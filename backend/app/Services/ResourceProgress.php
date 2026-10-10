<?php

namespace App\Services;

use App\Models\ResolutionResource;
use App\Models\ResourceView;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des ressources video des RM : memes regles que les exercices video et les devoirs
 * (ExerciseProgress::apply : passages reellement lus, avances rapides non comptees, plausibilite par
 * rapport au temps ecoule, regardee a 95 %), et reprise la ou le membre s'etait arrete.
 */
class ResourceProgress
{
    public static function percent(ResolutionResource $r, ?ResourceView $view): int
    {
        if (! $view) {
            return 0;
        }
        if ($view->completed_at) {
            return 100;
        }
        $duration = (int) $r->media_duration;

        return $duration > 0 ? (int) min(99, floor($view->watched_seconds / $duration * 100)) : 0;
    }

    /** Etat de lecture pour le membre : pourcentage, regardee en entier, position de reprise. @return array<string, mixed> */
    public static function stateFor(ResolutionResource $r, ?ResourceView $view): array
    {
        return [
            'percent' => self::percent($r, $view),
            'completed' => (bool) $view?->completed_at,
            'resume_at' => $view && ! $view->completed_at ? (int) $view->last_position : 0,
        ];
    }

    /** Signal du lecteur. */
    public static function record(ResolutionResource $r, User $user, array $data): ResourceView
    {
        $reported = (int) round((float) ($data['duration'] ?? 0));
        if (! $r->media_duration && $reported >= 1 && $reported <= 43200) {
            ResolutionResource::whereKey($r->id)->whereNull('media_duration')->update(['media_duration' => $reported]);
            $r->media_duration = $reported;
        }
        $duration = (int) ($r->media_duration ?: $reported);

        return DB::transaction(fn () => ExerciseProgress::apply(self::lockedView($r, $user), $duration, $data));
    }

    private static function lockedView(ResolutionResource $r, User $user): ResourceView
    {
        $query = fn () => ResourceView::where('resolution_resource_id', $r->id)->where('user_id', $user->id)->lockForUpdate()->first();
        $view = $query();
        if ($view) {
            return $view;
        }
        try {
            ResourceView::create(['resolution_resource_id' => $r->id, 'user_id' => $user->id, 'started_at' => now(), 'segments' => []]);
        } catch (UniqueConstraintViolationException) {
            // Deux signaux simultanes : l'autre vient de creer la ligne.
        }

        return $query();
    }
}
