<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Messenger;
use App\Services\Notifier;
use App\Services\Sms\TwilioMessagingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferences de notifications du membre connecte : categories (push, courriel et texto suivent le
 * meme choix) et canaux « par courriel » / « par texto ».
 */
class MyNotificationPrefsController extends Controller
{
    private const CHANNELS = ['channel_email', 'channel_sms'];

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $keys = array_merge(array_keys(Notifier::PREF_CATEGORIES), self::CHANNELS);
        $data = $request->validate(['prefs' => ['required', 'array'], 'prefs.*' => ['boolean']]);
        $prefs = array_intersect_key(array_map('boolval', $data['prefs']), array_flip($keys));
        $user = $request->user();
        $user->forceFill(['notification_prefs' => array_merge($user->notification_prefs ?? [], $prefs)])->save();

        return response()->json(['message' => 'Préférences enregistrées.'] + $this->payload($user->fresh('profile')));
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $prefs = $user->notification_prefs ?? [];
        $email = Messenger::emailOf($user->loadMissing('profile'));

        return [
            'categories' => collect(Notifier::PREF_CATEGORIES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'enabled' => ($prefs[$key] ?? true) !== false])
                ->values()->all(),
            'channels' => [
                ['key' => 'channel_email', 'label' => 'Recevoir aussi par courriel', 'enabled' => ($prefs['channel_email'] ?? true) !== false,
                    'available' => $email !== null, 'detail' => $email ?? 'Ajoutez votre adresse courriel dans votre profil.'],
                ['key' => 'channel_sms', 'label' => 'Recevoir aussi par texto', 'enabled' => ($prefs['channel_sms'] ?? true) !== false,
                    'available' => TwilioMessagingClient::enabled(),
                    'detail' => TwilioMessagingClient::enabled() ? 'Quand vous n\'avez pas d\'adresse courriel, au '.$user->phone.'.' : 'Bientôt disponible.'],
            ],
        ];
    }
}
