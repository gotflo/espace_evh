<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MyExerciseController extends Controller
{
    /** Exercices qui s'adressent au fidele connecte + sa reponse eventuelle. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->load('profile.departments');
        $tribeId = $user->profile?->tribe_id;
        $gemId = $user->profile?->gem_id;
        $deptIds = $user->profile ? $user->profile->departments->pluck('id')->all() : [];

        $exercises = Exercise::where('is_active', true)
            ->where(function ($q) use ($tribeId, $gemId, $deptIds) {
                $q->where('target_type', 'all')
                    ->orWhere(fn ($x) => $x->where('target_type', 'tribe')->where('target_id', $tribeId))
                    ->orWhere(fn ($x) => $x->where('target_type', 'gem')->where('target_id', $gemId))
                    ->orWhere(fn ($x) => $x->where('target_type', 'department')->whereIn('target_id', $deptIds ?: [0]));
            })
            ->latest()->get();

        $myResponses = ExerciseResponse::where('user_id', $user->id)
            ->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');

        return response()->json([
            'exercises' => $exercises->map(fn (Exercise $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'content' => $e->content,
                'type' => $e->type,
                'due_date' => $e->due_date?->toDateString(),
                'my_response' => $myResponses->get($e->id)?->response,
                'completed' => $myResponses->has($e->id),
            ])->values(),
        ]);
    }

    /** Repondre a un exercice (ou mettre a jour sa reponse). */
    public function respond(Request $request, Exercise $exercise): JsonResponse
    {
        $user = $request->user()->load('profile.departments');
        if (! $this->targetsUser($exercise, $user)) {
            abort(403, 'Cet exercice ne vous est pas adresse.');
        }

        $data = $request->validate(['response' => ['required', 'string', 'max:5000']]);

        ExerciseResponse::updateOrCreate(
            ['exercise_id' => $exercise->id, 'user_id' => $user->id],
            ['response' => $data['response'], 'completed_at' => Carbon::now()],
        );

        return response()->json(['message' => 'Reponse enregistree.']);
    }

    private function targetsUser(Exercise $exercise, $user): bool
    {
        if (! $exercise->is_active) {
            return false;
        }

        return match ($exercise->target_type) {
            'tribe' => $exercise->target_id === $user->profile?->tribe_id,
            'gem' => $exercise->target_id === $user->profile?->gem_id,
            'department' => $user->profile
                ? $user->profile->departments->pluck('id')->contains($exercise->target_id)
                : false,
            default => true,
        };
    }
}
