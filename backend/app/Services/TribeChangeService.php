<?php

namespace App\Services;

use App\Models\Tribe;
use App\Models\TribeChangeApproval;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\GemRules;
use App\Support\Recipients;
use Illuminate\Support\Facades\DB;

/**
 * Changement de tribu (jamais direct pour un membre qui a deja une tribu) :
 * 1. le membre fait une demande (nouvelle tribu + motif) ;
 * 2. les responsables de l'ancienne et de la nouvelle tribu sont prevenus ;
 * 3. chaque cote doit approuver (responsable de cette tribu avec tribes.transfer) ;
 *    une autorite pastorale (members.view_all + tribes.transfer) peut approuver pour les deux ;
 * 4. un refus cloture la demande ; toutes les approbations => changement applique ;
 * 5. chaque etape est conservee (decisions, dates, commentaires) et auditee.
 */
class TribeChangeService
{
    public static function request(User $member, int $toTribeId, ?string $reason): TribeChangeRequest
    {
        $profile = $member->profile;
        abort_unless($profile, 422, 'Complétez d\'abord votre profil.');
        abort_unless(Tribe::whereKey($toTribeId)->where('is_active', true)->exists(), 422, 'Tribu invalide.');
        abort_if((int) $profile->tribe_id === $toTribeId, 422, 'Vous faites déjà partie de cette tribu.');
        abort_if(TribeChangeRequest::where('user_id', $member->id)->where('status', 'pending')->exists(), 409,
            'Une demande de changement de tribu est déjà en cours.');

        $req = TribeChangeRequest::create([
            'user_id' => $member->id,
            'from_tribe_id' => $profile->tribe_id,
            'to_tribe_id' => $toTribeId,
            'reason' => $reason,
            'status' => 'pending',
        ]);
        $req->load('fromTribe', 'toTribe');
        Audit::log('tribe_change.requested', $req, $member->id, ['tribe_id' => $profile->tribe_id], ['tribe_id' => $toTribeId], ['reason' => $reason]);

        $name = $profile->full_name ?: $member->phone;
        $from = $req->fromTribe?->name ?? 'aucune';
        Notifier::send(self::approvers($req), 'tribe_change', "Changement de tribu demandé : {$name}",
            "De {$from} vers {$req->toTribe->name}".($reason ? ' · '.mb_substr($reason, 0, 120) : ''),
            '/admin/validations', ['tribe_change_request_id' => $req->id], 'high');

        return $req;
    }

    /** Cotes pour lesquels cet utilisateur peut se prononcer. */
    public static function sidesFor(User $user, TribeChangeRequest $req): array
    {
        if ($user->id === $req->user_id || ! $user->hasPermission('tribes.transfer') || $req->status !== 'pending') {
            return [];
        }
        if ($user->hasPermission('members.view_all')) {
            return ['both'];
        }
        $tribes = $user->scopeTribeIds();
        $sides = [];
        if ($req->from_tribe_id && in_array((int) $req->from_tribe_id, $tribes, true)) {
            $sides[] = 'from';
        }
        if (in_array((int) $req->to_tribe_id, $tribes, true)) {
            $sides[] = 'to';
        }
        $already = $req->approvals->pluck('side')->all();

        return array_values(array_diff($sides, $already));
    }

    public static function decide(User $approver, TribeChangeRequest $req, bool $approve, ?string $comment): TribeChangeRequest
    {
        $req->load('approvals');
        $sides = self::sidesFor($approver, $req);
        abort_unless($sides, 403, 'Vous ne pouvez pas (ou plus) vous prononcer sur cette demande.');

        return DB::transaction(function () use ($approver, $req, $approve, $comment, $sides) {
            foreach ($sides as $side) {
                TribeChangeApproval::create([
                    'request_id' => $req->id, 'side' => $side, 'approver_id' => $approver->id,
                    'decision' => $approve ? 'approved' : 'rejected', 'comment' => $comment, 'decided_at' => now(),
                ]);
            }
            Audit::log($approve ? 'tribe_change.approved' : 'tribe_change.rejected', $req, $req->user_id, [],
                ['sides' => $sides, 'comment' => $comment]);
            $req->load('approvals', 'fromTribe', 'toTribe', 'member.profile');

            if (! $approve) {
                $req->forceFill(['status' => 'rejected', 'completed_at' => now()])->save();
                Notifier::send([$req->user_id], 'tribe_change', 'Changement de tribu refusé',
                    $comment ?: "Votre demande pour rejoindre la tribu {$req->toTribe->name} n'a pas été acceptée.", '/mon-profil');

                return $req;
            }

            if (! array_diff($req->requiredSides(), $req->approvedSides())) {
                self::apply($req);
            }

            return $req;
        });
    }

    public static function cancel(User $member, TribeChangeRequest $req): void
    {
        abort_unless($req->user_id === $member->id, 404);
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');
        $req->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
        Audit::log('tribe_change.cancelled', $req, $member->id);
    }

    /** Toutes les validations obtenues : le membre change de tribu. */
    private static function apply(TribeChangeRequest $req): void
    {
        $profile = $req->member->profile;
        $old = $profile->tribe_id;
        $profile->forceFill(['tribe_id' => $req->to_tribe_id])->save();
        GemRules::afterTribeChange($profile, auth()->id() ?? $req->user_id);
        $req->forceFill(['status' => 'approved', 'completed_at' => now()])->save();
        Audit::log('tribe.changed', $profile, $req->user_id, ['tribe_id' => $old], ['tribe_id' => $req->to_tribe_id], ['tribe_change_request_id' => $req->id]);

        Notifier::send([$req->user_id], 'tribe_change', "Bienvenue dans la tribu {$req->toTribe->name} !",
            'Votre changement de tribu a été validé.', '/mon-profil');
        $name = $profile->full_name;
        Notifier::send(array_diff(Recipients::tribeLeadersFor($req->to_tribe_id, 'members.view_scope'), [$req->user_id]),
            'member', "Nouveau membre dans la tribu : {$name}", "Arrive de la tribu {$req->fromTribe?->name}.", '/admin/membres/'.$req->user_id);
    }

    /** Personnes a prevenir : responsables de l'ancienne et de la nouvelle tribu (ou autorites). */
    public static function approvers(TribeChangeRequest $req): array
    {
        return array_values(array_unique(array_merge(
            $req->from_tribe_id ? Recipients::tribeLeadersFor($req->from_tribe_id, 'tribes.transfer', $req->user_id) : [],
            Recipients::tribeLeadersFor($req->to_tribe_id, 'tribes.transfer', $req->user_id),
        )));
    }
}
