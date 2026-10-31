<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PulseController;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Profile;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\MemberScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /** Membres pointes au plus sur une feuille (toute l'eglise, avec de la marge). */
    private const MAX_MEMBERS = 20000;

    /**
     * Feuille de presence pour une date + un evenement : membres ACTIFS de la portee.
     * Les inactifs sont masques ; ceux deja pointes ce jour-la restent affiches, et un inactif
     * qui revient peut etre retrouve par une recherche (q) puis ajoute : le pointer le reactive.
     */
    public function roster(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'event' => ['nullable', 'string', 'max:60'],
            'kind' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:80'],
            'department_id' => ['nullable', 'integer'],
        ]);
        $date = $request->query('date') ?: now()->toDateString();
        $department = $this->department($request);
        $event = $this->eventName($request->query('event') ?: 'Culte', $department);
        $kind = $request->query('kind') === 'repetition' ? 'repetition' : 'culte';

        $profiles = $this->scopedProfiles($request->user(), $department, 'attendance.view');
        $records = Attendance::where('attended_on', $date)->where('event', $event)->where('kind', $kind)
            ->whereIn('member_user_id', $profiles->pluck('user_id'))
            ->get()->keyBy('member_user_id');

        [$active, $inactive] = $profiles->partition(fn (Profile $p) => $this->isActive($p) || isset($records[$p->user_id]));

        // Recherche d'un fidele inactif revenu (au moins 2 lettres).
        $q = mb_strtolower(trim((string) $request->query('q')));
        if (mb_strlen($q) >= 2) {
            return response()->json([
                'matches' => $inactive->filter(fn (Profile $p) => str_contains(mb_strtolower($p->full_name), $q))
                    ->take(10)->map(fn (Profile $p) => $this->row($p, $records))->values(),
            ]);
        }

        return response()->json([
            'date' => $date,
            'event' => $event,
            'kind' => $kind,
            'members' => $active->map(fn (Profile $p) => $this->row($p, $records))->values(),
            'inactive_hidden' => $inactive->count(),
        ]);
    }

    /** @param Collection<int, Attendance> $records @return array<string, mixed> */
    private function row(Profile $p, Collection $records): array
    {
        return [
            'user_id' => $p->user_id,
            'full_name' => $p->full_name ?: '(profil incomplet)',
            'photo_url' => $p->photo_url,
            'tribe' => $p->tribe?->name,
            'present' => isset($records[$p->user_id]) && $records[$p->user_id]->status === 'present',
            'status' => $records[$p->user_id]->status ?? null,
        ];
    }

    /** Statut d'activite effectif (force manuellement, sinon statut stocke). */
    private function isActive(Profile $p): bool
    {
        return $p->user?->activityStatus() === 'active';
    }

    /** Enregistrer la presence d'une session (remplace ce qui existait pour cette date + evenement). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attended_on' => ['required', 'date', 'before_or_equal:today'],
            'event' => ['required', 'string', 'max:60'],
            'kind' => ['nullable', 'in:culte,repetition'],
            'present_user_ids' => ['array', 'max:'.self::MAX_MEMBERS],
            'present_user_ids.*' => ['integer'],
            'statuses' => ['array', 'max:'.self::MAX_MEMBERS], // repetition : { user_id: status }
            'department_id' => ['nullable', 'integer'],
        ]);

        $kind = $data['kind'] ?? 'culte';
        $department = $this->department($request);
        $data['event'] = $this->eventName($data['event'], $department);
        $rosterIds = $this->rosterQuery($request->user(), $department, 'attendance.record')->pluck('profiles.user_id');
        $inRoster = $rosterIds->flip();
        $recordedBy = $request->user()->id;
        $valid = ['present', 'retard', 'absent_justifie', 'absent'];

        // Determine le statut de chaque membre selon le type de session.
        $rows = collect();
        if ($kind === 'repetition') {
            foreach (($data['statuses'] ?? []) as $uid => $status) {
                if (isset($inRoster[(int) $uid]) && in_array($status, $valid, true)) {
                    $rows->push(['uid' => (int) $uid, 'status' => $status]);
                }
            }
        } else {
            foreach (collect($data['present_user_ids'] ?? [])->unique() as $uid) {
                if (isset($inRoster[(int) $uid])) {
                    $rows->push(['uid' => (int) $uid, 'status' => 'present']);
                }
            }
        }

        // Une ecriture groupee par lot (et non une requete par membre) ; nouvel essai automatique si deux
        // appels se croisent (blocage mutuel de la base).
        $attendedOn = Carbon::parse($data['attended_on'])->toDateString();
        $removed = 0;
        DB::transaction(function () use ($attendedOn, $data, $kind, $rosterIds, $rows, $recordedBy, &$removed) {
            $sheet = fn () => Attendance::where('attended_on', $attendedOn)->where('event', $data['event']);
            // Meme jour et meme nom, mais une feuille d'un autre type : elle n'est jamais ecrasee.
            abort_if($rows->isNotEmpty() && $sheet()->where('kind', '!=', $kind)->whereIn('member_user_id', $rows->pluck('uid'))->exists(), 422,
                'Une feuille d\'un autre type existe déjà pour ce jour et cet événement.');

            $marked = $rows->pluck('uid')->flip();
            foreach ($rosterIds->reject(fn ($uid) => isset($marked[$uid]))->chunk(500) as $chunk) {
                $removed += $sheet()->where('kind', $kind)->whereIn('member_user_id', $chunk->values())->delete();
            }
            foreach ($rows->chunk(500) as $chunk) {
                Attendance::upsert($chunk->map(fn ($r) => [
                    'member_user_id' => $r['uid'],
                    'attended_on' => $attendedOn,
                    'event' => $data['event'],
                    'kind' => $kind,
                    'status' => $r['status'],
                    'recorded_by' => $recordedBy,
                ])->values()->all(), ['member_user_id', 'attended_on', 'event'], ['status', 'recorded_by']);
            }
        }, 3);
        if ($removed > 0) {
            PulseController::forgetCounts();
        }

        $present = $rows->where('status', 'present')->count();

        // Etre present (ou en retard) a une session est une activite : un membre inactif redevient actif.
        User::whereIn('id', $rows->whereIn('status', ['present', 'retard'])->pluck('uid'))->where('activity_status', 'inactive')->get()
            ->each(fn (User $u) => ActivityService::touch($u, false, 'attendance'));

        return response()->json(['message' => "Session enregistrée ({$present} présent(s))."]);
    }

    /**
     * Membres dont l'utilisateur fait l'appel : toute l'eglise ou sa portee d'action (GEM, tribu, dept...),
     * limitee aux membres d'un departement quand il est choisi (appel d'une repetition).
     */
    private function scopedProfiles(User $user, ?Department $department, string $permission): Collection
    {
        return $this->rosterQuery($user, $department, $permission)
            ->with(['tribe:id,name', 'user:id,activity_status,activity_override'])
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['profiles.id', 'profiles.user_id', 'profiles.first_name', 'profiles.last_name', 'profiles.tribe_id', 'profiles.photo_path']);
    }

    /** Requete des membres de l'appel, sans rien charger : l'enregistrement n'a besoin que de leurs identifiants. */
    private function rosterQuery(User $user, ?Department $department, string $permission): Builder
    {
        $query = Profile::query()->has('user')
            ->when($department, fn ($q) => $q->whereHas('departments', fn ($d) => $d->whereKey($department->id)));

        return MemberScope::manageableProfiles($query, $user, $permission);
    }

    /**
     * Departement choisi pour l'appel : l'un de ceux du perimetre de l'utilisateur (un responsable
     * de plusieurs departements fait l'appel de chacun separement), ou n'importe lequel s'il voit toute l'eglise.
     */
    private function department(Request $request): ?Department
    {
        $id = (int) $request->input('department_id');
        if (! $id) {
            return null;
        }
        $user = $request->user();
        abort_unless($user->hasPermission('members.view_all') || in_array($id, $user->scopeDepartmentIds(), true), 403,
            'Ce département ne fait pas partie de votre périmètre.');

        return Department::findOrFail($id);
    }

    /** Une feuille par departement (« Répétition · Chorale ») : un membre peut servir dans deux departements le meme jour. */
    private function eventName(string $event, ?Department $department): string
    {
        return $department ? mb_substr("{$event} · {$department->name}", 0, 120) : $event;
    }
}
