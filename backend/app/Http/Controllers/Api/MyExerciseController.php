<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\ExerciseProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MyExerciseController extends Controller
{
    /** Exercices qui s'adressent au fidele connecte (ouverts d'abord), avec son avancement. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $exercises = CalendarService::exercisesFor($user)->latest()->limit(100)->get();
        $ids = $exercises->pluck('id');
        $responses = ExerciseResponse::where('user_id', $user->id)->whereIn('exercise_id', $ids)->get()->keyBy('exercise_id');
        $views = ExerciseVideoView::where('user_id', $user->id)->whereIn('exercise_id', $ids)->get()->keyBy('exercise_id');

        $items = $exercises->map(fn (Exercise $e) => $this->present($e, $views->get($e->id), $responses->get($e->id)))
            ->sortBy(fn ($x) => [$x['is_closed'] ? 1 : 0, $x['status'] === 'done' ? 1 : 0, $x['closes_at'] ?? '9999'])
            ->values();

        return response()->json(['exercises' => $items]);
    }

    public function show(Request $request, Exercise $exercise): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTarget($exercise, $user);
        $view = ExerciseVideoView::where('exercise_id', $exercise->id)->where('user_id', $user->id)->first();
        $response = ExerciseResponse::where('exercise_id', $exercise->id)->where('user_id', $user->id)->first();

        return response()->json(['exercise' => $this->present($exercise, $view, $response) + [
            // Reprise de la lecture la ou le fidele s'etait arrete.
            'resume_at' => $view && ! $view->completed_at ? $view->last_position : 0,
        ]]);
    }

    /** Repondre a un exercice (ou mettre a jour sa reponse) tant qu'il est ouvert. */
    public function respond(Request $request, Exercise $exercise): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTarget($exercise, $user);
        $this->ensureOpen($exercise);
        abort_unless($exercise->needsResponse(), 422, 'Cet exercice ne demande pas de réponse écrite.');

        $data = $request->validate(['response' => ['required', 'string', 'max:5000']]);

        ExerciseResponse::updateOrCreate(
            ['exercise_id' => $exercise->id, 'user_id' => $user->id],
            ['response' => $data['response'], 'completed_at' => Carbon::now()],
        );

        $view = ExerciseVideoView::where('exercise_id', $exercise->id)->where('user_id', $user->id)->first();
        $response = ExerciseResponse::where('exercise_id', $exercise->id)->where('user_id', $user->id)->first();
        $done = ExerciseProgress::isDone($exercise, $view, $response);

        return response()->json([
            'message' => $done ? 'Réponse enregistrée. Exercice terminé, merci !' : 'Réponse enregistrée. Il vous reste à regarder la vidéo en entier.',
            'status' => ExerciseProgress::status($exercise, $view, $response),
        ]);
    }

    /** Signal du lecteur video : passages regardes, position, avances rapides. */
    public function progress(Request $request, Exercise $exercise): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTarget($exercise, $user);
        abort_unless($exercise->isVideo(), 404);
        $this->ensureOpen($exercise);

        $data = $request->validate([
            'segments' => ['nullable', 'array', 'max:500'],
            'segments.*' => ['array', 'size:2'],
            'segments.*.*' => ['numeric', 'min:0', 'max:43200'],
            'position' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'seeks' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'skipped' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'rate' => ['nullable', 'numeric', 'min:0.1', 'max:16'],
        ]);

        $view = ExerciseProgress::record($exercise, $user, $data);
        $exercise->refresh();
        $response = ExerciseResponse::where('exercise_id', $exercise->id)->where('user_id', $user->id)->first();

        return response()->json([
            'percent' => ExerciseProgress::percent($exercise, $view),
            'video_completed' => (bool) $view->completed_at,
            'status' => ExerciseProgress::status($exercise, $view, $response),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Exercise $e, ?ExerciseVideoView $view, ?ExerciseResponse $response): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'content' => $e->content,
            'type' => $e->type,
            'video' => $e->videoPayload(),
            'requires_response' => $e->needsResponse(),
            'due_date' => $e->due_date?->toDateString(),
            'closes_at' => $e->closes_at?->toIso8601String(),
            'is_closed' => $e->isClosed(),
            'status' => ExerciseProgress::status($e, $view, $response),
            'percent' => ExerciseProgress::percent($e, $view),
            'video_completed' => (bool) $view?->completed_at,
            'seek_count' => $view?->seek_count ?? 0,
            'my_response' => $response?->response,
            'completed' => ExerciseProgress::isDone($e, $view, $response),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    private function authorizeTarget(Exercise $exercise, User $user): void
    {
        abort_unless(CalendarService::exercisesFor($user)->whereKey($exercise->id)->exists(), 403, 'Cet exercice ne vous est pas adressé.');
    }

    private function ensureOpen(Exercise $exercise): void
    {
        abort_if($exercise->isClosed(), 422, 'Cet exercice est fermé depuis le '
            .$exercise->closes_at->locale('fr')->isoFormat('D MMMM [à] H[h]mm').'.');
    }
}
