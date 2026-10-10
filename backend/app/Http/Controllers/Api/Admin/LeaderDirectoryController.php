<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Services\LeaderDirectoryService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Annuaire des responsables (AP, patriarches, responsables de departement, Gardes) et fiche
 * d'un Garde. Perimetre verifie par LeaderDirectoryService : toute l'eglise pour les pasteurs,
 * leurs tribus pour les AP et les patriarches.
 */
class LeaderDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(LeaderDirectoryService::directory($request->user()));
    }

    /**
     * Export PDF de l'annuaire : meme perimetre que l'ecran, droit d'exporter les listes (members.export),
     * export journalise dans la meme requete.
     */
    public function export(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('members.export'), 403, 'Vous ne pouvez pas exporter la liste des responsables.');
        $directory = LeaderDirectoryService::directory($request->user());
        Audit::log('leaders.exported', null, null, [], ['scope' => $directory['scope'], 'tribes' => count($directory['tribes'])]);

        return response()->json($directory + ['exported_at' => now()->toIso8601String()]);
    }

    public function gem(Request $request, Gem $gem): JsonResponse
    {
        return response()->json(LeaderDirectoryService::gem($request->user(), $gem));
    }
}
