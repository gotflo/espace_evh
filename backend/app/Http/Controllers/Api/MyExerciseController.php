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
        $user = $request->user();
        $exercises = \App\Services\CalendarService::exercisesFor($user)->latest()->get();

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
        $user = $request->user();
        if (! $this->targetsUser($exercise, $user)) {
            abort(403, 'Cet exercice ne vous est pas adresse.');
        }

        $data = $request->validate(['response' => ['required', 'string', 'max:5000']]);

        ExerciseResponse::updateOrCreate(
            ['exercise_id' => $exercise->id, 'user_id' => $user->id],
            ['response' => $data['response'], 'completed_at' => Carbon::now()],
        );

        return response()->json(['message' => 'Réponse enregistrée.']);
    }

    /** L'exercice s'adresse-t-il a ce fidele (sa tribu, son GEM, ses departements, toute l'eglise) ? */
    private function targetsUser(Exercise $exercise, $user): bool
    {
        return \App\Services\CalendarService::exercisesFor($user)->whereKey($exercise->id)->exists();
    }
}
