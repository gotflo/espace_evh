<?php

namespace App\Services;

use App\Models\FissEditRequest;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\Audit;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fiche de sante spirituelle (FISS) :
 * - enregistree une fois par mois, puis VERROUILLEE ;
 * - pour la modifier, le fidele fait une demande (motif), traitee par son patriarche
 *   (a defaut l'AP de sa tribu, a defaut une autorite pastorale) ;
 * - approbation => la fiche est deverrouillee pour UNE modification (7 jours), puis reverrouillee ;
 * - 2 demandes au maximum par fiche (regle serveur) ;
 * - tout est trace : creation, verrouillage, demandes, decisions, anciennes/nouvelles valeurs.
 */
class FissService
{
    /** Enregistre la fiche du mois (creation) : elle est aussitot verrouillee. */
    public static function create(User $member, array $data): SpiritualHealthForm
    {
        $period = now()->format('Y-m');
        abort_if(SpiritualHealthForm::where('user_id', $member->id)->where('period', $period)->exists(), 409,
            'Votre fiche de ce mois est déjà enregistrée. Pour la modifier, faites une demande de modification.');

        return DB::transaction(function () use ($member, $data, $period) {
            $form = SpiritualHealthForm::create($data + [
                'user_id' => $member->id,
                'period' => $period,
                'submitted_at' => now(),
                'locked_at' => now(),
            ]);
            Audit::log('fiss.created', $form, $member->id, [], self::values($form), ['period' => $period]);
            Audit::log('fiss.locked', $form, $member->id, [], ['locked_at' => $form->locked_at->toIso8601String()]);
            ActivityService::touch($member, true, 'fiss');

            return $form;
        });
    }

    /** Modification d'une fiche deverrouillee par une demande approuvee ; la fiche est reverrouillee. */
    public static function update(User $member, SpiritualHealthForm $form, array $data): SpiritualHealthForm
    {
        abort_unless($form->user_id === $member->id, 404);
        $request = self::openRequest($form);
        abort_unless($request, 423, 'Cette fiche est verrouillée. Faites une demande de modification à votre patriarche.');

        return DB::transaction(function () use ($member, $form, $data, $request) {
            $before = self::values($form);
            $form->fill($data);
            $form->forceFill(['locked_at' => now(), 'edit_count' => $form->edit_count + 1])->save();
            [$old, $new] = Audit::diff($before, self::values($form));
            Audit::log('fiss.modified', $form, $member->id, $old, $new, ['edit_request_id' => $request->id, 'edit_number' => $form->edit_count]);
            Audit::log('fiss.locked', $form, $member->id, [], ['locked_at' => $form->locked_at->toIso8601String()]);
            $request->forceFill(['status' => 'used', 'used_at' => now()])->save();
            ActivityService::touch($member, true, 'fiss');

            return $form;
        });
    }

    /** Le fidele demande a modifier une fiche verrouillee (motif obligatoire, 2 demandes max). */
    public static function requestEdit(User $member, SpiritualHealthForm $form, string $reason): FissEditRequest
    {
        abort_unless($form->user_id === $member->id, 404);
        abort_unless($form->isLocked(), 422, 'Cette fiche est déjà modifiable.');
        abort_if($form->editRequests()->whereIn('status', ['pending'])->exists(), 409, 'Une demande est déjà en attente pour cette fiche.');
        abort_if(self::openRequest($form) !== null, 409, 'Une modification a déjà été approuvée : vous pouvez modifier la fiche.');
        $used = self::requestsCount($form);
        abort_if($used >= FissEditRequest::MAX_PER_FORM, 422,
            'Vous avez déjà fait '.FissEditRequest::MAX_PER_FORM.' demandes de modification pour cette fiche : ce n\'est plus possible.');

        $req = FissEditRequest::create(['form_id' => $form->id, 'user_id' => $member->id, 'reason' => $reason, 'status' => 'pending']);
        Audit::log('fiss.edit_requested', $req, $member->id, [], ['reason' => $reason, 'request_number' => $used + 1], ['form_id' => $form->id, 'period' => $form->period]);

        $name = $member->profile?->full_name ?: $member->phone;
        Notifier::send(self::reviewers($member), 'fiss_request', "Demande de modification de FISS : {$name}",
            self::periodLabel($form->period).' · '.mb_substr($reason, 0, 140), '/admin/validations',
            ['fiss_edit_request_id' => $req->id], 'high');

        return $req;
    }

    /** Decision du patriarche (ou valideur autorise). */
    public static function decide(User $reviewer, FissEditRequest $req, bool $approve, ?string $comment): FissEditRequest
    {
        $member = $req->requester;
        abort_unless($member && self::canReview($reviewer, $member), 403, 'Vous ne pouvez pas traiter cette demande.');
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');

        return DB::transaction(function () use ($reviewer, $req, $approve, $comment, $member) {
            $req->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'unlock_expires_at' => $approve ? now()->addDays(FissEditRequest::UNLOCK_DAYS) : null,
            ])->save();
            Audit::log($approve ? 'fiss.edit_approved' : 'fiss.edit_rejected', $req, $member->id,
                ['status' => 'pending'], ['status' => $req->status, 'comment' => $comment], ['form_id' => $req->form_id]);

            $form = $req->form;
            if ($approve) {
                $form->forceFill(['locked_at' => null])->save();
                Audit::log('fiss.unlocked', $form, $member->id, [], ['until' => $req->unlock_expires_at->toIso8601String()], ['edit_request_id' => $req->id]);
            }

            Notifier::send([$member->id], 'fiss_request',
                $approve ? 'Modification de votre FISS approuvée' : 'Demande de modification de FISS refusée',
                $approve
                    ? 'Vous pouvez modifier votre fiche de '.self::periodLabel($form->period).' pendant '.FissEditRequest::UNLOCK_DAYS.' jours.'
                    : ($comment ?: 'Votre patriarche n\'a pas accepté la demande.'),
                '/ma-fiche', ['fiss_edit_request_id' => $req->id]);

            return $req;
        });
    }

    /** Le fidele annule sa demande en attente. */
    public static function cancel(User $member, FissEditRequest $req): void
    {
        abort_unless($req->user_id === $member->id, 404);
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');
        $req->forceFill(['status' => 'cancelled'])->save();
        Audit::log('fiss.edit_cancelled', $req, $member->id, ['status' => 'pending'], ['status' => 'cancelled']);
    }

    /** Automatisme : fenetre de modification expiree sans modification => fiche reverrouillee. */
    public static function expireOpenRequests(): int
    {
        $count = 0;
        FissEditRequest::where('status', 'approved')->whereNull('used_at')
            ->where('unlock_expires_at', '<', now())->with('form')->get()
            ->each(function (FissEditRequest $req) use (&$count) {
                DB::transaction(function () use ($req) {
                    $req->forceFill(['status' => 'expired'])->save();
                    if ($req->form && ! $req->form->locked_at) {
                        $req->form->forceFill(['locked_at' => now()])->save();
                        Audit::log('fiss.locked', $req->form, $req->user_id, [], ['locked_at' => now()->toIso8601String()], ['reason' => 'expired', 'edit_request_id' => $req->id], null);
                    }
                });
                $count++;
            });

        return $count;
    }

    /** Demande approuvee et encore utilisable pour cette fiche. */
    public static function openRequest(SpiritualHealthForm $form): ?FissEditRequest
    {
        return $form->editRequests()->where('status', 'approved')->whereNull('used_at')
            ->where(fn ($q) => $q->whereNull('unlock_expires_at')->orWhere('unlock_expires_at', '>', now()))
            ->latest('id')->first();
    }

    /** Demandes comptant dans la limite (toutes sauf celles annulees par le fidele). */
    public static function requestsCount(SpiritualHealthForm $form): int
    {
        return $form->editRequests()->where('status', '!=', 'cancelled')->count();
    }

    /** Patriarche(s) du fidele, a defaut AP de la tribu, a defaut autorite pastorale. */
    public static function reviewers(User $member): array
    {
        return Recipients::tribeLeadersFor($member->profile?->tribe_id, 'fiss.review', $member->id);
    }

    public static function canReview(User $reviewer, User $member): bool
    {
        return $reviewer->id !== $member->id && $reviewer->hasPermission('fiss.review') && $reviewer->canManageMember($member);
    }

    /** @return array<string, mixed> */
    public static function values(SpiritualHealthForm $form): array
    {
        return collect(SpiritualHealthForm::FIELDS)->mapWithKeys(fn ($f) => [$f => $form->{$f}])->all();
    }

    public static function periodLabel(string $period): string
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-01')->locale('fr')->isoFormat('MMMM YYYY');
    }
}
