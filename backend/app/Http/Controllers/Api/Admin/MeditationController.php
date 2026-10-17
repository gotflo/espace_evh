<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeditationSheet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fiches de meditation d'un membre, en lecture seule : patriarche de sa tribu et pasteurs (PR, PA). */
class MeditationController extends Controller
{
    public function index(Request $request, User $user): JsonResponse
    {
        $this->authorizeFor($request->user(), $user);
        $sheets = MeditationSheet::where('user_id', $user->id)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->get(['id', 'submitted_at']);

        return response()->json(['sheets' => $sheets->map(fn (MeditationSheet $s) => $s->summary())->values()]);
    }

    public function show(Request $request, User $user, MeditationSheet $sheet): JsonResponse
    {
        $this->authorizeFor($request->user(), $user);
        abort_unless((int) $sheet->user_id === (int) $user->id, 404, 'Fiche introuvable.');

        return response()->json(['sheet' => $sheet->present()]);
    }

    private function authorizeFor(User $viewer, User $member): void
    {
        abort_unless($viewer->canViewMember($member) && $viewer->canViewMeditationsOf($member), 403,
            'Les fiches de méditation sont réservées au patriarche de la tribu et aux pasteurs.');
    }
}
