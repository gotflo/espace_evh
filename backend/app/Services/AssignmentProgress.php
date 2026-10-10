<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Avancement d'un devoir (DS), memes regles que les exercices video :
 * - le devoir est « fait » quand la video ou l'audio (si la lecture est mesurable dans
 *   l'application) a ete lu en entier ET que le resume et les actions ont ete envoyes ;
 * - la lecture est reconstruite a partir des passages reellement lus (ExerciseProgress::apply) :
 *   avancer ne compte pas, et le serveur refuse plus de lecture que le temps ecoule ne le permet.
 * Un devoir publie par simple lien externe ne se mesure pas : le resume suffit.
 */
class AssignmentProgress
{
    public static function isDone(Assignment $a, ?AssignmentView $view, ?AssignmentResponse $response): bool
    {
        return $response !== null && (! $a->isTrackable() || ($view && $view->completed_at));
    }

    /** todo | in_progress | done */
    public static function status(Assignment $a, ?AssignmentView $view, ?AssignmentResponse $response): string
    {
        if (self::isDone($a, $view, $response)) {
            return 'done';
        }

        return ($view && ($view->watched_seconds > 0 || $view->completed_at)) || $response ? 'in_progress' : 'todo';
    }

    /** Pourcentage lu (0-100) ; null si la lecture ne se mesure pas (lien externe). */
    public static function percent(Assignment $a, ?AssignmentView $view): ?int
    {
        if (! $a->isTrackable()) {
            return null;
        }
        if (! $view) {
            return 0;
        }
        if ($view->completed_at) {
            return 100;
        }
        $duration = (int) $a->media_duration;

        return $duration > 0 ? (int) min(99, floor($view->watched_seconds / $duration * 100)) : 0;
    }

    /** Membres ayant fait le devoir (resume envoye et lecture terminee si mesurable). @return array<int> */
    public static function doneUserIds(Assignment $a): array
    {
        $responded = AssignmentResponse::where('assignment_id', $a->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if (! $a->isTrackable()) {
            return $responded;
        }
        $completed = AssignmentView::where('assignment_id', $a->id)->whereNotNull('completed_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($responded, $completed));
    }

    /** Signal de lecture envoye par le lecteur (video ou audio). */
    public static function record(Assignment $a, User $user, array $data): AssignmentView
    {
        // Duree : fixee par le premier lecteur qui la connait (bornee a 12 h).
        $reported = (int) round((float) ($data['duration'] ?? 0));
        if (! $a->media_duration && $reported >= 1 && $reported <= 43200) {
            Assignment::whereKey($a->id)->whereNull('media_duration')->update(['media_duration' => $reported]);
            $a->media_duration = $reported;
        }
        $duration = (int) ($a->media_duration ?: $reported);

        return DB::transaction(fn () => ExerciseProgress::apply(self::lockedView($a, $user), $duration, $data));
    }

    private static function lockedView(Assignment $a, User $user): AssignmentView
    {
        $query = fn () => AssignmentView::where('assignment_id', $a->id)->where('user_id', $user->id)->lockForUpdate()->first();
        $view = $query();
        if ($view) {
            return $view;
        }
        try {
            AssignmentView::create(['assignment_id' => $a->id, 'user_id' => $user->id, 'started_at' => now(), 'segments' => []]);
        } catch (UniqueConstraintViolationException) {
            // Deux signaux simultanes : l'autre vient de creer la ligne.
        }

        return $query();
    }

    /** Suivi detaille (responsables). @return array<string, mixed> */
    public static function tracking(Assignment $a, ?AssignmentView $view): array
    {
        return [
            'percent' => self::percent($a, $view),
            'media_completed' => (bool) $view?->completed_at,
            'seek_count' => $view?->seek_count ?? 0,
            'skipped_seconds' => $view?->skipped_seconds ?? 0,
            'max_rate' => $view ? (float) $view->max_rate : null,
        ];
    }
}
