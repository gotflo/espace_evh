<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;

/**
 * Apparence publique (images de l'accueil et de l'ecran de connexion, textes affiches) : sans connexion,
 * car l'ecran de connexion en a besoin. Aucune donnee personnelle.
 */
class AppearanceController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(Appearance::client())->header('Cache-Control', 'no-cache');
    }
}
