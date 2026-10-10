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
use App\Support\Like;
use App\Support\MemberScope;
use App\Support\PlainText;
use App\Support\PlatformManager;
use App\Support\YouTube;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Historique des exercices que l'utilisateur suit, du plus recent au plus ancien, avec leur avancement :
     * les siens, tous pour les pasteurs (PR, PA), et pour un AP ou un patriarche ceux qui visent sa tribu (ou
     * ses GEMs, departements) ou que les responsables de sa tribu ont donnes. Filtres : en cours / fermes,
     * les miens, recherche ; 20 par page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status' => ['nullable', 'in:open,closed,all'],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $perPage = 20;
        $page = (int) ($data['page'] ?? 1);
        $status = $data['status'] ?? 'all';
        $query = self::followedBy($user)
            ->when($status === 'open', fn ($q) => $q->where(fn ($w) => $w->whereNull('closes_at')->orWhere('closes_at', '>', now())))
            ->when($status === 'closed', fn ($q) => $q->whereNotNull('closes_at')->where('closes_at', '<=', now()))
            ->when($data['mine'] ?? false, fn ($q) => $q->where('created_by', $user->id))
            ->when(filled($data['q'] ?? null), fn ($q) => Like::contains($q, 'title', trim((string) $data['q'])));
        $total = (clone $query)->count();
        $visible = $user->hasPermission('members.view_all') ? null : array_flip(MemberScope::visibleUserIds($user));
        $audiences = [];

        $exercises = $query->withCount([
            'responses',
            'videoViews as views_count',
            'videoViews as views_completed_count' => fn ($q) => $q->whereNotNull('completed_at'),
        ])->with('scopes', 'creator.profile', 'creator.roles')->latest()->latest('id')->forPage($page, $perPage)->get()
            ->map(function (Exercise $e) use ($user, $visible, &$audiences) {
                return self::progressOf($e, $user, $visible, $audiences) + [
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
                    'author' => self::authorOf($e),
                    'is_mine' => (int) $e->created_by === $user->id,
                    'can_delete' => $user->hasPermission('exercises.delete') && Audience::canManage($user, $e->created_by, $e->audienceList()),
                ];
            })->values();

        return response()->json([
            'exercises' => $exercises, 'types' => $this->typeList(),
            // Actions permises (memes regles que les routes) : creer, lire les reponses de chaque membre.
            'can' => ['create' => $user->hasPermission('exercises.create'), 'view_responses' => $user->hasPermission('exercises.view_responses')],
            'total' => $total, 'page' => $page, 'has_more' => $page * $perPage < $total,
        ]);
    }

    /** Exercices suivis par cet utilisateur (voir index). */
    public static function followedBy(User $user): Builder
    {
        $query = Exercise::query();
        if ($user->canBroadcastAll()) {
            return $query;
        }
        $o = Audience::options($user);
        $tribes = array_column($o['tribes'], 'id');
        $gems = array_column($o['gems'], 'id');
        $depts = array_column($o['departments'], 'id');
        // Responsables (patriarche, AP, Gardes) des tribus et GEMs suivis : leurs exercices aussi.
        $authors = DB::table('role_user')->where(fn ($q) => $q->where(fn ($t) => $t->where('scope_kind', 'tribe')->whereIn('scope_id', $tribes ?: [0]))
            ->orWhere(fn ($g) => $g->where('scope_kind', 'gem')->whereIn('scope_id', $gems ?: [0])))->pluck('user_id')->all();

        return $query->where(function ($w) use ($user, $tribes, $gems, $depts, $authors) {
            $w->where('exercises.created_by', $user->id)
                ->orWhereIn('exercises.created_by', $authors ?: [0])
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('publication_scopes as ps')
                    ->whereColumn('ps.scopable_id', 'exercises.id')->where('ps.scopable_type', Exercise::SCOPABLE_TYPE)
                    ->where(fn ($x) => $x->where(fn ($t) => $t->where('ps.scope_type', 'tribe')->whereIn('ps.scope_id', $tribes ?: [0]))
                        ->orWhere(fn ($g) => $g->where('ps.scope_type', 'gem')->whereIn('ps.scope_id', $gems ?: [0]))
                        ->orWhere(fn ($d) => $d->where('ps.scope_type', 'department')->whereIn('ps.scope_id', $depts ?: [0]))));
        });
    }

    /** Peut-il voir cet exercice et son suivi ? */
    public static function canFollow(User $user, Exercise $exercise): bool
    {
        return Audience::canManage($user, $exercise->created_by, $exercise->audienceList())
            || self::followedBy($user)->whereKey($exercise->id)->exists();
    }

    /** Toute l'audience de l'exercice, ou seulement les membres que l'utilisateur suit ? */
    private static function seesEveryone(User $user, Exercise $exercise): bool
    {
        return $user->hasPermission('members.view_all') || Audience::canManage($user, $exercise->created_by, $exercise->audienceList());
    }

    /**
     * Fideles concernes et ceux qui ont termine, limites a ce que l'utilisateur voit ($visible : ids
     * visibles, null = tout le monde).
     *
     * @param  array<int, int>|null  $visible
     * @param  array<string, array<int>>  $audiences  audiences deja calculees pendant cette requete
     * @return array{audience_count: int, done_count: int, percent: int}
     */
    private static function progressOf(Exercise $e, User $user, ?array $visible, array &$audiences): array
    {
        // Meme audience (ex. toute l'eglise) pour plusieurs exercices de la page : calculee une seule fois.
        $key = json_encode($e->audienceList());
        $audiences[$key] ??= Audience::userIds($e->audienceList());
        $audience = array_diff($audiences[$key], [(int) $e->created_by]);
        if ($visible !== null && ! self::seesEveryone($user, $e)) {
            $audience = array_filter($audience, fn ($id) => isset($visible[$id]));
        }
        $done = count(array_intersect($audience, ExerciseProgress::doneUserIds($e)));

        return ['audience_count' => count($audience), 'done_count' => $done, 'percent' => $audience ? (int) round($done / count($audience) * 100) : 0];
    }

    /** @return array{name: string, role: string|null}|null */
    private static function authorOf(Exercise $e): ?array
    {
        $creator = $e->creator;
        if (! $creator) {
            return null;
        }
        $role = $creator->roles->sortByDesc('rank')->first();

        return ['name' => PlatformManager::nameOf($creator) ?? 'Responsable', 'role' => $creator->isPlatformManager() ? null : $role?->name];
    }

    /**
     * Relancer maintenant ceux qui n'ont pas termine (dans la portee de l'utilisateur), au plus une fois par
     * jour et par personne qui relance : notification et push nominatifs.
     */
    public function remind(Request $request, Exercise $exercise): JsonResponse
    {
        $user = $request->user();
        abort_unless(self::canFollow($user, $exercise), 403, 'Cet exercice est hors de votre périmètre.');
        abort_if($exercise->isClosed(), 409, 'Cet exercice est fermé.');
        $ids = array_diff(Audience::userIds($exercise->audienceList()), ExerciseProgress::doneUserIds($exercise), [(int) $exercise->created_by]);
        if (! self::seesEveryone($user, $exercise)) {
            $ids = array_intersect($ids, MemberScope::visibleUserIds($user));
        }
        abort_if($ids === [], 422, 'Tout le monde a déjà terminé.');
        abort_unless(Notifier::once("exercise-nudge:{$exercise->id}:{$user->id}:".now()->toDateString()), 429, 'Relance déjà envoyée aujourd\'hui.');
        Notifier::send($ids, 'task_reminder', 'Rappel : '.$exercise->title, self::announceBody($exercise),
            '/exercices/'.$exercise->id, ['exercise_id' => $exercise->id]);
        Audit::log('exercise.reminded', $exercise, null, [], ['count' => count($ids)]);

        return response()->json(['message' => count($ids) > 1 ? count($ids).' personnes relancées.' : '1 personne relancée.', 'count' => count($ids)]);
    }

    /** Exercices adresses a un membre, avec son avancement (pasteurs et responsables qui le suivent). */
    public function member(Request $request, User $member): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($member), 403, 'Accès refusé.');
        $profile = $member->profile()->with('departments:id')->first();
        $tribes = array_filter([(int) $profile?->tribe_id]);
        $gems = array_filter([(int) $profile?->gem_id]);
        $depts = $profile ? $profile->departments->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $exercises = Exercise::where('is_active', true)->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $member->id))
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('publication_scopes as ps')
                ->whereColumn('ps.scopable_id', 'exercises.id')->where('ps.scopable_type', Exercise::SCOPABLE_TYPE)
                ->where(fn ($x) => $x->where('ps.scope_type', 'church')
                    ->orWhere(fn ($t) => $t->where('ps.scope_type', 'tribe')->whereIn('ps.scope_id', $tribes ?: [0]))
                    ->orWhere(fn ($g) => $g->where('ps.scope_type', 'gem')->whereIn('ps.scope_id', $gems ?: [0]))
                    ->orWhere(fn ($d) => $d->where('ps.scope_type', 'department')->whereIn('ps.scope_id', $depts ?: [0]))))
            ->latest()->latest('id')->limit(30)->get();
        $views = ExerciseVideoView::where('user_id', $member->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');
        $responses = ExerciseResponse::where('user_id', $member->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');

        $items = $exercises->map(function (Exercise $e) use ($views, $responses) {
            $view = $views->get($e->id);
            $response = $responses->get($e->id);

            return [
                'id' => $e->id,
                'title' => $e->title,
                'type_label' => self::TYPES[$e->type] ?? $e->type,
                'closes_at' => $e->closes_at?->toIso8601String(),
                'is_closed' => $e->isClosed(),
                'status' => ExerciseProgress::status($e, $view, $response),
                'percent' => ExerciseProgress::percent($e, $view),
                'is_video' => $e->isVideo(),
                'response' => $response?->response,
                'done_at' => collect([$view?->completed_at, $response?->completed_at])->filter()->max()?->toIso8601String(),
                'created_at' => $e->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'exercises' => $items,
            'summary' => ['total' => $items->count(), 'done' => $items->where('status', 'done')->count()],
        ]);
    }

    /** Apercu d'un lien YouTube pour les ressources et les devoirs (qui peut en publier). */
    public function contentVideoPreview(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('resources.edit') || $user->hasPermission('assignments.publish') || $user->hasPermission('exercises.create'),
            403, 'Vous n\'avez pas la permission requise.');

        return $this->videoPreview($request);
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
            'title' => PlainText::title($details['title']),
            'author' => PlainText::title($details['author']),
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
        $user = $request->user();
        abort_unless(self::canFollow($user, $exercise), 403, 'Cet exercice est hors de votre périmètre.');

        $ids = array_values(array_diff(Audience::userIds($exercise->audienceList()), [(int) $exercise->created_by]));
        $views = ExerciseVideoView::where('exercise_id', $exercise->id)->get()->keyBy('user_id');
        $responses = ExerciseResponse::where('exercise_id', $exercise->id)->get()->keyBy('user_id');
        // Un fidele qui a commence puis change de groupe reste visible.
        // (l'auteur qui regarde sa propre video n'est pas compte)
        $ids = array_values(array_diff(array_unique(array_merge($ids, $views->keys()->all(), $responses->keys()->all())), [(int) $exercise->created_by]));
        // Exercice qui depasse la portee de l'utilisateur (AP, patriarche) : seulement les membres qu'il suit.
        if (! self::seesEveryone($user, $exercise)) {
            $ids = array_values(array_intersect($ids, MemberScope::visibleUserIds($user)));
        }

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
                'author' => self::authorOf($exercise->load('creator.profile', 'creator.roles')),
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
