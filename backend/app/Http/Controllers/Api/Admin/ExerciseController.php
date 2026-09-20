<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Exercise;
use App\Models\Tribe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public const TYPES = ['verset' => 'Verset à méditer', 'quiz' => 'Quiz', 'reflexion' => 'Reflexion', 'lecture' => 'Lecture'];

    /** Liste des exercices, avec le nombre de reponses. */
    public function index(): JsonResponse
    {
        $exercises = Exercise::withCount('responses')->with('creator.profile')->latest()->get()
            ->map(fn (Exercise $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'type' => $e->type,
                'type_label' => self::TYPES[$e->type] ?? $e->type,
                'target' => $this->targetLabel($e),
                'due_date' => $e->due_date?->toDateString(),
                'responses_count' => $e->responses_count,
                'created_at' => $e->created_at->toDateString(),
            ]);

        return response()->json(['exercises' => $exercises, 'types' => $this->typeList()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'target_type' => ['required', 'in:all,tribe,department,gem'],
            'target_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        // Un responsable restreint ne peut cibler que sa propre portee (GEM / tribu / dept).
        [$targetType, $targetId] = \App\Support\MemberScope::isScoped($request->user())
            ? \App\Support\MemberScope::primaryScope($request->user())
            : [$data['target_type'], $data['target_type'] === 'all' ? null : $data['target_id']];

        if ($targetType === 'tribe' && ! Tribe::whereKey($targetId)->exists()) {
            abort(422, 'Tribu invalide.');
        }
        if ($targetType === 'department' && ! Department::whereKey($targetId)->exists()) {
            abort(422, 'Département invalide.');
        }

        Exercise::create([
            'title' => $data['title'],
            'content' => $data['content'],
            'type' => $data['type'],
            'target_type' => $targetType,
            'target_id' => $targetType === 'all' ? null : $targetId,
            'due_date' => $data['due_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Exercice créé.']);
    }

    public function destroy(Exercise $exercise): JsonResponse
    {
        $exercise->delete();

        return response()->json(['message' => 'Exercice supprimé.']);
    }

    /** Reponses des fideles a un exercice. */
    public function responses(Exercise $exercise): JsonResponse
    {
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

    private function targetLabel(Exercise $e): string
    {
        return match ($e->target_type) {
            'tribe' => 'Tribu '.(Tribe::find($e->target_id)?->name ?? '?'),
            'department' => 'Dept. '.(Department::find($e->target_id)?->name ?? '?'),
            'gem' => 'GEM '.(\App\Models\Gem::find($e->target_id)?->name ?? '?'),
            default => "Toute l'église",
        };
    }

    private function typeList(): array
    {
        return collect(self::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
