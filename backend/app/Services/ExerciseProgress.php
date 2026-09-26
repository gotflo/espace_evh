<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Avancement des exercices :
 * - un exercice est « fait » quand la video (s'il y en a une) a ete regardee en entier ET que
 *   la reponse ecrite (si elle est demandee) a ete envoyee ;
 * - le visionnage est reconstruit a partir des passages reellement lus : avancer la video ne
 *   compte pas comme regarde, et le serveur refuse plus de lecture que le temps ecoule ne le permet.
 */
class ExerciseProgress
{
    /** Part de la video a avoir regardee pour qu'elle compte comme vue en entier. */
    public const COMPLETION_RATIO = 0.95;

    /** Vitesse de lecture maximale admise (2x sur YouTube) + marge. */
    private const MAX_SPEED = 2.1;

    /** Credit de lecture accorde a un premier signal sans « depart » prealable (secondes). */
    private const FIRST_CREDIT = 20;

    public static function isDone(Exercise $exercise, ?ExerciseVideoView $view, ?ExerciseResponse $response): bool
    {
        $videoOk = ! $exercise->isVideo() || ($view && $view->completed_at);
        $responseOk = ! $exercise->needsResponse() || $response !== null;

        return $videoOk && $responseOk;
    }

    /** todo | in_progress | done (independant de la fermeture). */
    public static function status(Exercise $exercise, ?ExerciseVideoView $view, ?ExerciseResponse $response): string
    {
        if (self::isDone($exercise, $view, $response)) {
            return 'done';
        }

        return ($view && ($view->watched_seconds > 0 || $view->completed_at)) || $response ? 'in_progress' : 'todo';
    }

    /**
     * Fideles ayant termine l'exercice.
     *
     * @return array<int>
     */
    public static function doneUserIds(Exercise $exercise): array
    {
        $responded = $exercise->needsResponse()
            ? ExerciseResponse::where('exercise_id', $exercise->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : null;
        $watched = $exercise->isVideo()
            ? ExerciseVideoView::where('exercise_id', $exercise->id)->whereNotNull('completed_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : null;

        if ($responded !== null && $watched !== null) {
            return array_values(array_intersect($responded, $watched));
        }

        return $responded ?? $watched ?? [];
    }

    /** Pourcentage regarde (0-100). */
    public static function percent(Exercise $exercise, ?ExerciseVideoView $view): int
    {
        if (! $view) {
            return 0;
        }
        if ($view->completed_at) {
            return 100;
        }
        $duration = (int) $exercise->video_duration;

        return $duration > 0 ? (int) min(99, floor($view->watched_seconds / $duration * 100)) : 0;
    }

    /**
     * Enregistre un signal de lecture envoye par le lecteur.
     *
     * @param  array{segments?: array<int, array<int, float|int>>, position?: float|int, duration?: float|int, seeks?: int, skipped?: float|int, rate?: float|int}  $data
     */
    public static function record(Exercise $exercise, User $user, array $data): ExerciseVideoView
    {
        // Duree : fixee par le premier lecteur qui la connait (bornee a 12 h).
        $reported = (int) round((float) ($data['duration'] ?? 0));
        if (! $exercise->video_duration && $reported >= 1 && $reported <= 43200) {
            Exercise::whereKey($exercise->id)->whereNull('video_duration')->update(['video_duration' => $reported]);
            $exercise->video_duration = $reported;
        }
        $duration = (int) ($exercise->video_duration ?: $reported);

        return DB::transaction(function () use ($exercise, $user, $data, $duration) {
            $view = self::lockedView($exercise, $user);
            $now = now();

            $old = self::normalize($view->segments ?? [], $duration);
            $incoming = self::normalize($data['segments'] ?? [], $duration);
            $merged = self::merge(array_merge($old, $incoming));
            $gain = self::covered($merged) - self::covered($old);

            // Plus de lecture que le temps reellement ecoule (x2 max) : signal ignore.
            $elapsed = $view->last_heartbeat_at ? $view->last_heartbeat_at->diffInSeconds($now, true) : self::FIRST_CREDIT / self::MAX_SPEED;
            $plausible = $gain <= $elapsed * self::MAX_SPEED + 5;

            if ($plausible) {
                $view->segments = $merged;
                $view->watched_seconds = (int) round(self::covered($merged));
                $view->seek_count = min(65000, $view->seek_count + max(0, min(50, (int) ($data['seeks'] ?? 0))));
                $view->skipped_seconds = $view->skipped_seconds + (int) round(max(0, min((float) ($data['skipped'] ?? 0), $duration ?: 43200)));
                $view->max_rate = max((float) $view->max_rate, max(0.25, min(4.0, (float) ($data['rate'] ?? 1))));
            }
            $position = (int) round(max(0, min((float) ($data['position'] ?? 0), $duration ?: 43200)));
            $view->last_position = $position;
            if ($plausible) {
                $view->max_position = max($view->max_position, $position);
            }

            // Controle independant du navigateur : tout passage non regarde avant le point le
            // plus loin atteint a forcement ete saute (avance rapide).
            $reached = max($view->max_position, (int) floor(max(array_merge([0], array_column($view->segments ?? [], 1)))));
            $gap = (int) round($reached - self::covered(self::clip($view->segments ?? [], $reached)));
            if ($gap > 3) {
                $view->skipped_seconds = max($view->skipped_seconds, $gap);
                $view->seek_count = max($view->seek_count, 1);
            }
            $view->last_heartbeat_at = $now;

            if (! $view->completed_at && $duration > 0
                && ($view->watched_seconds >= $duration * self::COMPLETION_RATIO || $duration - $view->watched_seconds <= 5)) {
                $view->completed_at = $now;
            }
            $view->save();

            return $view;
        });
    }

    private static function lockedView(Exercise $exercise, User $user): ExerciseVideoView
    {
        $query = fn () => ExerciseVideoView::where('exercise_id', $exercise->id)->where('user_id', $user->id)->lockForUpdate()->first();
        $view = $query();
        if ($view) {
            return $view;
        }
        try {
            ExerciseVideoView::create(['exercise_id' => $exercise->id, 'user_id' => $user->id, 'started_at' => now(), 'segments' => []]);
        } catch (UniqueConstraintViolationException) {
            // Deux signaux simultanes : l'autre vient de creer la ligne.
        }

        return $query();
    }

    /**
     * Passages valides, bornes a la duree, arrondis au dixieme.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private static function normalize(mixed $segments, int $duration): array
    {
        if (! is_array($segments)) {
            return [];
        }
        $max = $duration > 0 ? $duration : 43200;
        $out = [];
        foreach (array_slice($segments, 0, 500) as $s) {
            if (! is_array($s) || count($s) < 2 || ! is_numeric($s[0] ?? null) || ! is_numeric($s[1] ?? null)) {
                continue;
            }
            $a = round(max(0, min((float) $s[0], $max)), 1);
            $b = round(max(0, min((float) $s[1], $max)), 1);
            if ($b > $a) {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * Fusionne les passages qui se chevauchent (ou se touchent a 1 s pres).
     *
     * @param  array<int, array{0: float, 1: float}>  $segments
     * @return array<int, array{0: float, 1: float}>
     */
    public static function merge(array $segments): array
    {
        usort($segments, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($segments as [$a, $b]) {
            $last = count($out) - 1;
            if ($last >= 0 && $a <= $out[$last][1] + 1) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * Passages limites a [0, $limit].
     *
     * @param  array<int, array{0: float, 1: float}>  $segments
     * @return array<int, array{0: float, 1: float}>
     */
    private static function clip(array $segments, float $limit): array
    {
        $out = [];
        foreach ($segments as [$a, $b]) {
            if ($a < $limit) {
                $out[] = [$a, min($b, $limit)];
            }
        }

        return $out;
    }

    /** @param array<int, array{0: float, 1: float}> $segments */
    public static function covered(array $segments): float
    {
        return array_sum(array_map(fn ($s) => $s[1] - $s[0], $segments));
    }
}
