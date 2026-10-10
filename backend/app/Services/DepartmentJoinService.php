<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Recipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inscription a un service (departement) : le fidele fait une demande, le responsable du departement la
 * valide ou la refuse (a defaut de responsable : les autorites qui gerent les departements). Les
 * responsables sont prevenus dans l'application et par courriel ; le fidele recoit la decision.
 */
class DepartmentJoinService
{
    /** Qui decide pour ce departement : ses responsables, sinon les autorites des departements. */
    public static function deciderIds(Department $department): array
    {
        $leaders = array_values(array_filter(Recipients::departmentLeaders($department),
            fn (int $id) => User::find($id)?->hasPermission('departments.approve')));

        return $leaders ?: Recipients::authorities('departments.manage');
    }

    public static function canDecide(User $actor, DepartmentJoinRequest $request): bool
    {
        if ($actor->id === $request->user_id || ! $actor->hasPermission('departments.approve')) {
            return false;
        }

        return $actor->hasPermission('departments.manage')
            || in_array((int) $request->department_id, $actor->scopeDepartmentIds(), true);
    }

    /** @return Collection<int, DepartmentJoinRequest> demandes en attente que cet utilisateur peut traiter */
    public static function pendingFor(User $actor): Collection
    {
        if (! $actor->hasPermission('departments.approve')) {
            return collect();
        }

        return DepartmentJoinRequest::where('status', 'pending')->with('department', 'member.profile.tribe')->oldest()->get()
            ->filter(fn (DepartmentJoinRequest $r) => $r->department && $r->member && self::canDecide($actor, $r))->values();
    }

    /** Demande d'inscription (une seule en attente par service). Retourne la demande, ou null si deja membre. */
    public static function request(User $member, Department $department): ?DepartmentJoinRequest
    {
        abort_unless($department->is_active, 422, "Ce service n'accepte pas d'inscription pour le moment.");
        $profile = $member->profile;
        abort_unless($profile && $profile->is_completed, 422, "Complétez d'abord votre profil.");
        if ($profile->departments()->whereKey($department->id)->exists()) {
            return null;
        }

        $request = DB::transaction(function () use ($member, $department) {
            User::whereKey($member->id)->lockForUpdate()->first();
            $existing = DepartmentJoinRequest::where('user_id', $member->id)->where('department_id', $department->id)
                ->where('status', 'pending')->first();

            return $existing ?? DepartmentJoinRequest::create(['user_id' => $member->id, 'department_id' => $department->id]);
        });
        if (! $request->wasRecentlyCreated) {
            return $request;
        }

        Audit::log('service_join.requested', $request, $member->id, [], ['department' => $department->name]);
        $name = $profile->full_name ?: $member->phone;
        $deciders = array_values(array_diff(self::deciderIds($department), [$member->id]));
        Notifier::send($deciders, 'service', "Inscription au service {$department->name} : {$name}",
            'Une demande attend votre validation.', '/admin/validations#services', ['department_join_request_id' => $request->id], 'high');
        foreach (User::with('profile')->whereIn('id', $deciders)->get() as $leader) {
            MemberMessages::serviceRequest($leader, $member, $department, $request);
        }

        return $request;
    }

    /** Le fidele retire sa demande en attente. */
    public static function cancel(User $member, Department $department): bool
    {
        $request = DepartmentJoinRequest::where('user_id', $member->id)->where('department_id', $department->id)->where('status', 'pending')->first();
        if (! $request) {
            return false;
        }
        $request->update(['status' => 'cancelled', 'decided_at' => now()]);
        Audit::log('service_join.cancelled', $request, $member->id, [], ['department' => $department->name]);

        return true;
    }

    public static function decide(User $actor, DepartmentJoinRequest $request, bool $approve): void
    {
        abort_unless(self::canDecide($actor, $request), 403, 'Seul le responsable de ce service traite cette demande.');
        $claimed = DepartmentJoinRequest::whereKey($request->id)->where('status', 'pending')->update([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now(),
        ]);
        abort_unless($claimed === 1, 409, 'Cette demande a déjà été traitée.');
        $request->refresh()->load('department', 'member.profile');
        $department = $request->department;
        $member = $request->member;

        if ($approve) {
            self::welcome($request, $actor);
        } else {
            Audit::log('service_join.rejected', $request, $member->id, [], ['department' => $department->name]);
            Notifier::send([$member->id], 'service', "Inscription au service {$department->name}",
                "Votre demande n'a pas été retenue pour le moment. Vous pouvez en parler au responsable du service.", '/servir');
        }
    }

    /**
     * Validation automatique (reglage « Validation automatique des inscriptions aux services ») : demande restee
     * sans reponse du responsable apres le delai choisi. Retourne false si elle a ete traitee entre-temps.
     */
    public static function approveAutomatically(DepartmentJoinRequest $request): bool
    {
        $claimed = DepartmentJoinRequest::whereKey($request->id)->where('status', 'pending')->update([
            'status' => 'approved', 'decided_by' => null, 'decided_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            return false;
        }
        $request->refresh()->load('department', 'member.profile');
        self::welcome($request, null);

        return true;
    }

    /** Inscription acceptee (par un responsable, ou automatiquement quand $actor est null). */
    private static function welcome(DepartmentJoinRequest $request, ?User $actor): void
    {
        $department = $request->department;
        $member = $request->member;
        $name = $member->profile?->full_name ?: $member->phone;
        $member->profile?->departments()->syncWithoutDetaching([$department->id]);
        Audit::log($actor ? 'service_join.approved' : 'service_join.auto_approved', $request, $member->id, [], ['department' => $department->name], [], $actor);
        Notifier::send([$member->id], 'service', "Bienvenue au service {$department->name}",
            'Votre inscription est validée. Vous recevrez désormais les annonces et les événements de ce département.', '/servir');
        // Les responsables du departement sont informes (la demande ne les attend plus).
        Notifier::send(array_values(array_diff(Recipients::departmentLeaders($department), array_filter([$actor?->id, $member->id]))), 'service',
            "Nouveau serviteur : {$name}", $actor
                ? "{$name} a rejoint le service {$department->name}."
                : "{$name} a rejoint le service {$department->name} (demande validée automatiquement, sans réponse dans le délai).",
            "/admin/membres/{$member->id}");
    }

    /** @return array<string, mixed> */
    public static function present(DepartmentJoinRequest $r): array
    {
        return [
            'id' => $r->id,
            'department' => ['id' => $r->department_id, 'name' => $r->department?->name],
            'member' => [
                'user_id' => $r->user_id,
                'name' => $r->member?->profile?->full_name ?: $r->member?->phone,
                'tribe' => $r->member?->profile?->tribe?->name,
                'photo_url' => $r->member?->profile?->photo_url,
            ],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
