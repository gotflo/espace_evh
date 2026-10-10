<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reglages de la plateforme (permission settings.manage) : chaque modification est journalisee. */
class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['groups' => PlatformSettings::present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array', 'min:1', 'max:50']]);
        [$old, $new] = PlatformSettings::update($data['values'], $request->user());
        if ($new) {
            Audit::log('settings.updated', null, null, $old, $new);
        }

        return response()->json([
            'message' => $new ? 'Réglages enregistrés.' : 'Aucun changement.',
            'groups' => PlatformSettings::present(),
        ]);
    }
}
