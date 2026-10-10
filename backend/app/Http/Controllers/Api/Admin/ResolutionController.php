<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonthlyResolution;
use App\Models\Profile;
use App\Models\ResolutionResource;
use App\Models\ResourceView;
use App\Models\User;
use App\Services\FissService;
use App\Services\ResolutionService;
use App\Support\Audit;
use App\Support\ResolutionCatalog;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Mes RM, cote responsables :
 * - suivi du mois (pasteurs : toute l'eglise ; patriarche, AP : leurs tribus), meme confidentialite que la FISS ;
 * - resolutions d'un membre (sa fiche) ;
 * - ressources et liens video (meditation, priere, jeune, culte) : permissions resources.* (voir les routes).
 */
class ResolutionController extends Controller
{
    /** Qui a pris ses resolutions ce mois-ci, renouvellements et bilan du mois precedent. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $period = $data['period'] ?? now()->format('Y-m');
        $previous = Carbon::createFromFormat('Y-m-d', $period.'-01')->subMonthNoOverflow()->format('Y-m');
        $viewer = $request->user();
        $tribes = $viewer->fissTribeIds();
        abort_if($tribes === [], 403, 'Les résolutions des membres sont suivies par les pasteurs et les responsables de tribu.');

        $members = Profile::where('is_completed', true)
            ->when($tribes !== null, fn ($q) => $q->whereIn('tribe_id', $tribes ?: [0]))
            ->with('tribe:id,name')->orderBy('last_name')->orderBy('first_name')->limit(2000)
            ->get(['user_id', 'first_name', 'last_name', 'tribe_id', 'photo_path']);
        $rows = MonthlyResolution::whereIn('period', [$period, $previous])->whereIn('user_id', $members->pluck('user_id'))
            ->get()->groupBy('period');
        $cur = ($rows[$period] ?? collect())->keyBy('user_id');
        $prev = ($rows[$previous] ?? collect())->keyBy('user_id');

        $list = $members->map(function (Profile $p) use ($cur, $prev) {
            $r = $cur[$p->user_id] ?? null;
            $b = $prev[$p->user_id] ?? null;

            return [
                'user_id' => $p->user_id,
                'name' => $p->full_name,
                'tribe' => $p->tribe?->name,
                'photo_url' => $p->photo_url,
                'taken' => $r !== null,
                'count' => $r ? count($r->filled()) : 0,
                'renewed' => $r ? count($r->renewed ?? []) : 0,
                'previous' => $b ? [
                    'count' => count($b->filled()),
                    'achieved' => count(array_filter($b->outcome ?? [], fn ($v) => $v === 'atteinte')),
                    'reviewed' => $b->outcome !== null,
                ] : null,
            ];
        })->values();

        return response()->json([
            'period' => $period,
            'period_label' => FissService::periodLabel($period),
            'members' => $list,
            'taken' => $list->where('taken', true)->count(),
            'total' => $list->count(),
        ]);
    }

    /** Resolutions d'un membre (12 derniers mois). */
    public function member(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user) && $viewer->canViewFissOf($user), 403,
            "Les résolutions d'un membre sont réservées aux pasteurs et aux responsables de sa tribu.");

        $rows = MonthlyResolution::where('user_id', $user->id)->orderByDesc('period')->limit(12)->get();

        return response()->json([
            'resolutions' => $rows->map(fn ($r) => ResolutionService::present($r))->values(),
            'fields' => collect(ResolutionCatalog::fields())->map(fn ($f) => $f['label']),
            'outcomes' => ResolutionCatalog::OUTCOMES,
            'current_period' => now()->format('Y-m'),
        ]);
    }

    // ---------------------------------------------------------------- Ressources (PR)

    /** Ressources par theme, brouillons compris (onglet de gestion). */
    public function resources(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'groups' => ResolutionService::resources(true),
            'themes' => ResolutionCatalog::THEMES,
            'can' => [
                'edit' => $user->hasPermission('resources.edit'),
                'publish' => $user->hasPermission('resources.publish'),
                'delete' => $user->hasPermission('resources.delete'),
            ],
        ]);
    }

    public function storeResource(Request $request): JsonResponse
    {
        $data = $this->validatedResource($request);
        $this->ensureCanPublish($request, $data['status']);
        $data['position'] = (int) ResolutionResource::where('theme', $data['theme'])->max('position') + 1;
        $resource = ResolutionResource::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        Audit::log('resolution_resource.created', $resource, null, [], $resource->only(['theme', 'title', 'author', 'url', 'status']));

        return response()->json([
            'message' => $resource->status === 'published' ? 'Ressource publiée.' : 'Brouillon enregistré.',
            'resource' => ResolutionService::presentResource($resource),
        ], 201);
    }

    public function updateResource(Request $request, ResolutionResource $resource): JsonResponse
    {
        $data = $this->validatedResource($request, $resource);
        // Publier, depublier ou modifier une ressource deja visible des membres : permission « publier ».
        if ($data['status'] !== $resource->status || $resource->status === 'published') {
            $this->ensureCanPublish($request, 'published');
        }
        $before = $resource->only(['theme', 'title', 'author', 'url', 'note', 'status']);
        // Autre video : duree et lectures deja faites remises a zero (elles valaient pour l'ancienne).
        if ($data['youtube_id'] !== $resource->youtube_id) {
            $data['media_duration'] = null;
            ResourceView::where('resolution_resource_id', $resource->id)->delete();
        }
        // Changement de theme : la ressource se place a la fin de son nouvel onglet.
        if ($data['theme'] !== $resource->theme) {
            $data['position'] = (int) ResolutionResource::where('theme', $data['theme'])->max('position') + 1;
        }
        $resource->update($data + ['updated_by' => $request->user()->id]);
        [$old, $new] = Audit::diff($before, $resource->only(array_keys($before)));
        if ($new) {
            $action = match (true) {
                array_keys($new) === ['status'] && $resource->status === 'published' => 'resolution_resource.published',
                array_keys($new) === ['status'] => 'resolution_resource.unpublished',
                default => 'resolution_resource.updated',
            };
            Audit::log($action, $resource, null, $old, $new);
        }

        return response()->json(['message' => 'Ressource mise à jour.', 'resource' => ResolutionService::presentResource($resource)]);
    }

    public function destroyResource(Request $request, ResolutionResource $resource): JsonResponse
    {
        Audit::log('resolution_resource.deleted', $resource, null, $resource->only(['theme', 'title', 'author', 'url', 'status']));
        $resource->delete();

        return response()->json(['message' => 'Ressource retirée.']);
    }

    private function ensureCanPublish(Request $request, string $status): void
    {
        if ($status === 'published' && ! $request->user()->hasPermission('resources.publish')) {
            throw ValidationException::withMessages([
                'status' => 'Vous pouvez préparer des brouillons ; publier, dépublier ou modifier une ressource publiée demande la permission « Publier ».',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validatedResource(Request $request, ?ResolutionResource $current = null): array
    {
        $data = $request->validate([
            'theme' => ['required', 'in:'.implode(',', array_keys(ResolutionCatalog::THEMES))],
            // Titre et auteur : pre-remplis depuis YouTube s'ils sont laisses vides (voir plus bas).
            'title' => ['nullable', 'string', 'max:150'],
            'author' => ['nullable', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:300'],
            'status' => ['nullable', 'in:draft,published'],
        ], [
            'author.required' => "Indiquez le nom de l'auteur.",
            'url.required' => 'Collez le lien de la ressource (YouTube ou autre adresse web).',
            'title.required' => 'Indiquez un titre.',
        ]);

        $url = trim($data['url']);
        // Autre protocole que le web (ftp:, javascript:, data:...) : refuse, jamais complete en https.
        $foreignScheme = preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m) && ! in_array(strtolower($m[1]), ['http', 'https'], true)
            && ! preg_match('#^[^/]+:\d+(/|$)#', $url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($foreignScheme || ! filter_var($url, FILTER_VALIDATE_URL) || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['url' => "Ce lien n'est pas une adresse web valide."]);
        }
        $youtubeId = YouTube::videoId($url);
        // Lien YouTube vers une video dont l'identifiant est absent ou abime : refuse (les chaines et
        // listes de lecture restent de simples liens).
        if (! $youtubeId && YouTube::looksLikeBrokenVideoLink($url)) {
            throw ValidationException::withMessages(['url' => "Ce lien YouTube ne contient pas de vidéo reconnaissable. Copiez l'adresse depuis le bouton « Partager » de la vidéo."]);
        }
        $data['url'] = $url;
        $data['youtube_id'] = $youtubeId;
        if ($youtubeId) {
            ['title' => $data['title'], 'author' => $data['author']] = YouTube::prefill($youtubeId, $data['title'] ?? null, $data['author'] ?? null);
        }
        $missing = [];
        if (mb_strlen(trim((string) ($data['title'] ?? ''))) < 2) {
            $missing['title'] = 'Indiquez un titre.';
        }
        if (mb_strlen(trim((string) ($data['author'] ?? ''))) < 2) {
            $missing['author'] = "Indiquez le nom de l'auteur.";
        }
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }
        // Statut : celui demande ; sinon publie pour qui peut publier, brouillon pour les autres.
        $data['status'] = $data['status'] ?? $current?->status ?? ($request->user()->hasPermission('resources.publish') ? 'published' : 'draft');

        return $data;
    }
}
