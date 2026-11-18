<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\AssignmentController;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Services\ActivityService;
use App\Services\AssignmentFeedback;
use App\Services\AssignmentProgress;
use App\Support\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Mon devoir (DS) : le membre regarde ou ecoute, puis ecrit son resume et ses actions et resolutions. */
class MyAssignmentController extends Controller
{
    public const SUMMARY_MAX = 5000;

    public const ACTIONS_MAX = 3000;

    public const PRAYER_REMINDER = 'Après le devoir, prenez un moment pour prier et vous recommander à Dieu, source de force par sa grâce.';

    /** Le devoir le plus recent en premier, puis les precedents (fait ou a faire). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $assignments = Assignment::published()->orderByDesc('published_at')->orderByDesc('id')->limit(30)->get();
        $mine = AssignmentResponse::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');
        $views = AssignmentView::where('user_id', $user->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');
        // Retours du pasteur sur ses reponses (« j'aime », commentaires) : visibles du membre lui-meme.
        $feedback = AssignmentFeedback::forResponses($mine->values(), $user);

        return response()->json([
            'assignments' => $assignments->map(fn (Assignment $a) => AssignmentController::presentFor($a)
                + $this->progressOf($a, $views->get($a->id), $mine->get($a->id)) + [
                    'response' => isset($mine[$a->id]) ? $this->presentResponse($mine[$a->id], $feedback[$mine[$a->id]->id] ?? null) : null,
                ])->values(),
            // Devoirs a faire : pas encore faits, sauf ceux que l'on a publies soi-meme (l'auteur ne fait pas son devoir).
            'pending' => $assignments->reject(fn ($a) => (int) $a->created_by === (int) $user->id
                || AssignmentProgress::isDone($a, $views->get($a->id), $mine->get($a->id)))->count(),
            'prayer_reminder' => self::PRAYER_REMINDER,
        ]);
    }

    /** Pastille du menu « Mon devoir (DS) » : nombre de devoirs a faire (meme regle que la page). */
    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $assignments = Assignment::published()->orderByDesc('published_at')->orderByDesc('id')->limit(30)
            ->get(['id', 'media_type', 'media_url', 'youtube_id', 'audio_path', 'created_by', 'published_at']);
        $ids = $assignments->pluck('id');
        $mine = AssignmentResponse::where('user_id', $user->id)->whereIn('assignment_id', $ids)->get()->keyBy('assignment_id');
        $views = AssignmentView::where('user_id', $user->id)->whereIn('assignment_id', $ids)->get()->keyBy('assignment_id');

        return response()->json(['pending' => $assignments->reject(fn ($a) => (int) $a->created_by === (int) $user->id
            || AssignmentProgress::isDone($a, $views->get($a->id), $mine->get($a->id)))->count()]);
    }

    /** Resume et actions et resolutions (modifiables ensuite). */
    public function respond(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->published_at && $assignment->published_at->lte(now()), 404);
        // Texte enrichi (document de l'editeur, reconstruit par RichText) ou texte simple (anciennes versions de l'application).
        $request->validate([
            'summary_doc' => ['nullable', 'array'],
            'actions_doc' => ['nullable', 'array'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'actions' => ['nullable', 'string', 'max:20000'],
        ]);
        [$summaryDoc, $summary] = $this->field($request, 'summary', self::SUMMARY_MAX);
        [$actionsDoc, $actions] = $this->field($request, 'actions', self::ACTIONS_MAX);
        $errors = array_filter([
            'summary' => mb_strlen($summary) < 10 ? ($summary === '' ? 'Écrivez votre résumé.' : 'Votre résumé est un peu court : quelques phrases suffisent.') : null,
            'actions' => mb_strlen($actions) < 5 ? 'Écrivez au moins une action ou une résolution.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $response = AssignmentResponse::updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $request->user()->id],
            ['summary' => $summary, 'summary_doc' => $summaryDoc, 'actions' => $actions, 'actions_doc' => $actionsDoc, 'submitted_at' => now()],
        );
        ActivityService::touch($request->user(), true, 'assignment');
        $view = AssignmentView::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->first();
        $done = AssignmentProgress::isDone($assignment, $view, $response);

        return response()->json([
            'message' => ! $done ? 'Résumé enregistré. Terminez la '.($assignment->media_type === 'audio' ? 'écoute' : 'vidéo').' pour que le devoir soit fait.'
                : ($response->wasRecentlyCreated ? 'Devoir enregistré. Prenons le temps de prier.' : 'Devoir mis à jour.'),
            'response' => $this->presentResponse($response, AssignmentFeedback::forResponses(collect([$response]), $request->user())[$response->id] ?? null),
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

    /**
     * Document de l'editeur nettoye (ou null pour un texte simple) et son texte brut.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function field(Request $request, string $name, int $max): array
    {
        if (is_array($request->input($name.'_doc'))) {
            return RichText::clean($request->input($name.'_doc'), $max, $name);
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $request->input($name, '')));
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$name => "Ce texte est trop long : {$max} caractères au plus."]);
        }

        return [null, $text];
    }

    /**
     * @param  array<string, mixed>|null  $feedback  « j'aime » et commentaires du pasteur
     * @return array<string, mixed>
     */
    private function presentResponse(AssignmentResponse $r, ?array $feedback = null): array
    {
        return [
            'summary' => $r->summary,
            'summary_doc' => $r->summary_doc,
            'actions' => $r->actions,
            'actions_doc' => $r->actions_doc,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'feedback' => ['likes' => $feedback['likes'] ?? [], 'comments' => $feedback['comments'] ?? []],
        ];
    }
}
