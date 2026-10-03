<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Services\LeaderDirectoryService;
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

    public function gem(Request $request, Gem $gem): JsonResponse
    {
        return response()->json(LeaderDirectoryService::gem($request->user(), $gem));
    }
}
