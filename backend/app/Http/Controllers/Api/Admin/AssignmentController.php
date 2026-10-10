<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Models\Profile;
use App\Models\User;
use App\Services\AssignmentProgress;
use App\Services\MemberMessages;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Audit;
use App\Support\PlatformManager;
use App\Support\YouTube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Mon devoir (DS), cote responsables :
 * - publier ou modifier (assignments.publish), retirer (assignments.delete) : le PR par defaut, et le compte
 *   de gestion principal (qui voit la liste des devoirs, jamais les reponses ni les chiffres des membres) ;
 * - les pasteurs et les responsables de la tribu (patriarche, AP) lisent les resumes et
 *   resolutions des membres dont ils voient les FISS (meme confidentialite).
 */
class AssignmentController extends Controller
{
    /** Taille maximale d'un fichier audio depose (Ko). */
    public const AUDIO_MAX_KB = 40960;

    private const AUDIO_TYPES = 'audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/x-aac,audio/wav,audio/x-wav,audio/wave,audio/ogg,audio/webm';

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $canPublish = $viewer->hasPermission('assignments.publish');
        // Publication sans suivi (ex. compte de gestion) : la liste des devoirs, sans aucun chiffre sur les membres.
        if ($canPublish && $viewer->fissTribeIds() === [] && ! $viewer->isSuperAdmin()) {
            $assignments = Assignment::with('creator.profile:id,user_id,first_name,last_name')
                ->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();

            return response()->json([
                'assignments' => $assignments->map(fn (Assignment $a) => $this->present($a))->values(),
                'can_manage' => true,
                'can_delete' => $viewer->hasPermission('assignments.delete'),
                'can_track' => false,
                'audio_max_mb' => intdiv(self::AUDIO_MAX_KB, 1024),
            ]);
        }
        $members = $this->scopedMembers($viewer);
        $total = (clone $members)->count();
        $memberIds = (clone $members)->select('user_id');

        $assignments = Assignment::with('creator.profile:id,user_id,first_name,last_name')
            ->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();
        // Deux lectures groupees (et non deux par devoir) : resumes envoyes et lectures terminees.
        $ids = $assignments->pluck('id');
        $responded = AssignmentResponse::whereIn('assignment_id', $ids)->whereIn('user_id', $memberIds)
            ->get(['assignment_id', 'user_id'])->groupBy('assignment_id');
        $completed = AssignmentView::whereIn('assignment_id', $ids)->whereIn('user_id', (clone $members)->select('user_id'))
            ->whereNotNull('completed_at')->get(['assignment_id', 'user_id'])->groupBy('assignment_id');

        return response()->json([
            'assignments' => $assignments->map(function (Assignment $a) use ($responded, $completed, $total) {
                $resp = ($responded[$a->id] ?? collect())->pluck('user_id');
                $done = $a->isTrackable() ? $resp->intersect(($completed[$a->id] ?? collect())->pluck('user_id')) : $resp;

                return $this->present($a) + [
                    'responses_count' => $resp->count(),
                    'done_count' => $done->count(),
                    'members_count' => $total,
                ];
            })->values(),
            'can_manage' => $canPublish,
            'can_delete' => $viewer->hasPermission('assignments.delete'),
            'can_track' => true,
            'audio_max_mb' => intdiv(self::AUDIO_MAX_KB, 1024),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureManager($request);
        $data = $this->validated($request, null);

        $assignment = DB::transaction(function () use ($data, $request) {
            $assignment = Assignment::create($data + [
                'published_at' => now(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            Audit::log('assignment.created', $assignment, null, [], $this->snapshot($assignment));

            return $assignment;
        });

        if ($request->boolean('notify', true)) {
            $what = $assignment->media_type === 'audio' ? 'Écoutez' : 'Regardez';
            $members = array_diff(Audience::userIds([['type' => 'church', 'id' => null]]), [$request->user()->id]);
            Notifier::send($members, 'assignment', 'Nouveau devoir : '.$assignment->title,
                ($assignment->speaker ? $assignment->speaker.' · ' : '')."{$what}, puis écrivez votre résumé et vos résolutions.",
                '/mon-devoir?d='.$assignment->id, ['assignment_id' => $assignment->id]);
            // Courriel (adresse remplie) ou, a defaut, texto : mis en file, envoyes en arriere-plan.
            MemberMessages::assignmentPublished($assignment, $members);
        }

        return response()->json(['message' => 'Devoir publié.', 'assignment' => $this->present($assignment)], 201);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $this->ensureManager($request);
        $data = $this->validated($request, $assignment);
        $before = $this->snapshot($assignment);
        $oldAudio = $assignment->audio_path;
        // Autre video ou autre audio : la lecture deja faite ne vaut plus pour ce nouveau media.
        $mediaChanged = $data['youtube_id'] !== $assignment->youtube_id || $data['audio_path'] !== $assignment->audio_path
            || (! $data['youtube_id'] && ! $data['audio_path'] && $data['media_url'] !== $assignment->media_url);

        DB::transaction(function () use ($assignment, $data, $request, $before, $mediaChanged) {
            if ($mediaChanged) {
                $data['media_duration'] = null;
                AssignmentView::where('assignment_id', $assignment->id)->delete();
            }
            $assignment->update($data + ['updated_by' => $request->user()->id]);
            [$old, $new] = Audit::diff($before, $this->snapshot($assignment));
            if ($new) {
                Audit::log('assignment.updated', $assignment, null, $old, $new);
            }
        });
        if ($oldAudio && $oldAudio !== $assignment->audio_path) {
            Storage::disk('public')->delete($oldAudio);
        }

        return response()->json(['message' => 'Devoir mis à jour.', 'assignment' => $this->present($assignment->fresh())]);
    }

    /** Retire le devoir pour les membres ; leurs reponses restent dans leur historique. */
    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('assignments.delete'), 403, 'Vous ne pouvez pas retirer les devoirs (DS).');
        Audit::log('assignment.deleted', $assignment, null, $this->snapshot($assignment), [], ['responses' => $assignment->responses()->count()]);
        $assignment->forceFill(['updated_by' => $request->user()->id])->save();
        $assignment->delete();

        return response()->json(['message' => 'Devoir retiré.']);
    }

    /**
     * Reponses d'un devoir (membres dont le responsable voit les FISS) avec le suivi de lecture, comme
     * pour les exercices video : pourcentage lu, avances rapides, vitesse ; et ceux qui ne l'ont pas fait.
     */
    public function responses(Request $request, Assignment $assignment): JsonResponse
    {
        $members = $this->scopedMembers($request->user())->with('tribe:id,name')
            ->orderBy('last_name')->orderBy('first_name')->limit(2000)->get(['user_id', 'first_name', 'last_name', 'tribe_id', 'photo_path']);
        $responses = AssignmentResponse::where('assignment_id', $assignment->id)
            ->whereIn('user_id', $members->pluck('user_id'))->get()->keyBy('user_id');
        $views = AssignmentView::where('assignment_id', $assignment->id)
            ->whereIn('user_id', $members->pluck('user_id'))->get()->keyBy('user_id');
        $base = fn (Profile $p) => ['user_id' => $p->user_id, 'name' => $p->full_name, 'tribe' => $p->tribe?->name, 'photo_url' => $p->photo_url]
            + AssignmentProgress::tracking($assignment, $views->get($p->user_id))
            + ['status' => AssignmentProgress::status($assignment, $views->get($p->user_id), $responses->get($p->user_id))];

        return response()->json([
            'assignment' => $this->present($assignment) + ['trackable' => $assignment->isTrackable()],
            'responses' => $members->filter(fn ($p) => isset($responses[$p->user_id]))->map(fn (Profile $p) => $base($p) + [
                'summary' => $responses[$p->user_id]->summary,
                'actions' => $responses[$p->user_id]->actions,
                'submitted_at' => $responses[$p->user_id]->submitted_at?->toIso8601String(),
            ])->sortByDesc('submitted_at')->values(),
            'missing' => $members->reject(fn ($p) => isset($responses[$p->user_id]))->map($base)->values(),
        ]);
    }

    /** Devoirs d'un membre (fiche du membre). */
    public function member(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user) && $viewer->canViewFissOf($user), 403,
            "Les devoirs d'un membre sont réservés aux pasteurs et aux responsables de sa tribu.");

        $responses = AssignmentResponse::where('user_id', $user->id)->with('assignment')->latest('submitted_at')->limit(24)->get();
        $views = AssignmentView::where('user_id', $user->id)->whereIn('assignment_id', $responses->pluck('assignment_id'))->get()->keyBy('assignment_id');
        $published = Assignment::published()->count();

        return response()->json([
            'responses' => $responses->map(fn (AssignmentResponse $r) => [
                'id' => $r->id,
                'assignment_id' => $r->assignment_id,
                'title' => $r->assignment?->title,
                'speaker' => $r->assignment?->speaker,
                'withdrawn' => (bool) $r->assignment?->trashed(),
                ...($r->assignment ? AssignmentProgress::tracking($r->assignment, $views->get($r->assignment_id)) : []),
                'done' => $r->assignment ? AssignmentProgress::isDone($r->assignment, $views->get($r->assignment_id), $r) : true,
                'summary' => $r->summary,
                'actions' => $r->actions,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ])->values(),
            'published_count' => $published,
        ]);
    }

    /**
     * Membres dont l'utilisateur voit les FISS (pasteurs : tous ; patriarche, AP : leurs tribus).
     *
     * @return Builder<Profile>
     */
    private function scopedMembers(User $viewer)
    {
        $tribes = $viewer->fissTribeIds();
        abort_if($tribes === [] && ! $viewer->isSuperAdmin(), 403,
            'Les devoirs des membres sont suivis par les pasteurs et les responsables de tribu.');

        return Profile::query()->where('is_completed', true)
            ->when($tribes !== null, fn ($q) => $q->whereIn('tribe_id', $tribes ?: [0]));
    }

    private function ensureManager(Request $request): void
    {
        abort_unless($request->user()->hasPermission('assignments.publish'), 403, 'Seul le Pasteur Résident peut publier les devoirs (DS).');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Assignment $current): array
    {
        $data = $request->validate([
            // Titre et orateur : pre-remplis depuis YouTube s'ils sont laisses vides.
            'title' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'speaker' => ['nullable', 'string', 'max:120'],
            'media_type' => ['required', 'in:video,audio'],
            'url' => ['nullable', 'string', 'max:500'],
            'audio' => ['nullable', 'file', 'max:'.self::AUDIO_MAX_KB, 'mimetypes:'.self::AUDIO_TYPES],
            'remove_audio' => ['nullable', 'boolean'],
            'notify' => ['nullable', 'boolean'],
        ], [
            'audio.max' => 'Le fichier audio dépasse '.intdiv(self::AUDIO_MAX_KB, 1024).' Mo.',
            'audio.mimetypes' => 'Format audio non reconnu : utilisez un fichier MP3, M4A, WAV ou OGG.',
        ]);

        $url = trim((string) ($data['url'] ?? ''));
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['url' => "Ce lien n'est pas une adresse web valide."]);
        }
        $youtubeId = $url ? YouTube::videoId($url) : null;
        $named = $youtubeId ? YouTube::prefill($youtubeId, $data['title'] ?? null, $data['speaker'] ?? null)
            : ['title' => $data['title'] ?? null, 'author' => $data['speaker'] ?? null];
        if (mb_strlen(trim((string) $named['title'])) < 3) {
            throw ValidationException::withMessages(['title' => 'Indiquez un titre (3 caractères au moins).']);
        }
        $out = [
            'title' => trim((string) $named['title']),
            'instructions' => $data['instructions'] ?? null,
            'speaker' => $named['author'],
            'media_type' => $data['media_type'],
            'media_url' => $url ?: null,
            'youtube_id' => $youtubeId,
        ];

        if ($data['media_type'] === 'video') {
            if ($url === '') {
                throw ValidationException::withMessages(['url' => 'Collez le lien de la vidéo (YouTube de préférence).']);
            }
            $out['audio_path'] = null;
        } else {
            if ($request->hasFile('audio')) {
                $out['audio_path'] = $request->file('audio')->store('assignments', 'public');
            } elseif ($current?->audio_path && ! $request->boolean('remove_audio')) {
                $out['audio_path'] = $current->audio_path;
            } else {
                $out['audio_path'] = null;
            }
            if (! $out['audio_path'] && $url === '') {
                throw ValidationException::withMessages(['audio' => 'Ajoutez le fichier audio ou collez son lien.']);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function snapshot(Assignment $a): array
    {
        return $a->only(['title', 'speaker', 'media_type', 'media_url', 'audio_path']);
    }

    /** @return array<string, mixed> */
    public static function presentFor(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'instructions' => $a->instructions,
            'speaker' => $a->speaker,
            'media_type' => $a->media_type,
            'media_url' => $a->media_url,
            'media' => $a->mediaPayload(),
            'thumbnail' => $a->youtube_id ? YouTube::thumbnail($a->youtube_id) : null,
            'published_at' => $a->published_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Assignment $a): array
    {
        return self::presentFor($a) + [
            'author' => PlatformManager::nameOf($a->creator),
            'has_audio_file' => (bool) $a->audio_path,
        ];
    }
}
