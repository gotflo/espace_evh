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
        // Verrou sur le membre : une seule demande en cours, meme en cas d'envois simultanes.
        $req = DB::transaction(function () use ($member, $profile, $toTribeId, $reason) {
            User::whereKey($member->id)->lockForUpdate()->first();
            abort_if(TribeChangeRequest::where('user_id', $member->id)->where('status', 'pending')->exists(), 409,
                'Une demande de changement de tribu est déjà en cours.');

            return TribeChangeRequest::create([
                'user_id' => $member->id,
                'from_tribe_id' => $profile->tribe_id,
                'to_tribe_id' => $toTribeId,
                'reason' => $reason,
                'status' => 'pending',
            ]);
        });
        $req->load('fromTribe', 'toTribe');
        Audit::log('tribe_change.requested', $req, $member->id, ['tribe_id' => $profile->tribe_id], ['tribe_id' => $toTribeId], ['reason' => $reason]);

        $name = $profile->full_name ?: $member->phone;
        $from = $req->fromTribe?->name ?? 'aucune';
        $approvers = self::approvers($req);
        Notifier::send($approvers, 'tribe_change', "Changement de tribu demandé : {$name}",
            "De {$from} vers {$req->toTribe->name}".($reason ? ' · '.mb_substr($reason, 0, 120) : ''),
            '/admin/validations#tribus', ['tribe_change_request_id' => $req->id], 'high');
        // Aussi par courriel : la cloche ne se voit qu'en ouvrant l'application, et le push n'arrive que sur
        // les telephones abonnes (application installee, notifications acceptees).
        self::mailApprovers($req);
        // En meme temps, les patriarches des deux tribus sont informes (s'ils ne valident pas eux-memes).
        self::informPatriarchs($req, 'requested', $approvers);

        return $req;
    }

    /** Cotes pour lesquels cet utilisateur peut se prononcer. */
    public static function sidesFor(User $user, TribeChangeRequest $req): array
    {
        if ($user->id === $req->user_id || ! $user->hasPermission('tribes.transfer') || $req->status !== 'pending') {
            return [];
        }
        if ($user->isChurchWideFor('tribes.transfer')) {
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
        // Verrou sur la demande : deux responsables qui valident au meme instant (ou un double
        // clic) sont traites l'un apres l'autre ; le second voit la decision du premier. Sans ce
        // verrou, chacun ne voyait que sa propre approbation et le changement n'etait jamais applique.
        return DB::transaction(function () use ($approver, $req, $approve, $comment) {
            $req = TribeChangeRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            $req->load('approvals');
            $sides = self::sidesFor($approver, $req);
            if (! $sides) {
                abort_if($req->status !== 'pending', 409, 'Cette demande a déjà été traitée.');
                abort_if($req->approvals->contains('approver_id', $approver->id), 409, 'Votre décision est déjà enregistrée.');
                abort(403, 'Vous ne pouvez pas (ou plus) vous prononcer sur cette demande.');
            }

            foreach ($sides as $side) {
                TribeChangeApproval::create([
                    'request_id' => $req->id, 'side' => $side, 'approver_id' => $approver->id,
                    'decision' => $approve ? 'approved' : 'rejected', 'comment' => $comment, 'decided_at' => now(),
                ]);
            }
            Audit::log($approve ? 'tribe_change.approved' : 'tribe_change.rejected', $req, $req->user_id, [],
                ['sides' => $sides, 'comment' => $comment]);
            $req->load('approvals', 'fromTribe', 'toTribe', 'member.profile');

            $by = $approver->loadMissing('profile')->profile?->full_name;
            if (! $approve) {
                $req->forceFill(['status' => 'rejected', 'completed_at' => now()])->save();
                Notifier::send([$req->user_id], 'tribe_change', 'Changement de tribu refusé',
                    $comment ?: "Votre demande pour rejoindre la tribu {$req->toTribe->name} n'a pas été acceptée.", '/mon-profil');
                self::mailMember($req, 'rejected', $by, $comment);
                self::informPatriarchs($req, 'rejected', [$approver->id], $by);

                return $req;
            }

            $missing = array_values(array_diff($req->requiredSides(), $req->approvedSides()));
            if (! $missing) {
                self::apply($req, $by);

                return $req;
            }

            // Une etape validee : le fidele le sait aussitot (application et courriel), et sait ce qu'il reste.
            $validated = self::sidesLabel($req, $sides);
            $waiting = self::sideTribeName($req, $missing[0]);
            Notifier::send([$req->user_id], 'tribe_change', 'Changement de tribu : une étape validée',
                ($by ? "{$by} a validé votre demande" : 'Votre demande a été validée').($validated ? " pour la tribu {$validated}" : '')
                .". Il reste la validation des responsables de la tribu {$waiting}.", '/mon-profil');
            self::mailMember($req, 'step', $by, $comment, $validated, $waiting);

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

    /**
     * Rattrapage (automatismes) : demandes restees « en attente » alors que tous les cotes ont
     * approuve, cas produit par deux validations simultanees avant le verrou de decide().
     */
    public static function completeApproved(): int
    {
        $done = 0;
        foreach (TribeChangeRequest::where('status', 'pending')->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$done) {
                $req = TribeChangeRequest::whereKey($id)->lockForUpdate()->first();
                if (! $req || $req->status !== 'pending') {
                    return;
                }
                $req->load('approvals', 'fromTribe', 'toTribe', 'member.profile');
                if ($req->approvals->contains('decision', 'rejected') || array_diff($req->requiredSides(), $req->approvedSides())) {
                    return;
                }
                self::apply($req);
                $done++;
            });
        }

        return $done;
    }

    /** Toutes les validations obtenues : le membre change de tribu. */
    private static function apply(TribeChangeRequest $req, ?string $by = null): void
    {
        $profile = $req->member->profile;
        $old = $profile->tribe_id;
        $profile->forceFill(['tribe_id' => $req->to_tribe_id])->save();
        GemRules::afterTribeChange($profile, auth()->id() ?? $req->user_id);
        $req->forceFill(['status' => 'approved', 'completed_at' => now()])->save();
        Audit::log('tribe.changed', $profile, $req->user_id, ['tribe_id' => $old], ['tribe_id' => $req->to_tribe_id], ['tribe_change_request_id' => $req->id]);

        Notifier::send([$req->user_id], 'tribe_change', "Bienvenue dans la tribu {$req->toTribe->name} !",
            'Votre changement de tribu a été validé.', '/mon-profil');
        self::mailMember($req, 'approved', $by);
        $name = $profile->full_name;
        $welcomed = array_values(array_diff(Recipients::tribeLeadersFor($req->to_tribe_id, 'members.view_scope'), [$req->user_id]));
        Notifier::send($welcomed, 'member', "Nouveau membre dans la tribu : {$name}",
            $req->fromTribe?->name ? "Arrivé(e) de la tribu {$req->fromTribe->name}." : 'Changement de tribu validé.', '/admin/membres/'.$req->user_id);
        // Patriarches des deux tribus : informes du changement (notification, sauf s'ils viennent de la recevoir, et courriel).
        self::informPatriarchs($req, 'approved', array_filter([auth()->id()]), $by, $welcomed);
    }

    /**
     * Patriarches de l'ancienne et de la nouvelle tribu, informes en meme temps que les valideurs : demande faite,
     * changement valide ou refuse (notification et courriel, preferences de courriel respectees). Ceux qui valident
     * eux-memes ($exclude) recoivent deja les messages de validation. Une seule fois par patriarche et par etape.
     *
     * @param  array<int>  $exclude  personnes qui n'ont rien a recevoir ici (valideurs, auteur de la decision)
     * @param  array<int>  $alreadyNotified  personnes deja notifiees de la meme chose (courriel seulement)
     */
    public static function informPatriarchs(TribeChangeRequest $req, string $outcome, array $exclude = [], ?string $by = null, array $alreadyNotified = []): int
    {
        $req->loadMissing('fromTribe', 'toTribe');
        $member = User::with('profile')->find($req->user_id);
        if (! $member) {
            return 0;
        }
        // Patriarche => cote (« from » : son ancienne tribu, « to » : la nouvelle).
        $sides = [];
        foreach (['from' => $req->from_tribe_id, 'to' => $req->to_tribe_id] as $side => $tribeId) {
            foreach ($tribeId ? Recipients::tribeRoleHolders((int) $tribeId, ['patriarche']) : [] as $id) {
                $sides[$id] ??= $side;
            }
        }
        foreach (array_merge([$req->user_id], $exclude) as $id) {
            unset($sides[(int) $id]);
        }
        if (! $sides) {
            return 0;
        }

        $name = $member->profile?->full_name ?: (string) $member->phone;
        $from = $req->fromTribe?->name;
        $to = (string) $req->toTribe?->name;
        $deciders = $outcome === 'requested'
            ? User::with('profile')->whereIn('id', self::approvers($req))->get()->map(fn (User $u) => $u->profile?->full_name)->filter()->take(3)->implode(', ')
            : '';
        [$title, $body] = match ($outcome) {
            'approved' => ["Changement de tribu validé : {$name}", ($from ? "De la tribu {$from} vers la tribu {$to}" : "Vers la tribu {$to}").($by ? " (validé par {$by})." : '.')],
            'rejected' => ["Changement de tribu refusé : {$name}", "Sa demande pour rejoindre la tribu {$to} n'a pas été acceptée".($by ? " ({$by})." : '.')],
            default => ["Changement de tribu demandé : {$name}", ($from ? "De la tribu {$from} vers la tribu {$to}." : "Vers la tribu {$to}.")
                .' Pour information : '.($deciders ? "la décision revient à {$deciders}." : 'la décision revient aux responsables.')],
        };
        // La fiche du membre s'ouvre pour le patriarche de la tribu ou il se trouve (l'ancienne tant que rien n'est valide).
        $memberSide = $outcome === 'approved' ? 'to' : 'from';
        $fiche = '/admin/membres/'.$member->id;
        foreach (['from', 'to'] as $side) {
            $ids = array_values(array_diff(array_keys(array_filter($sides, fn ($s) => $s === $side)), $alreadyNotified));
            Notifier::send($ids, 'tribe_change', $title, $body, $side === $memberSide ? $fiche : null, ['tribe_change_request_id' => $req->id]);
        }

        $queued = 0;
        $app = rtrim((string) config('app.url'), '/');
        foreach (User::with('profile')->whereIn('id', array_keys($sides))->get() as $patriarch) {
            $email = Messenger::emailOf($patriarch);
            if (! $email || ! Messenger::wants($patriarch, 'email', 'followup')) {
                continue;
            }
            $side = $sides[$patriarch->id];
            $queued += Messenger::queueEmail($email, $patriarch->id, 'tribe_change_info', $title, 'tribe-change-info', [
                'outcome' => $outcome,
                'firstName' => $patriarch->profile?->first_name ?: '',
                'memberName' => $name,
                'from' => $from,
                'to' => $to,
                'mine' => $side === 'from' ? $from : $to,
                'side' => $side,
                'reason' => $outcome === 'requested' ? $req->reason : null,
                'deciders' => $deciders ?: null,
                'by' => $by,
                'url' => $side === $memberSide ? $app.$fiche : null,
            ], "tribe-change-info:{$req->id}:{$outcome}:{$patriarch->id}") ? 1 : 0;
        }

        return $queued;
    }

    /**
     * Courriel aux valideurs encore attendus (cotes pas encore approuves) : le membre, les deux tribus, le motif
     * et un bouton vers la validation. Une seule fois par demande et par valideur (cle anti-doublon) : les
     * automatismes peuvent repasser (demande deja en attente, patriarche nomme depuis) sans rien renvoyer.
     */
    public static function mailApprovers(TribeChangeRequest $req): int
    {
        $req->loadMissing('fromTribe', 'toTribe', 'member.profile', 'approvals');
        $member = $req->member;
        if (! $member || $req->status !== 'pending') {
            return 0;
        }
        $approved = $req->approvedSides();
        $ids = [];
        if ($req->from_tribe_id && ! in_array('from', $approved, true)) {
            $ids = Recipients::tribeLeadersFor($req->from_tribe_id, 'tribes.transfer', $req->user_id);
        }
        if (! in_array('to', $approved, true)) {
            $ids = array_merge($ids, Recipients::tribeLeadersFor($req->to_tribe_id, 'tribes.transfer', $req->user_id));
        }
        $decided = $req->approvals->pluck('approver_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff(array_unique($ids), $decided));
        if (! $ids) {
            return 0;
        }
        $name = $member->profile?->full_name ?: (string) $member->phone;
        $queued = 0;
        foreach (User::with('profile')->whereIn('id', $ids)->get() as $leader) {
            $email = Messenger::emailOf($leader);
            if (! $email || ! Messenger::wants($leader, 'email', null)) {
                continue;
            }
            $queued += Messenger::queueEmail($email, $leader->id, 'tribe_change_request', "Changement de tribu à valider : {$name}",
                'tribe-change-request', [
                    'firstName' => $leader->profile?->first_name ?: '',
                    'memberName' => $name,
                    'from' => $req->fromTribe?->name,
                    'to' => $req->toTribe?->name,
                    'reason' => $req->reason,
                    'requestedOn' => $req->created_at->copy()->locale('fr')->isoFormat('dddd D MMMM [à] H[h]mm'),
                    'url' => rtrim((string) config('app.url'), '/').'/admin/validations#tribus',
                ], "tribe-change-request:{$req->id}:{$leader->id}") ? 1 : 0;
        }

        return $queued;
    }

    /**
     * Automatismes : courriel aux valideurs des demandes en attente (30 derniers jours) qui ne l'ont pas encore
     * recu (demande faite avant les courriels, patriarche nomme depuis). La cle anti-doublon evite tout renvoi.
     */
    public static function mailPendingApprovers(): int
    {
        $queued = 0;
        $pending = TribeChangeRequest::where('status', 'pending')->where('created_at', '>=', now()->subDays(30))
            ->with('fromTribe', 'toTribe', 'member.profile', 'approvals')->get();
        foreach ($pending as $req) {
            $queued += self::mailApprovers($req);
        }

        return $queued;
    }

    /**
     * Courriel au fidele a chaque decision : etape validee (et ce qu'il reste), changement valide, ou refus.
     * Courriel si son adresse est remplie, sinon texto (si les textos sont configures). Une fois par decision.
     */
    private static function mailMember(TribeChangeRequest $req, string $outcome, ?string $by, ?string $comment = null,
        ?string $validated = null, ?string $waiting = null): void
    {
        $member = User::with('profile')->find($req->user_id);
        if (! $member) {
            return;
        }
        $to = (string) $req->toTribe?->name;
        $url = rtrim((string) config('app.url'), '/').'/mon-profil';
        [$kind, $subject, $sms] = match ($outcome) {
            'approved' => ['tribe_change_approved', "Bienvenue dans la tribu {$to}",
                "My vasesdhonneur : votre changement de tribu est validé. Bienvenue dans la tribu {$to} !"],
            'rejected' => ['tribe_change_rejected', 'Votre demande de changement de tribu',
                "My vasesdhonneur : votre demande pour rejoindre la tribu {$to} n'a pas été acceptée."],
            default => ['tribe_change_step', 'Changement de tribu : une étape validée',
                "My vasesdhonneur : une étape de votre changement de tribu est validée. Il reste la validation de la tribu {$waiting}."],
        };
        Messenger::toMember($member, $kind, "tribe-change-{$outcome}:{$req->id}", [
            'subject' => $subject,
            'view' => 'tribe-change-decision',
            'data' => [
                'outcome' => $outcome,
                'firstName' => $member->profile?->first_name ?: '',
                'from' => $req->fromTribe?->name,
                'to' => $to,
                'by' => $by,
                'comment' => $comment,
                'validated' => $validated,
                'waiting' => $waiting,
                'url' => $url,
            ],
        ], $sms.' '.$url);
    }

    /** Nom de la tribu d'un cote (« from » : l'ancienne, « to » : la nouvelle). */
    private static function sideTribeName(TribeChangeRequest $req, string $side): string
    {
        return (string) ($side === 'from' ? $req->fromTribe?->name : $req->toTribe?->name);
    }

    /** Tribus pour lesquelles une decision vient d'etre prise (null pour « both » : les deux d'un coup). */
    private static function sidesLabel(TribeChangeRequest $req, array $sides): ?string
    {
        if (in_array('both', $sides, true)) {
            return null;
        }
        $names = array_values(array_filter(array_map(fn (string $s) => self::sideTribeName($req, $s), $sides)));

        return $names ? implode(' et ', $names) : null;
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
