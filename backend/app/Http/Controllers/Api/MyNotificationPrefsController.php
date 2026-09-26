<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Preferences de notifications push du membre connecte. */
class MyNotificationPrefsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['categories' => $this->present($request->user()->notification_prefs ?? [])]);
    }

    public function update(Request $request): JsonResponse
    {
        $keys = array_keys(Notifier::PREF_CATEGORIES);
        $data = $request->validate(['prefs' => ['required', 'array'], 'prefs.*' => ['boolean']]);
        $prefs = array_intersect_key(array_map('boolval', $data['prefs']), array_flip($keys));
        $user = $request->user();
        $user->forceFill(['notification_prefs' => array_merge($user->notification_prefs ?? [], $prefs)])->save();

        return response()->json(['message' => 'Préférences enregistrées.', 'categories' => $this->present($user->notification_prefs)]);
    }

    /** @return array<int, array{key: string, label: string, enabled: bool}> */
    private function present(array $prefs): array
    {
        return collect(Notifier::PREF_CATEGORIES)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'enabled' => ($prefs[$key] ?? true) !== false])
            ->values()->all();
    }
}
