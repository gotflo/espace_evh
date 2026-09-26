<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Services\CalendarService;
use App\Services\ExerciseProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Nouveautes depuis la derniere visite d'un membre (« Content de vous revoir ») :
 * annonces recues, evenements a venir publies entre-temps, exercices ouverts non faits.
 * Uniquement ce qui lui est adresse ; rien qui culpabilise.
 */
class MyWelcomeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['required', 'date']]);
        $user = $request->user();
        // Au plus 90 jours en arriere (au-dela, tout est « nouveau »).
        $since = Carbon::parse($data['since'])->max(now()->subDays(90));

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

        return response()->json([
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
            'unread' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }
}
