<?php

namespace App\Services;

use App\Models\MemberDeletionRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Monitoring\UserAdmin;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Suppression d'un membre :
 * - le Pasteur Resident (PR) supprime directement (definitif, numero bloque pour une nouvelle inscription) ;
 * - le patriarche de la tribu du membre et les responsables de ses departements demandent la
 *   suppression, avec un motif ; le PR approuve (le compte est supprime) ou refuse (motif communique).
 */
class MemberDeletionService
{
    public static function canDelete(User $viewer, User $member): bool
    {
        // Le compte de gestion principal ne se supprime pas depuis l'application (commande serveur seulement).
        return $viewer->isSuperAdmin() && $viewer->id !== $member->id && ! $member->isSuperAdmin() && ! $member->isPlatformManager();
    }

    /** Patriarche de la tribu du membre, ou responsable d'un de ses departements. */
    public static function canRequest(User $viewer, User $member): bool
    {
        if ($viewer->id === $member->id || $member->isSuperAdmin() || $member->isPlatformManager()) {
            return false;
        }
        $profile = $member->profile()->with('departments:id')->first();
        if (! $profile) {
            return false;
        }
        $patriarchTribes = $viewer->roles->filter(fn ($r) => $r->key === 'patriarche' && $r->pivot->scope_kind === 'tribe')
            ->map(fn ($r) => (int) $r->pivot->scope_id)->all();
        if ($profile->tribe_id && in_array((int) $profile->tribe_id, $patriarchTribes, true)) {
            return true;
        }

        return array_intersect($viewer->ledDepartmentIds(), $profile->departments->pluck('id')->map(fn ($id) => (int) $id)->all()) !== [];
    }

    /** Suppression definitive par le PR (directe ou en approuvant une demande). */
    public static function delete(User $pr, User $member, string $reason, ?MemberDeletionRequest $from = null): void
    {
        abort_unless(self::canDelete($pr, $member), 403, 'Seul le Pasteur Résident peut supprimer un membre.');
        $impact = UserAdmin::impact($member);
        $name = $member->profile?->full_name ?: $member->phone;
        $id = $member->id;
        // Demandes encore en attente pour ce membre (autres que celle approuvee) : leurs auteurs sont
        // prevenus comme pour une decision, au lieu de voir leur demande disparaitre sans nouvelle.
        $others = MemberDeletionRequest::where('user_id', $id)->where('status', 'pending')
            ->when($from, fn ($q) => $q->whereKeyNot($from->id))->get();

        DB::transaction(function () use ($pr, $member, $reason, $from, $impact, $name, $id) {
            if ($from) {
                // Reservation atomique : un double clic (ou l'ecran et le courriel en meme temps) ne decide qu'une fois.
                $claimed = MemberDeletionRequest::whereKey($from->id)->where('status', 'pending')
                    ->update(['status' => 'approved', 'decided_by' => $pr->id, 'decided_at' => now(), 'updated_at' => now()]);
                abort_unless($claimed === 1, 409, 'Cette demande a déjà été traitée.');
                $from->refresh();
            }
            // Trace avant la suppression (le membre n'existera plus ensuite).
            Audit::log('member.deleted', null, null, ['name' => $name], [], [
                'user_id' => $id, 'reason' => $reason, 'via' => $from ? 'demande approuvée' : 'Pasteur Résident',
                'deletion_request_id' => $from?->id, 'deleted' => $impact['deleted'] ?? [],
            ], $pr);
            UserAdmin::delete($member, $reason, $pr->id);
        });

        foreach ($others as $other) {
            if ($other->requested_by) {
                Notifier::send([$other->requested_by], 'member', 'Suppression effectuée : '.$other->member_name,
                    'Le Pasteur Résident a supprimé ce membre.', '/admin/membres');
            }
        }
    }

    public static function request(User $leader, User $member, string $reason): MemberDeletionRequest
    {
        abort_unless(self::canRequest($leader, $member), 403, 'Vous ne pouvez pas demander la suppression de ce membre.');
        abort_if(MemberDeletionRequest::where('user_id', $member->id)->where('status', 'pending')->exists(), 409,
            'Une demande de suppression est déjà en attente pour ce membre.');
        $name = $member->profile?->full_name ?: $member->phone;
        $req = MemberDeletionRequest::create([
            'user_id' => $member->id, 'member_name' => $name, 'requested_by' => $leader->id, 'reason' => $reason, 'status' => 'pending',
        ]);
        Audit::log('member.deletion_requested', $req, $member->id, [], ['reason' => $reason]);
        Notifier::send(self::residentPastors(), 'member', 'Demande de suppression : '.$name,
            ($leader->profile?->full_name ?: 'Un responsable').' · '.mb_substr($reason, 0, 140), '/admin/validations',
            ['deletion_request_id' => $req->id], 'high');
        self::mailPastors($req, $member, $leader);

        return $req;
    }

    /** Liens des boutons du courriel : valables 14 jours. */
    public const MAIL_LINK_DAYS = 14;

    /**
     * Courriel au Pasteur Resident (a chacun, s'il a une adresse) : la demande, ce qui serait efface et
     * deux boutons « Accepter / Rejeter » qui decident aussitot (liens signes, nominatifs).
     */
    public static function mailPastors(MemberDeletionRequest $req, User $member, User $leader): int
    {
        $impact = collect(UserAdmin::impact($member)['deleted'] ?? [])->map(fn ($i) => ['label' => $i['label'], 'count' => $i['count']])->values()->all();
        $profile = $member->profile()->with('tribe')->first();
        $queued = 0;
        foreach (User::with('profile')->whereIn('id', self::residentPastors())->get() as $pastor) {
            $email = Messenger::emailOf($pastor);
            if (! $email) {
                continue;
            }
            $link = fn (string $decision) => URL::temporarySignedRoute('email.deletion',
                now()->addDays(self::MAIL_LINK_DAYS), ['deletionRequest' => $req->id, 'decision' => $decision, 'pastor' => $pastor->id]);
            $queued += Messenger::queueEmail($email, $pastor->id, 'deletion_request', 'Demande de suppression : '.$req->member_name, 'deletion-request', [
                'memberName' => $req->member_name,
                'tribe' => $profile?->tribe?->name,
                'phone' => $member->phone,
                'requestedBy' => $leader->profile?->full_name ?: 'Un responsable',
                'reason' => $req->reason,
                'impact' => $impact,
                'approveUrl' => $link('approve'),
                'rejectUrl' => $link('reject'),
                'days' => self::MAIL_LINK_DAYS,
            ], "deletion-request:{$req->id}:{$pastor->id}") ? 1 : 0;
        }

        return $queued;
    }

    public static function decide(User $pr, MemberDeletionRequest $req, bool $approve, ?string $comment): void
    {
        abort_unless($pr->isSuperAdmin(), 403, 'Seul le Pasteur Résident traite les demandes de suppression.');
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');
        $member = $req->member;
        if ($approve) {
            abort_unless($member, 404, 'Ce membre a déjà été supprimé.');
            self::delete($pr, $member, $req->reason, $req);
        } else {
            // Reservation atomique : un seul refus (et une seule notification) meme en cas de double clic.
            $claimed = MemberDeletionRequest::whereKey($req->id)->where('status', 'pending')->update([
                'status' => 'rejected', 'decided_by' => $pr->id, 'decided_at' => now(), 'decision_comment' => $comment, 'updated_at' => now(),
            ]);
            abort_unless($claimed === 1, 409, 'Cette demande a déjà été traitée.');
            $req->refresh();
            Audit::log('member.deletion_rejected', $req, $member?->id, ['status' => 'pending'], ['status' => 'rejected', 'comment' => $comment], [], $pr);
        }
        if ($req->requested_by) {
            Notifier::send([$req->requested_by], 'member',
                $approve ? 'Suppression effectuée : '.$req->member_name : 'Suppression refusée : '.$req->member_name,
                $approve ? 'Le Pasteur Résident a approuvé votre demande.' : ($comment ?: 'Le Pasteur Résident a refusé la demande.'),
                $approve ? '/admin/membres' : ($member ? '/admin/membres/'.$member->id : '/admin/membres'));
        }
    }

    /** @return array<int> Pasteurs Residents. */
    public static function residentPastors(): array
    {
        $roleId = Role::where('key', User::SUPER_ADMIN)->value('id');

        return DB::table('role_user')->where('role_id', $roleId)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
