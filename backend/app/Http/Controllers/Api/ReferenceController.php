<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Tribe;
use Illuminate\Http\JsonResponse;

class ReferenceController extends Controller
{
    /** Listes pour les menus deroulants du profil (tribus, departements, GEMs actifs). */
    public function index(): JsonResponse
    {
        return response()->json([
            'tribes' => Tribe::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'gems' => Gem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'tribe_id']),
        ]);
    }
}
