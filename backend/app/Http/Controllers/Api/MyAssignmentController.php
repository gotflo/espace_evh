<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\AssignmentController;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Services\ActivityService;
use App\Services\AssignmentProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mon devoir (DS) : le membre regarde ou ecoute, puis ecrit son resume et ses actions et resolutions. */
class MyAssignmentController extends Controller
{
    public const PRAYER_REMINDER = 'Après le devoir, prenez un moment pour prier et vous recommander à Dieu, source de force par sa grâce.';

    /** Le devoir le plus recent en premier, puis les precedents (fait ou a faire). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $assignments = Assignment::published()->orderByDesc('published_at')->orderByDesc('id')->limit(30)->get();
        $mine = AssignmentResponse::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');
        $views = AssignmentView::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');

        return response()->json([
            'assignments' => $assignments->map(fn (Assignment $a) => AssignmentController::presentFor($a)
                + $this->progressOf($a, $views->get($a->id), $mine->get($a->id)) + [
                    'response' => isset($mine[$a->id]) ? $this->presentResponse($mine[$a->id]) : null,
                ])->values(),
            'pending' => $assignments->reject(fn ($a) => AssignmentProgress::isDone($a, $views->get($a->id), $mine->get($a->id)))->count(),
            'prayer_reminder' => self::PRAYER_REMINDER,
        ]);
    }

    /** Resume et actions et resolutions (modifiables ensuite). */
    public function respond(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->published_at && $assignment->published_at->lte(now()), 404);
        $data = $request->validate([
            'summary' => ['required', 'string', 'min:10', 'max:5000'],
            'actions' => ['required', 'string', 'min:5', 'max:3000'],
        ], [
            'summary.min' => 'Votre résumé est un peu court : quelques phrases suffisent.',
            'actions.min' => 'Écrivez au moins une action ou une résolution.',
        ]);

        $response = AssignmentResponse::updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $request->user()->id],
            $data + ['submitted_at' => now()],
        );
        ActivityService::touch($request->user(), true, 'assignment');
        $view = AssignmentView::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->first();
        $done = AssignmentProgress::isDone($assignment, $view, $response);

        return response()->json([
            'message' => ! $done ? 'Résumé enregistré. Terminez la '.($assignment->media_type === 'audio' ? 'écoute' : 'vidéo').' pour que le devoir soit fait.'
                : ($response->wasRecentlyCreated ? 'Devoir enregistré. Prenons le temps de prier.' : 'Devoir mis à jour.'),
            'response' => $this->presentResponse($response),
        ] + $this->progressOf($assignment, $view, $response));
    }

    /** Signal de lecture (video ou audio), meme fonctionnement que les exercices video. */
    public function progress(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->published_at && $assignment->published_at->lte(now()) && $assignment->isTrackable(), 404);
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
        $view = AssignmentProgress::record($assignment, $request->user(), $data);
        $assignment->refresh();
        $response = AssignmentResponse::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->first();

        return response()->json([
            'percent' => AssignmentProgress::percent($assignment, $view) ?? 0,
            'video_completed' => (bool) $view->completed_at,
            'status' => AssignmentProgress::status($assignment, $view, $response),
        ]);
    }

    /** @return array<string, mixed> */
    private function progressOf(Assignment $a, ?AssignmentView $view, ?AssignmentResponse $response): array
    {
        return [
            'trackable' => $a->isTrackable(),
            'percent' => AssignmentProgress::percent($a, $view),
            'media_completed' => (bool) $view?->completed_at,
            // Reprise la ou le membre s'etait arrete (sauf lecture deja terminee).
            'resume_at' => $view && ! $view->completed_at ? (int) $view->last_position : 0,
            'status' => AssignmentProgress::status($a, $view, $response),
            'done' => AssignmentProgress::isDone($a, $view, $response),
        ];
    }

    /** @return array<string, mixed> */
    private function presentResponse(AssignmentResponse $r): array
    {
        return [
            'summary' => $r->summary,
            'actions' => $r->actions,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
        ];
    }
}
