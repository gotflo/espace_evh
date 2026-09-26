<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FissEditRequest;
use App\Models\SpiritualHealthForm;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Services\FissService;
use App\Services\TribeChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centre de validation des responsables : demandes de modification de FISS et demandes de
 * changement de tribu qu'ILS peuvent traiter (portee et permissions verifiees cote serveur).
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

        return response()->json(['fiss' => $fiss, 'tribes' => $tribes, 'count' => count($fiss) + count($tribes)]);
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
        abort_unless($viewer->canViewMember($user) && ($viewer->hasPermission('fiss.review') || $viewer->hasPermission('audit.view')), 403, 'Accès refusé.');

        $formIds = SpiritualHealthForm::where('user_id', $user->id)->pluck('period', 'id');
        $requestIds = FissEditRequest::where('user_id', $user->id)->pluck('id');
        $logs = AuditLog::with('actor.profile')
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
