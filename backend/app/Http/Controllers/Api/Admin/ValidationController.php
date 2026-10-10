<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DepartmentJoinRequest;
use App\Models\FissEditRequest;
use App\Models\MemberDeletionRequest;
use App\Models\SpiritualHealthForm;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\VertumetreEditRequest;
use App\Services\DepartmentJoinService;
use App\Services\FissService;
use App\Services\MembershipService;
use App\Services\TribeChangeService;
use App\Services\VertumetreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centre de validation des responsables (portee et permissions verifiees cote serveur) : modifications
 * de FISS et de Vertumetre, changements de tribu.
 */
class ValidationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $fiss = [];
        if ($user->hasPermission('fiss.review')) {
            $fiss = FissEditRequest::where('status', 'pending')->with('requester.profile.tribe', 'form')->oldest()->get()
                ->filter(fn (FissEditRequest $r) => $r->requester && FissService::canReview($user, $r->requester))
                ->map(fn (FissEditRequest $r) => [
                    'id' => $r->id,
                    'member' => ['user_id' => $r->user_id, 'name' => $r->requester->profile?->full_name ?: $r->requester->phone, 'tribe' => $r->requester->profile?->tribe?->name],
                    'period' => $r->form?->period,
                    'period_label' => $r->form ? FissService::periodLabel($r->form->period) : null,
                    'reason' => $r->reason,
                    'request_number' => FissEditRequest::where('form_id', $r->form_id)->where('status', '!=', 'cancelled')->where('id', '<=', $r->id)->count(),
                    'created_at' => $r->created_at->toIso8601String(),
                ])->values()->all();
        }

        $tribes = [];
        if ($user->hasPermission('tribes.transfer')) {
            $tribes = TribeChangeRequest::where('status', 'pending')->with('member.profile', 'fromTribe', 'toTribe', 'approvals.approver.profile')->oldest()->get()
                ->map(fn (TribeChangeRequest $r) => ['req' => $r, 'sides' => TribeChangeService::sidesFor($user, $r)])
                ->filter(fn ($x) => $x['sides'] !== [])
                ->map(fn ($x) => $this->presentTribe($x['req'], $x['sides']))->values()->all();
        }

        // Vertumetre : memes valideurs que la FISS.
        $vertumetre = [];
        if ($user->hasPermission('fiss.review')) {
            $vertumetre = VertumetreEditRequest::where('status', 'pending')->with('requester.profile.tribe', 'form')->oldest()->get()
                ->filter(fn (VertumetreEditRequest $r) => $r->requester && FissService::canReview($user, $r->requester))
                ->map(fn (VertumetreEditRequest $r) => [
                    'id' => $r->id,
                    'member' => ['user_id' => $r->user_id, 'name' => $r->requester->profile?->full_name ?: $r->requester->phone, 'tribe' => $r->requester->profile?->tribe?->name],
                    'period_label' => $r->form ? FissService::periodLabel($r->form->period) : null,
                    'reason' => $r->reason,
                    'request_number' => VertumetreEditRequest::where('form_id', $r->form_id)->where('status', '!=', 'cancelled')->where('id', '<=', $r->id)->count(),
                    'created_at' => $r->created_at->toIso8601String(),
                ])->values()->all();
        }

        // Demandes de suppression d'un membre (PR).
        $deletions = [];
        if ($user->isSuperAdmin()) {
            $deletions = MemberDeletionRequest::where('status', 'pending')->with('requester.profile', 'member.profile.tribe')->oldest()->get()
                ->map(fn (MemberDeletionRequest $r) => [
                    'id' => $r->id,
                    'member' => ['user_id' => $r->user_id, 'name' => $r->member?->profile?->full_name ?: $r->member_name, 'tribe' => $r->member?->profile?->tribe?->name],
                    'requested_by' => $r->requester?->profile?->full_name,
                    'reason' => $r->reason,
                    'created_at' => $r->created_at->toIso8601String(),
                ])->values()->all();
        }

        // Nouvelles inscriptions (pasteur) : en attente, et refusees ces 30 derniers jours (erreur corrigeable).
        $memberships = [];
        $rejected = [];
        if (MembershipService::canApprove($user)) {
            $memberships = User::where('approval_status', User::PENDING)->whereNotNull('approval_requested_at')
                ->with('profile.tribe', 'profile.departments', 'profile.gem')->oldest('approval_requested_at')->limit(100)->get()
                ->map(fn (User $u) => MembershipService::present($u))->values()->all();
            $rejected = User::where('approval_status', User::REJECTED)->where('approval_decided_at', '>=', now()->subDays(30))
                ->with('profile.tribe', 'profile.departments', 'profile.gem')->latest('approval_decided_at')->limit(30)->get()
                ->map(fn (User $u) => MembershipService::present($u))->values()->all();
        }

        // Inscriptions aux services : le responsable du departement (ou les autorites des departements).
        $services = DepartmentJoinService::pendingFor($user)
            ->map(fn ($r) => DepartmentJoinService::present($r))->values()->all();

        return response()->json([
            'fiss' => $fiss, 'tribes' => $tribes, 'vertumetre' => $vertumetre, 'deletions' => $deletions,
            'memberships' => $memberships, 'memberships_rejected' => $rejected, 'services' => $services,
            'count' => count($fiss) + count($tribes) + count($vertumetre) + count($deletions) + count($memberships) + count($services),
        ]);
    }

    /** Valider ou refuser une inscription a un service (responsable du departement). */
    public function decideService(Request $request, DepartmentJoinRequest $joinRequest): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        DepartmentJoinService::decide($request->user(), $joinRequest, $data['decision'] === 'approved');

        return response()->json(['message' => $data['decision'] === 'approved' ? 'Inscription validée.' : 'Demande refusée.']);
    }

    /** Valider ou rejeter une nouvelle inscription (un compte rejete peut encore etre valide). */
    public function decideMembership(Request $request, User $member): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        MembershipService::decide($request->user(), $member, $data['decision'] === 'approved');

        return response()->json(['message' => $data['decision'] === 'approved'
            ? 'Compte validé : la personne est prévenue.' : 'Inscription refusée.']);
    }

    /** Une inscription (bandeau de confirmation apres un bouton du courriel). */
    public function showMembership(Request $request, User $member): JsonResponse
    {
        abort_unless(MembershipService::canApprove($request->user()), 403, 'Réservé au pasteur.');
        abort_if($member->isApproved() && ! $member->approval_decided_at, 404, 'Aucune inscription à valider pour ce compte.');
        $member->load('profile.tribe', 'profile.departments', 'profile.gem', 'profile');
        $decider = $member->approval_decided_by ? User::with('profile')->find($member->approval_decided_by) : null;

        return response()->json(MembershipService::present($member) + [
            'decided_by' => $decider?->profile?->full_name,
        ]);
    }

    public function decideVertumetre(Request $request, VertumetreEditRequest $editRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ]);
        VertumetreService::decide($request->user(), $editRequest->load('requester', 'form'), $data['decision'] === 'approved', $data['comment'] ?? null);

        return response()->json(['message' => $data['decision'] === 'approved'
            ? 'Demande approuvée : le Vertumètre est déverrouillé pour une modification.' : 'Demande refusée.']);
    }

    public function decideFiss(Request $request, FissEditRequest $editRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ]);
        FissService::decide($request->user(), $editRequest->load('requester', 'form'), $data['decision'] === 'approved', $data['comment'] ?? null);

        return response()->json(['message' => $data['decision'] === 'approved'
            ? 'Demande approuvée : la fiche est déverrouillée pour une modification.' : 'Demande refusée.']);
    }

    public function decideTribe(Request $request, TribeChangeRequest $tribeRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ]);
        $req = TribeChangeService::decide($request->user(), $tribeRequest, $data['decision'] === 'approved', $data['comment'] ?? null);

        return response()->json(['message' => match (true) {
            $req->status === 'approved' => 'Changement de tribu effectué.',
            $req->status === 'rejected' => 'Demande refusée.',
            default => "Approbation enregistrée : en attente de l'autre tribu.",
        }]);
    }

    /** Tracabilite des FISS d'un membre (creation, verrouillage, demandes, decisions, valeurs avant/apres). */
    public function fissHistory(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user) && $viewer->canViewFissOf($user)
            && ($viewer->hasPermission('fiss.review') || $viewer->hasPermission('audit.view')), 403, 'Accès refusé.');

        $formIds = SpiritualHealthForm::where('user_id', $user->id)->pluck('period', 'id');
        $requestIds = FissEditRequest::where('user_id', $user->id)->pluck('id');
        $logs = AuditLog::ordinary()->with('actor.profile')
            ->where(fn ($q) => $q->where(fn ($f) => $f->where('subject_type', 'spiritual_health_form')->whereIn('subject_id', $formIds->keys()))
                ->orWhere(fn ($r) => $r->where('subject_type', 'fiss_edit_request')->whereIn('subject_id', $requestIds)))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'label' => AuditLogController::LABELS[$l->action] ?? $l->action,
                'actor' => $l->actor?->profile?->full_name ?: ($l->user_id ? 'Utilisateur #'.$l->user_id : 'Système'),
                'period' => $l->subject_type === 'spiritual_health_form' ? ($formIds[$l->subject_id] ?? null) : null,
                'old' => $l->old_values,
                'new' => $l->new_values,
                'context' => $l->context,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['history' => $logs]);
    }

    /** @return array<string, mixed> */
    private function presentTribe(TribeChangeRequest $r, array $sides): array
    {
        return [
            'id' => $r->id,
            'member' => ['user_id' => $r->user_id, 'name' => $r->member?->profile?->full_name ?: $r->member?->phone],
            'from' => $r->fromTribe?->name,
            'to' => $r->toTribe?->name,
            'reason' => $r->reason,
            'my_sides' => $sides,
            'required_sides' => $r->requiredSides(),
            'approvals' => $r->approvals->map(fn ($a) => [
                'side' => $a->side, 'decision' => $a->decision, 'comment' => $a->comment,
                'by' => $a->approver?->profile?->full_name, 'at' => $a->decided_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
