<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TribeChangeRequest;
use App\Services\TribeChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Le membre demande un changement de tribu et suit ses demandes. */
class MyTribeChangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = TribeChangeRequest::where('user_id', $request->user()->id)
            ->with('fromTribe', 'toTribe', 'approvals.approver.profile')->latest('id')->limit(10)->get()
            ->map(fn (TribeChangeRequest $r) => [
                'id' => $r->id,
                'from' => $r->fromTribe?->name,
                'to' => $r->toTribe?->name,
                'reason' => $r->reason,
                'status' => $r->status,
                'required_sides' => $r->requiredSides(),
                'approved_sides' => $r->approvedSides(),
                'approvals' => $r->approvals->map(fn ($a) => [
                    'side' => $a->side, 'decision' => $a->decision, 'comment' => $a->comment,
                    'by' => $a->approver?->profile?->full_name, 'at' => $a->decided_at?->toIso8601String(),
                ])->values()->all(),
                'created_at' => $r->created_at->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ]);

        return response()->json(['requests' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_tribe_id' => ['required', 'integer', 'exists:tribes,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        TribeChangeService::request($request->user(), (int) $data['to_tribe_id'], $data['reason']);

        return response()->json(['message' => 'Demande envoyée : les responsables des deux tribus vont l\'examiner.'], 201);
    }

    public function destroy(Request $request, TribeChangeRequest $tribeRequest): JsonResponse
    {
        TribeChangeService::cancel($request->user(), $tribeRequest);

        return response()->json(['message' => 'Demande annulée.']);
    }
}
