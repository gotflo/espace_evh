<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Nouveautes depuis une date pour un membre : annonces recues, evenements a venir publies entre-temps,
 * exercices et devoirs ouverts non faits, notifications non lues. Uniquement ce qui lui est adresse ;
 * rien qui culpabilise. Sert a l'accueil « Content de vous revoir » et au courriel d'absence.
 */
class WelcomeBackService
{
    /** @return array<string, mixed> */
    public static function for(User $user, Carbon $since): array
    {
        $announcements = $user->receivedAnnouncements()->where('announcements.created_at', '>=', $since)
            ->orderByDesc('announcements.created_at')->limit(3)->get(['announcements.id', 'announcements.title', 'announcements.body']);
        $announcementCount = $user->receivedAnnouncements()->where('announcements.created_at', '>=', $since)->count();

        $events = CalendarService::occurrences(
            CalendarService::eventsQuery($user)->where('remind_all', false)->where('is_personal', false)->where('created_at', '>=', $since),
            now(), now()->addDays(60),
        )->unique(fn ($o) => $o['event']->id)->values();

        $exercises = CalendarService::exercisesFor($user)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))->get();
        $views = ExerciseVideoView::where('user_id', $user->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');
        $responses = ExerciseResponse::where('user_id', $user->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');
        $todo = $exercises->reject(fn (Exercise $e) => ExerciseProgress::isDone($e, $views->get($e->id), $responses->get($e->id)))->values();

        // Devoirs (DS) publies depuis la derniere visite et pas encore faits.
        $assignments = Assignment::published()->where('published_at', '>=', $since)->orderByDesc('published_at')->limit(20)->get();
        $dsViews = AssignmentView::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');
        $dsResponses = AssignmentResponse::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');
        $dsTodo = $assignments->reject(fn (Assignment $a) => AssignmentProgress::isDone($a, $dsViews->get($a->id), $dsResponses->get($a->id)))->values();

        return [
            'since' => $since->toIso8601String(),
            'announcements' => [
                'count' => $announcementCount,
                'items' => $announcements->map(fn ($a) => ['id' => $a->id, 'title' => $a->title ?: mb_strimwidth((string) $a->body, 0, 70, '…')])->values(),
            ],
            'events' => [
                'count' => $events->count(),
                'items' => $events->take(3)->map(fn ($o) => ['id' => $o['event']->id, 'title' => $o['event']->title, 'date' => $o['start']->toDateString()])->values(),
            ],
            'exercises' => [
                'count' => $todo->count(),
                'items' => $todo->take(3)->map(fn (Exercise $e) => ['id' => $e->id, 'title' => $e->title])->values(),
            ],
            'assignments' => [
                'count' => $dsTodo->count(),
                'items' => $dsTodo->take(3)->map(fn (Assignment $a) => ['id' => $a->id, 'title' => $a->title])->values(),
            ],
            'unread' => $user->notifications()->whereNull('read_at')->count(),
        ];
    }
}
