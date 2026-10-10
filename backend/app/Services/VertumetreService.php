<?php

namespace App\Services;

use App\Models\User;
use App\Models\VertumetreEditRequest;
use App\Models\VertumetreForm;
use App\Support\Audit;
use App\Support\French;
use App\Support\VertumetreCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Vertumetre mensuel, meme logique que la FISS (FissService) :
 * - une reponse par mois, pour le mois EN COURS uniquement (un mois passe ne se rattrape pas),
 *   VERROUILLEE des l'envoi ;
 * - pour la modifier : demande motivee au patriarche (a defaut l'AP, a defaut un pasteur), 2 au plus ;
 *   approuvee, elle deverrouille la reponse pour UNE modification pendant 7 jours, puis tout se reverrouille ;
 * - tout est trace dans le journal d'audit.
 */
class VertumetreService
{
    /** Mois qu'on peut remplir : le mois en cours seulement (un mois passe ne se rattrape pas). @return array<int, string> */
    public static function fillablePeriods(): array
    {
        return [now()->format('Y-m')];
    }

    public static function create(User $member, string $period, array $answers): VertumetreForm
    {
        abort_unless(in_array($period, self::fillablePeriods(), true), 422, 'Le Vertumètre se remplit pour le mois en cours seulement.');
        $clean = VertumetreCatalog::validate($answers);

        return DB::transaction(function () use ($member, $period, $clean) {
            User::whereKey($member->id)->lockForUpdate()->first();
            abort_if(VertumetreForm::where('user_id', $member->id)->where('period', $period)->exists(), 409,
                'Votre Vertumètre de ce mois est déjà enregistré. Pour le modifier, faites une demande de modification.');

            $form = VertumetreForm::create([
                'user_id' => $member->id, 'period' => $period, 'answers' => $clean,
                'submitted_at' => now(), 'locked_at' => now(),
            ]);
            Audit::log('vertumetre.created', $form, $member->id, [], ['period' => $period], ['period' => $period]);
            Audit::log('vertumetre.locked', $form, $member->id, [], ['locked_at' => $form->locked_at->toIso8601String()]);
            ActivityService::touch($member, true, 'vertumetre');

            return $form;
        });
    }

    public static function update(User $member, VertumetreForm $form, array $answers): VertumetreForm
    {
        abort_unless($form->user_id === $member->id, 404);
        $request = self::openRequest($form);
        abort_unless($request, 423, 'Ce Vertumètre est verrouillé. Faites une demande de modification à votre patriarche.');
        $clean = VertumetreCatalog::validate($answers);

        return DB::transaction(function () use ($member, $form, $clean, $request) {
            [$old, $new] = Audit::diff($form->answers ?? [], $clean);
            $form->forceFill(['answers' => $clean, 'locked_at' => now(), 'edit_count' => $form->edit_count + 1])->save();
            Audit::log('vertumetre.modified', $form, $member->id, $old, $new, ['edit_request_id' => $request->id, 'edit_number' => $form->edit_count]);
            Audit::log('vertumetre.locked', $form, $member->id, [], ['locked_at' => $form->locked_at->toIso8601String()]);
            $request->forceFill(['status' => 'used', 'used_at' => now()])->save();
            ActivityService::touch($member, true, 'vertumetre');

            return $form;
        });
    }

    public static function requestEdit(User $member, VertumetreForm $form, string $reason): VertumetreEditRequest
    {
        abort_unless($form->user_id === $member->id, 404);
        [$req] = DB::transaction(function () use ($member, $form, $reason) {
            $form = VertumetreForm::whereKey($form->id)->lockForUpdate()->firstOrFail();
            abort_unless($form->isLocked(), 422, 'Ce Vertumètre est déjà modifiable.');
            abort_if($form->editRequests()->where('status', 'pending')->exists(), 409, 'Une demande est déjà en attente pour ce mois.');
            abort_if(self::openRequest($form) !== null, 409, 'Une modification a déjà été approuvée : vous pouvez modifier votre Vertumètre.');
            $used = self::requestsCount($form);
            abort_if($used >= VertumetreEditRequest::MAX_PER_FORM, 422,
                'Vous avez déjà fait '.VertumetreEditRequest::MAX_PER_FORM.' demandes de modification pour ce mois : ce n\'est plus possible.');
            $req = VertumetreEditRequest::create(['form_id' => $form->id, 'user_id' => $member->id, 'reason' => $reason, 'status' => 'pending']);
            Audit::log('vertumetre.edit_requested', $req, $member->id, [], ['reason' => $reason, 'request_number' => $used + 1], ['form_id' => $form->id, 'period' => $form->period]);

            return [$req];
        });

        $name = $member->profile?->full_name ?: $member->phone;
        Notifier::send(FissService::reviewers($member), 'fiss_request', "Demande de modification du Vertumètre : {$name}",
            FissService::periodLabel($form->period).' · '.mb_substr($reason, 0, 140), '/admin/validations',
            ['vertumetre_edit_request_id' => $req->id], 'high');

        return $req;
    }

    public static function decide(User $reviewer, VertumetreEditRequest $req, bool $approve, ?string $comment): void
    {
        $member = $req->requester;
        abort_unless($member && FissService::canReview($reviewer, $member), 403, 'Vous ne pouvez pas traiter cette demande.');
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');

        DB::transaction(function () use ($reviewer, $req, $approve, $comment, $member) {
            $req->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'unlock_expires_at' => $approve ? now()->addDays(VertumetreEditRequest::UNLOCK_DAYS) : null,
            ])->save();
            Audit::log($approve ? 'vertumetre.edit_approved' : 'vertumetre.edit_rejected', $req, $member->id,
                ['status' => 'pending'], ['status' => $req->status, 'comment' => $comment], ['form_id' => $req->form_id]);
            $form = $req->form;
            if ($approve) {
                $form->forceFill(['locked_at' => null])->save();
                Audit::log('vertumetre.unlocked', $form, $member->id, [], ['until' => $req->unlock_expires_at->toIso8601String()], ['edit_request_id' => $req->id]);
            }
            Notifier::send([$member->id], 'fiss_request',
                $approve ? 'Modification de votre Vertumètre approuvée' : 'Demande de modification du Vertumètre refusée',
                $approve
                    ? 'Vous pouvez modifier votre Vertumètre '.French::de(FissService::periodLabel($form->period)).' pendant '.VertumetreEditRequest::UNLOCK_DAYS.' jours.'
                    : ($comment ?: 'Votre patriarche n\'a pas accepté la demande.'),
                '/vertumetre?mois='.$form->period, ['vertumetre_edit_request_id' => $req->id]);
        });
    }

    public static function cancel(User $member, VertumetreEditRequest $req): void
    {
        abort_unless($req->user_id === $member->id, 404);
        abort_unless($req->status === 'pending', 409, 'Cette demande a déjà été traitée.');
        $req->forceFill(['status' => 'cancelled'])->save();
        Audit::log('vertumetre.edit_cancelled', $req, $member->id, ['status' => 'pending'], ['status' => 'cancelled']);
    }

    /** Automatisme : fenetre de modification expiree sans modification => reponse reverrouillee. */
    public static function expireOpenRequests(): int
    {
        $count = 0;
        VertumetreEditRequest::where('status', 'approved')->whereNull('used_at')->where('unlock_expires_at', '<', now())->with('form')->get()
            ->each(function (VertumetreEditRequest $req) use (&$count) {
                DB::transaction(function () use ($req) {
                    $req->forceFill(['status' => 'expired'])->save();
                    if ($req->form && ! $req->form->locked_at) {
                        $req->form->forceFill(['locked_at' => now()])->save();
                        Audit::log('vertumetre.locked', $req->form, $req->user_id, [], ['locked_at' => now()->toIso8601String()], ['reason' => 'expired', 'edit_request_id' => $req->id], null);
                    }
                });
                $count++;
            });

        return $count;
    }

    public static function openRequest(VertumetreForm $form): ?VertumetreEditRequest
    {
        return $form->editRequests()->where('status', 'approved')->whereNull('used_at')
            ->where(fn ($q) => $q->whereNull('unlock_expires_at')->orWhere('unlock_expires_at', '>', now()))
            ->latest('id')->first();
    }

    public static function requestsCount(VertumetreForm $form): int
    {
        return $form->editRequests()->where('status', '!=', 'cancelled')->count();
    }

    /** @return array<string, mixed> */
    public static function present(VertumetreForm $f, bool $withRequests = true): array
    {
        $out = [
            'id' => $f->id,
            'period' => $f->period,
            'period_label' => FissService::periodLabel($f->period),
            'answers' => $f->answers ?? [],
            'submitted_at' => $f->submitted_at?->toIso8601String(),
            'locked' => $f->isLocked(),
            'edit_count' => (int) $f->edit_count,
        ];
        if ($withRequests) {
            $requests = $f->editRequests;
            $open = self::openRequest($f);
            $pending = $requests->firstWhere('status', 'pending');
            $used = $requests->where('status', '!=', 'cancelled')->count();
            $last = $requests->whereIn('status', ['approved', 'rejected', 'used', 'expired'])->sortByDesc('decided_at')->first();
            $out += [
                'editable' => $open !== null,
                'can_edit_until' => $open?->unlock_expires_at?->toIso8601String(),
                'requests_left' => max(0, VertumetreEditRequest::MAX_PER_FORM - $used),
                'pending_request' => $pending ? ['id' => $pending->id, 'reason' => $pending->reason, 'created_at' => $pending->created_at->toIso8601String()] : null,
                'last_decision' => $last ? ['status' => $last->status, 'comment' => $last->decision_comment] : null,
            ];
        }

        return $out;
    }
}
