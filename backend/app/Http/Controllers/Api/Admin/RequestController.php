<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\MyRequestController;
use App\Models\MemberRequest;
use App\Services\Notifier;
use App\Support\MemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestController extends Controller
{
    /** Demandes recues, limitees a la portee du responsable (son GEM / sa tribu / son dept). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scoped = MemberScope::isScoped($user);
        $visibleIds = $scoped ? MemberScope::manageableUserIds($user) : null;

        $base = MemberRequest::query();
        if ($scoped) {
            $base->whereIn('user_id', $visibleIds ?: [0]);
        }

        $items = (clone $base)->with('sender.profile', 'handler.profile', 'replier.profile')
            ->orderByRaw("CASE status WHEN 'nouvelle' THEN 0 WHEN 'en_cours' THEN 1 ELSE 2 END")
            ->latest()->get()
            ->map(fn (MemberRequest $r) => [
                'id' => $r->id,
                'category' => $r->category,
                'category_label' => MyRequestController::CATEGORIES[$r->category] ?? $r->category,
                'subject' => $r->subject,
                'message' => $r->message,
                'status' => $r->status,
                'status_label' => MyRequestController::STATUS[$r->status] ?? $r->status,
                'reply' => $r->reply,
                'replied_by' => $r->replier?->profile?->full_name ?: null,
                'replied_at' => $r->replied_at?->toDateString(),
                'sender' => $r->sender?->profile?->full_name ?: ($r->sender?->phone ?? 'Inconnu'),
                'sender_phone' => $r->sender?->phone,
                'handler' => $r->handler?->profile?->full_name ?: null,
                'created_at' => $r->created_at->toDateString(),
            ]);

        return response()->json([
            'requests' => $items,
            'new_count' => (clone $base)->where('status', 'nouvelle')->count(),
        ]);
    }

    /** Repondre a une demande (le responsable ecrit une reponse au fidele). */
    public function reply(Request $request, MemberRequest $memberRequest): JsonResponse
    {
        abort_unless($request->user()->canManageMember($memberRequest->sender), 403, 'Hors de votre portée.');
        $data = $request->validate(['reply' => ['required', 'string', 'max:3000']]);

        $memberRequest->update([
            'reply' => $data['reply'],
            'replied_by' => $request->user()->id,
            'replied_at' => now(),
            'status' => $memberRequest->status === 'nouvelle' ? 'en_cours' : $memberRequest->status,
            'handled_by' => $memberRequest->handled_by ?? $request->user()->id,
        ]);

        Notifier::send([$memberRequest->user_id], 'request_reply', 'Réponse à votre demande',
            mb_substr($data['reply'], 0, 200), '/contact', ['request_id' => $memberRequest->id]);

        return response()->json(['message' => 'Réponse envoyée.']);
    }

    /** Compteur de demandes nouvelles (pour un badge), limite a la portee. */
    public function newCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = MemberRequest::where('status', 'nouvelle');
        if (MemberScope::isScoped($user)) {
            $q->whereIn('user_id', MemberScope::manageableUserIds($user) ?: [0]);
        }

        return response()->json(['count' => $q->count()]);
    }

    /** Changer le statut d'une demande. */
    public function updateStatus(Request $request, MemberRequest $memberRequest): JsonResponse
    {
        abort_unless($request->user()->canManageMember($memberRequest->sender), 403, 'Hors de votre portée.');
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(MyRequestController::STATUS))],
        ]);

        $previous = $memberRequest->status;
        $memberRequest->update([
            'status' => $data['status'],
            'handled_by' => $data['status'] === 'nouvelle' ? null : $request->user()->id,
            'handled_at' => $data['status'] === 'traitee' ? now() : null,
        ]);

        if ($data['status'] === 'traitee' && $previous !== 'traitee') {
            Notifier::send([$memberRequest->user_id], 'request_reply', 'Votre demande a été traitée',
                $memberRequest->subject ?: mb_substr($memberRequest->message, 0, 120), '/contact', ['request_id' => $memberRequest->id]);
        }

        return response()->json(['message' => 'Statut mis à jour.']);
    }
}
