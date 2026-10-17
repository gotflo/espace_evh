<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NotificationMail;
use App\Models\MemberDeletionRequest;
use App\Models\User;
use App\Services\MemberDeletionService;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Boutons des courriels envoyes au pasteur (lien signe, nominatif, limite dans le temps) : « Accepter / Rejeter »
 * une demande de suppression, « Valider / Rejeter » une nouvelle inscription. Un appui decide aussitot, puis
 * l'application s'ouvre (Validations) pour confirmer le resultat.
 *
 * Protection contre les antivirus de messagerie qui « ouvrent » les liens pour les analyser : la decision
 * part tout de suite seulement pour un vrai clic de navigateur (en-tete Sec-Fetch-User) ; sinon une page
 * d'un instant envoie la decision par elle-meme (un robot qui ne fait que lire la page ne decide rien).
 * Accepter une SUPPRESSION (definitive) demande toujours un appui sur un bouton : un analyseur qui ouvre
 * le lien dans un vrai navigateur ne peut pas supprimer un membre. Une decision deja prise n'est jamais
 * refaite ; un pasteur bloque ne decide plus rien.
 */
class EmailActionController extends Controller
{
    public function showDeletion(Request $request, MemberDeletionRequest $deletionRequest, string $decision, User $pastor): Response|RedirectResponse
    {
        if ($stop = $this->stop($request, $deletionRequest, $pastor)) {
            return $stop;
        }
        if ($decision === 'approve') {
            return response()->view('email-action', NotificationMail::common() + [
                'title' => 'Supprimer '.($deletionRequest->member_name ?: 'ce membre').' ?',
                'action' => $request->fullUrl(),
                'confirm' => 'Confirmer la suppression définitive',
            ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        }
        if ($request->header('Sec-Fetch-User') === '?1') {
            return $this->decide($deletionRequest, $decision, $pastor);
        }

        return response()->view('email-action', NotificationMail::common() + [
            'title' => 'Refus en cours…',
            'action' => $request->fullUrl(),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function decideDeletion(Request $request, MemberDeletionRequest $deletionRequest, string $decision, User $pastor): RedirectResponse
    {
        return $this->stop($request, $deletionRequest, $pastor) ?? $this->decide($deletionRequest, $decision, $pastor);
    }

    // ---------------------------------------------------------------- Nouvelle inscription

    public function showMembership(Request $request, User $member, string $decision, User $pastor): Response|RedirectResponse
    {
        if ($stop = $this->stopMembership($request, $member, $pastor)) {
            return $stop;
        }
        if ($request->header('Sec-Fetch-User') === '?1') {
            return $this->decideMember($member, $decision, $pastor);
        }

        return response()->view('email-action', NotificationMail::common() + [
            'title' => $decision === 'approve' ? 'Validation en cours…' : 'Refus en cours…',
            'action' => $request->fullUrl(),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function decideMembership(Request $request, User $member, string $decision, User $pastor): RedirectResponse
    {
        return $this->stopMembership($request, $member, $pastor) ?? $this->decideMember($member, $decision, $pastor);
    }

    private function stopMembership(Request $request, User $member, User $pastor): ?RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->toValidations('inscription', $member->id, 'expire');
        }
        if ($pastor->blocked_at || ! MembershipService::canApprove($pastor)) {
            return $this->toValidations('inscription', $member->id, 'refuse');
        }

        return $member->approval_status === User::PENDING ? null : $this->toValidations('inscription', $member->id, 'deja');
    }

    private function decideMember(User $member, string $decision, User $pastor): RedirectResponse
    {
        try {
            MembershipService::decide($pastor, $member, $decision === 'approve');
        } catch (HttpException) {
            return $this->toValidations('inscription', $member->id, 'deja');
        }

        return $this->toValidations('inscription', $member->id, $decision === 'approve' ? 'validee' : 'refusee');
    }

    // ---------------------------------------------------------------- Demande de suppression

    /** Lien expire, pasteur sans ce droit, demande deja traitee : on va a l'application, rien n'est fait. */
    private function stop(Request $request, MemberDeletionRequest $req, User $pastor): ?RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->toApp($req, 'expire');
        }
        if ($pastor->blocked_at || ! $pastor->isSuperAdmin()) {
            return $this->toApp($req, 'refuse');
        }

        return $req->status === 'pending' ? null : $this->toApp($req, 'deja');
    }

    private function decide(MemberDeletionRequest $req, string $decision, User $pastor): RedirectResponse
    {
        try {
            MemberDeletionService::decide($pastor, $req->load('member.profile'), $decision === 'approve', null);
        } catch (HttpException) {
            return $this->toApp($req->fresh(), 'deja');
        }

        return $this->toApp($req, $decision === 'approve' ? 'approuvee' : 'refusee');
    }

    /** Ouvre l'application (Validations) qui confirme le resultat. */
    private function toApp(MemberDeletionRequest $req, string $result): RedirectResponse
    {
        return $this->toValidations('suppression', $req->id, $result);
    }

    private function toValidations(string $kind, int $id, string $result): RedirectResponse
    {
        $url = rtrim((string) config('app.url'), '/')."/admin/validations?{$kind}={$id}&resultat={$result}";

        return redirect()->away($url, 303)->header('Cache-Control', 'no-store');
    }
}
