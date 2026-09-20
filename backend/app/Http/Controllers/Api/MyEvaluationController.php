<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Support\EvaluationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyEvaluationController extends Controller
{
    /** Notes du fidele connecte + moyenne + moyenne par type. */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $evals = Evaluation::where('user_id', $userId)
            ->orderByDesc('evaluated_on')->orderByDesc('id')->get();

        $items = $evals->map(fn (Evaluation $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => EvaluationCatalog::TYPES[$e->type] ?? $e->type,
            'title' => $e->title,
            'score' => $e->score,
            'max_score' => $e->max_score,
            'stars' => round($e->score / max($e->max_score, 1) * 5, 1),
            'evaluated_on' => $e->evaluated_on?->toDateString(),
            'comment' => $e->comment,
        ]);

        // Moyenne par type (pour un apercu par matiere).
        $byType = $evals->groupBy('type')->map(fn ($g, $type) => [
            'type' => $type,
            'type_label' => EvaluationCatalog::TYPES[$type] ?? $type,
            'average' => round((float) $g->avg('score'), 2),
            'count' => $g->count(),
        ])->values();

        return response()->json([
            'evaluations' => $items,
            'average' => $evals->count() ? round((float) $evals->avg('score'), 2) : null,
            'by_type' => $byType,
        ]);
    }
}
