<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedPhone;
use App\Models\MemberDeletionRequest;
use App\Models\User;
use App\Services\MemberDeletionService;
use App\Services\Monitoring\UserAdmin;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Suppression d'un membre (PR), demandes de suppression (patriarche, responsable de departement), numeros bloques. */
class MemberDeletionController extends Controller
{
    /** Ce que le responsable peut faire sur la fiche du membre (et, pour le PR, ce qui disparaitra). */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $canDelete = MemberDeletionService::canDelete($viewer, $user);
        $pending = MemberDeletionRequest::where('user_id', $user->id)->where('status', 'pending')->with('requester.profile')->first();

        return response()->json([
            'can_delete' => $canDelete,
            'can_request' => ! $canDelete && MemberDeletionService::canRequest($viewer, $user),
            'pending_request' => $pending ? [
                'id' => $pending->id,
                'reason' => $pending->reason,
                'by' => $pending->requester?->profile?->full_name,
                'created_at' => $pending->created_at->toIso8601String(),
            ] : null,
            'impact' => $canDelete ? UserAdmin::impact($user) : null,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Indiquez le motif de la suppression.',
        ]);
        $pending = MemberDeletionRequest::where('user_id', $user->id)->where('status', 'pending')->first();
        MemberDeletionService::delete($request->user(), $user, $data['reason'], $pending);

        return response()->json(['message' => 'Membre supprimé. Son numéro ne pourra plus servir à une nouvelle inscription.']);
    }

    public function requestDeletion(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Indiquez pourquoi ce membre devrait être supprimé.',
        ]);
        MemberDeletionService::request($request->user(), $user, $data['reason']);

        return response()->json(['message' => 'Demande envoyée au Pasteur Résident.'], 201);
    }

    public function decide(Request $request, MemberDeletionRequest $deletionRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ]);
        MemberDeletionService::decide($request->user(), $deletionRequest->load('member.profile'), $data['decision'] === 'approved', $data['comment'] ?? null);

        return response()->json(['message' => $data['decision'] === 'approved' ? 'Membre supprimé.' : 'Demande refusée.']);
    }

    /** Etat d'une demande (confirmation affichee apres un appui sur un bouton du courriel) : le PR seul. */
    public function showRequest(Request $request, MemberDeletionRequest $deletionRequest): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Réservé au Pasteur Résident.');
        $by = $deletionRequest->decided_by ? User::with('profile')->find($deletionRequest->decided_by)?->profile?->full_name : null;

        return response()->json([
            'id' => $deletionRequest->id,
            'member_name' => $deletionRequest->member_name,
            'status' => $deletionRequest->status,
            'decided_by' => $by,
            'decided_at' => $deletionRequest->decided_at?->toIso8601String(),
        ]);
    }

    /** Numeros bloques (comptes supprimes) : le PR seul. */
    public function blockedPhones(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Réservé au Pasteur Résident.');

        return response()->json(['phones' => BlockedPhone::latest()->limit(200)->get()->map(fn (BlockedPhone $b) => [
            'id' => $b->id,
            'phone' => $b->phone,
            'member_name' => $b->member_name,
            'reason' => $b->reason,
            'created_at' => $b->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function unblockPhone(Request $request, BlockedPhone $blockedPhone): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Réservé au Pasteur Résident.');
        Audit::log('phone.unblocked', $blockedPhone, null, ['phone' => '…'.substr($blockedPhone->phone, -4), 'name' => $blockedPhone->member_name]);
        $blockedPhone->delete();

        return response()->json(['message' => 'Numéro débloqué : il peut de nouveau servir à une inscription.']);
    }
}
