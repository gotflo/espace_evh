<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Models\User;
use App\Services\ExerciseProgress;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Audit;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExerciseController extends Controller
{
    public const TYPES = [
        'video' => 'Vidéo à regarder',
        'verset' => 'Verset à méditer',
        'quiz' => 'Quiz',
        'reflexion' => 'Réflexion',
        'lecture' => 'Lecture',
    ];

    /** Exercices que l'utilisateur peut gerer (les siens ou ceux de sa portee), avec leur avancement. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $exercises = Exercise::withCount([
            'responses',
            'videoViews as views_count',
            'videoViews as views_completed_count' => fn ($q) => $q->whereNotNull('completed_at'),
        ])->with('scopes')->latest()->limit(200)->get()
            ->filter(fn (Exercise $e) => Audience::canManage($user, $e->created_by, $e->audienceList()))
            ->map(fn (Exercise $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'content' => $e->content,
                'type' => $e->type,
                'type_label' => self::TYPES[$e->type] ?? $e->type,
                'target' => $e->audienceLabel(),
                'scopes' => $e->audienceList(),
                'video' => $e->videoPayload(),
                'requires_response' => $e->needsResponse(),
                'due_date' => $e->due_date?->toDateString(),
                'closes_at' => $e->closes_at?->toIso8601String(),
                'is_closed' => $e->isClosed(),
                'responses_count' => $e->responses_count,
                'views_count' => $e->views_count,
                'views_completed_count' => $e->views_completed_count,
                'created_at' => $e->created_at->toDateString(),
            ])->values();

        return response()->json(['exercises' => $exercises, 'types' => $this->typeList()]);
    }

    /** Apercu d'un lien YouTube avant publication (identifiant, titre, lecture possible dans l'application). */
    public function videoPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:500']]);
        $id = YouTube::videoId($data['url']);
        if (! $id) {
            throw ValidationException::withMessages(['url' => 'Ce lien n\'est pas une vidéo YouTube (ex. https://www.youtube.com/watch?v=…).']);
        }
        $details = YouTube::details($id);

        return response()->json([
            'video_id' => $id,
            'thumbnail' => YouTube::thumbnail($id),
            'title' => $details['title'],
            'author' => $details['author'],
            'embeddable' => $details['embeddable'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'content' => ['nullable', 'string', 'max:5000', 'required_unless:type,video'],
            'type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'video_url' => ['nullable', 'string', 'max:500', 'required_if:type,video'],
            'video_duration' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'requires_response' => ['nullable', 'boolean'],
            'closes_at' => ['nullable', 'date', 'after:now'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'content.required_unless' => 'La consigne est obligatoire.',
            'video_url.required_if' => 'Collez le lien de la vidéo YouTube.',
            'closes_at.after' => 'La date limite doit être dans le futur.',
        ]);

        $videoId = null;
        if ($data['type'] === 'video') {
            $videoId = YouTube::videoId($data['video_url'] ?? '');
            if (! $videoId) {
                throw ValidationException::withMessages(['video_url' => 'Ce lien n\'est pas une vidéo YouTube valide.']);
            }
        }

        // Date limite : date et heure precises, ou ancienne echeance (fin de journee).
        $closesAt = ! empty($data['closes_at']) ? Carbon::parse($data['closes_at'])
            : (! empty($data['due_date']) ? Carbon::parse($data['due_date'])->setTime(23, 59) : null);

        $author = $request->user();
        $scopes = Audience::resolve($author, Audience::fromRequest($request));
        $attributes = [
            'title' => $data['title'],
            'content' => (string) ($data['content'] ?? ''),
            'type' => $data['type'],
            'video_id' => $videoId,
            'video_duration' => $videoId ? ($data['video_duration'] ?? null) : null,
            'requires_response' => $videoId ? (bool) ($data['requires_response'] ?? false) : true,
            'closes_at' => $closesAt,
            'due_date' => $closesAt?->toDateString(),
            'created_by' => $author->id,
        ];

        $exercise = DB::transaction(function () use ($attributes, $scopes) {
            $exercise = Exercise::create($attributes);
            $exercise->syncScopes($scopes);
            Audit::log('exercise.created', $exercise, null, [], [
                'title' => $exercise->title, 'type' => $exercise->type, 'video_id' => $exercise->video_id,
                'closes_at' => $exercise->closes_at?->toDateTimeString(), 'scopes' => $scopes,
            ]);

            return $exercise;
        });

        // Chaque fidele concerne est prevenu, avec l'exercice precis et la date limite.
        $audience = array_diff(Audience::userIds($scopes), [$author->id]);
        Notifier::send($audience, 'task', self::announceTitle($exercise), self::announceBody($exercise),
            '/exercices/'.$exercise->id, ['exercise_id' => $exercise->id]);

        return response()->json(['message' => 'Exercice publié ('.count($audience).' fidèle(s) prévenu(s)).', 'id' => $exercise->id], 201);
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

    /**
     * Suivi detaille : chaque fidele concerne, ce qu'il a regarde (et s'il a avance la video),
     * sa reponse, et s'il a termine.
     */
    public function tracking(Request $request, Exercise $exercise): JsonResponse
    {
        $this->authorizeManage($request, $exercise);

        $ids = array_values(array_diff(Audience::userIds($exercise->audienceList()), [(int) $exercise->created_by]));
        $views = ExerciseVideoView::where('exercise_id', $exercise->id)->get()->keyBy('user_id');
        $responses = ExerciseResponse::where('exercise_id', $exercise->id)->get()->keyBy('user_id');
        // Un fidele qui a commence puis change de groupe reste visible.
        $ids = array_values(array_unique(array_merge($ids, $views->keys()->all(), $responses->keys()->all())));

        $members = User::whereIn('id', $ids)->with('profile.tribe:id,name')->get(['id', 'phone', 'activity_status', 'activity_override']);
        $rows = $members->map(function (User $u) use ($exercise, $views, $responses) {
            $view = $views->get($u->id);
            $response = $responses->get($u->id);

            return [
                'user_id' => $u->id,
                'name' => $u->profile?->full_name ?: $u->phone,
                'tribe' => $u->profile?->tribe?->name,
                'active' => $u->activityStatus() === 'active',
                'status' => ExerciseProgress::status($exercise, $view, $response),
                'percent' => ExerciseProgress::percent($exercise, $view),
                'watched_seconds' => $view?->watched_seconds ?? 0,
                'seek_count' => $view?->seek_count ?? 0,
                'skipped_seconds' => $view?->skipped_seconds ?? 0,
                'max_rate' => $view ? (float) $view->max_rate : null,
                'video_completed_at' => $view?->completed_at?->toIso8601String(),
                'last_activity' => collect([$view?->last_heartbeat_at, $response?->completed_at])->filter()->max()?->toIso8601String(),
                'response' => $response?->response,
                'responded_at' => $response?->completed_at?->toIso8601String(),
            ];
        })->sortBy([['status', 'asc'], ['name', 'asc']])->values();

        $count = fn (string $status) => $rows->where('status', $status)->count();

        return response()->json([
            'exercise' => [
                'id' => $exercise->id,
                'title' => $exercise->title,
                'content' => $exercise->content,
                'type_label' => self::TYPES[$exercise->type] ?? $exercise->type,
                'video' => $exercise->videoPayload(),
                'requires_response' => $exercise->needsResponse(),
                'closes_at' => $exercise->closes_at?->toIso8601String(),
                'is_closed' => $exercise->isClosed(),
                'target' => $exercise->audienceLabel(),
            ],
            'summary' => [
                'total' => $rows->count(),
                'done' => $count('done'),
                'in_progress' => $count('in_progress'),
                'todo' => $count('todo'),
                'skipped' => $rows->where('seek_count', '>', 0)->count(),
            ],
            'members' => $rows,
        ]);
    }

    public static function announceTitle(Exercise $exercise): string
    {
        return ($exercise->isVideo() ? 'Nouvelle vidéo à regarder : ' : 'Nouvel exercice : ').$exercise->title;
    }

    public static function announceBody(Exercise $exercise): string
    {
        $what = $exercise->isVideo()
            ? ($exercise->needsResponse() ? 'Regardez la vidéo en entier puis répondez à la consigne' : 'Regardez la vidéo en entier')
            : (self::TYPES[$exercise->type] ?? 'Exercice');
        $until = $exercise->closes_at ? ' avant le '.$exercise->closes_at->locale('fr')->isoFormat('dddd D MMMM [à] H[h]mm') : '';

        return $what.$until.'.';
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
