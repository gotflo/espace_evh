<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\Notifier;
use App\Support\EvaluationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    /** Notes d'un fidele (pour un responsable). */
    public function index(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canViewMember($user), 403, 'Accès refusé.');

        $items = Evaluation::with('author.profile')
            ->where('user_id', $user->id)
            ->orderByDesc('evaluated_on')->orderByDesc('id')->get()
            ->map(fn (Evaluation $e) => $this->present($e));

        return response()->json([
            'evaluations' => $items,
            'average' => $this->average($user->id),
            'types' => EvaluationCatalog::options(),
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canManageMember($user), 403, 'Consultation seule : vous ne pouvez pas noter ce fidèle.');

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(EvaluationCatalog::TYPES))],
            'title' => ['nullable', 'string', 'max:150'],
            'score' => ['required', 'numeric', 'min:0', 'max:20'],
            'evaluated_on' => ['required', 'date', 'before_or_equal:today'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $evaluation = Evaluation::create($data + [
            'user_id' => $user->id,
            'max_score' => 20,
            'created_by' => $request->user()->id,
        ]);

        \App\Support\Audit::log('evaluation.created', $evaluation, $user->id, [], $evaluation->only(['type', 'title', 'score', 'evaluated_on']));
        Notifier::send([$user->id], 'evaluation',
            'Nouvelle note : '.(EvaluationCatalog::TYPES[$evaluation->type] ?? 'Évaluation'),
            ($evaluation->title ? $evaluation->title.' · ' : '').rtrim(rtrim(number_format((float) $evaluation->score, 1, ',', ''), '0'), ',').' / 20',
            '/ma-vie-spirituelle', ['evaluation_id' => $evaluation->id]);

        return response()->json(['message' => 'Note enregistrée.', 'average' => $this->average($user->id)], 201);
    }

    public function destroy(Request $request, Evaluation $evaluation): JsonResponse
    {
        abort_unless($request->user()->canManageMember($evaluation->member), 403, 'Consultation seule : action non autorisée.');
        $uid = $evaluation->user_id;
        \App\Support\Audit::log('evaluation.deleted', $evaluation, $evaluation->user_id, $evaluation->only(['type', 'title', 'score', 'evaluated_on']));
        $evaluation->delete();

        return response()->json(['message' => 'Note supprimée.', 'average' => $this->average($uid)]);
    }

    private function average(int $userId): ?float
    {
        $avg = Evaluation::where('user_id', $userId)->avg('score');

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    /** @return array<string, mixed> */
    private function present(Evaluation $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => EvaluationCatalog::TYPES[$e->type] ?? $e->type,
            'title' => $e->title,
            'score' => $e->score,
            'max_score' => $e->max_score,
            'stars' => round($e->score / max($e->max_score, 1) * 5, 1),
            'evaluated_on' => $e->evaluated_on?->toDateString(),
            'comment' => $e->comment,
            'author' => $e->author?->profile?->full_name ?: null,
        ];
    }
}
