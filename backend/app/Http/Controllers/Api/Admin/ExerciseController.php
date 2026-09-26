<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExerciseController extends Controller
{
    public const TYPES = ['verset' => 'Verset à méditer', 'quiz' => 'Quiz', 'reflexion' => 'Réflexion', 'lecture' => 'Lecture'];

    /** Exercices que l'utilisateur peut gerer (les siens ou ceux de sa portee), avec le nombre de reponses. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $exercises = Exercise::withCount('responses')->with('creator.profile', 'scopes')->latest()->limit(200)->get()
            ->filter(fn (Exercise $e) => Audience::canManage($user, $e->created_by, $e->audienceList()))
            ->map(fn (Exercise $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'type' => $e->type,
                'type_label' => self::TYPES[$e->type] ?? $e->type,
                'target' => $e->audienceLabel(),
                'scopes' => $e->audienceList(),
                'due_date' => $e->due_date?->toDateString(),
                'responses_count' => $e->responses_count,
                'created_at' => $e->created_at->toDateString(),
            ])->values();

        return response()->json(['exercises' => $exercises, 'types' => $this->typeList()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $author = $request->user();
        $scopes = Audience::resolve($author, Audience::fromRequest($request));

        $exercise = DB::transaction(function () use ($data, $author, $scopes) {
            $exercise = Exercise::create($data + ['created_by' => $author->id]);
            $exercise->syncScopes($scopes);
            Audit::log('exercise.created', $exercise, null, [], ['title' => $exercise->title, 'scopes' => $scopes]);

            return $exercise;
        });

        // Nouvelle tache : chaque fidele concerne est prevenu (centre + push).
        $audience = array_diff(Audience::userIds($scopes), [$author->id]);
        $due = $exercise->due_date ? ' · à rendre le '.$exercise->due_date->locale('fr')->isoFormat('dddd D MMMM') : '';
        Notifier::send($audience, 'task', 'Nouvel exercice : '.$exercise->title,
            (self::TYPES[$exercise->type] ?? 'Exercice').$due, '/tableau-de-bord#exercices', ['exercise_id' => $exercise->id]);

        return response()->json(['message' => 'Exercice créé ('.count($audience).' fidèle(s) prévenu(s)).']);
    }

    public function destroy(Request $request, Exercise $exercise): JsonResponse
    {
        $this->authorizeManage($request, $exercise);
        Audit::log('exercise.deleted', $exercise, null, ['title' => $exercise->title, 'scopes' => $exercise->audienceList()]);
        $exercise->scopes()->delete();
        $exercise->delete();

        return response()->json(['message' => 'Exercice supprimé.']);
    }

    /** Reponses des fideles a un exercice. */
    public function responses(Request $request, Exercise $exercise): JsonResponse
    {
        $this->authorizeManage($request, $exercise);
        $responses = $exercise->responses()->with('user.profile')->latest('completed_at')->get()
            ->map(fn ($r) => [
                'user_id' => $r->user_id,
                'name' => $r->user?->profile?->full_name ?: $r->user?->phone,
                'response' => $r->response,
                'completed_at' => $r->completed_at?->toDateString(),
            ]);

        return response()->json([
            'exercise' => ['title' => $exercise->title, 'content' => $exercise->content],
            'responses' => $responses,
        ]);
    }

    private function authorizeManage(Request $request, Exercise $exercise): void
    {
        abort_unless(Audience::canManage($request->user(), $exercise->created_by, $exercise->audienceList()), 403, 'Cet exercice est hors de votre périmètre.');
    }

    private function typeList(): array
    {
        return collect(self::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
