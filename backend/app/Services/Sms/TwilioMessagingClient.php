<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Textos de notification (devoir, rappels) par l'API Messages de Twilio. Distinct de Twilio Verify
 * (codes de connexion) : il faut un numero Twilio (TWILIO_SMS_FROM) ou un service de messagerie
 * (TWILIO_MESSAGING_SERVICE_SID). Sans l'un des deux, les textos sont desactives.
 */
class TwilioMessagingClient
{
    public static function enabled(): bool
    {
        $from = config('services.sms.twilio.sms_from');
        $service = config('services.sms.twilio.messaging_service_sid');
        $account = config('services.sms.twilio.sid');

        return config('services.sms.driver') !== 'log'
            && is_string($account) && preg_match('/^AC[a-f0-9]{32}$/i', $account) === 1
            && ((is_string($from) && preg_match('/^\+[1-9]\d{7,14}$/', $from) === 1)
                || (is_string($service) && preg_match('/^MG[a-f0-9]{32}$/i', $service) === 1));
    }

    /** Envoie le texto ; leve une exception (message sans secret) en cas d'echec. */
    public function send(string $to, string $body): string
    {
        $account = (string) config('services.sms.twilio.sid');
        $data = ['To' => $to, 'Body' => $body];
        $service = config('services.sms.twilio.messaging_service_sid');
        if (is_string($service) && $service !== '') {
            $data['MessagingServiceSid'] = $service;
        } else {
            $data['From'] = (string) config('services.sms.twilio.sms_from');
        }

        $response = $this->http()->asForm()->post("https://api.twilio.com/2010-04-01/Accounts/{$account}/Messages.json", $data);
        if (! $response->successful()) {
            $code = $response->json('code');
            throw new RuntimeException("Twilio Messages HTTP {$response->status()}".($code ? " (code {$code})" : '').': '.mb_substr((string) $response->json('message'), 0, 200));
        }

        return (string) $response->json('sid');
    }

    private function http(): PendingRequest
    {
        $keySid = config('services.sms.twilio.api_key_sid');
        $keySecret = config('services.sms.twilio.api_key_secret');
        $http = Http::timeout(15)->connectTimeout(8);

        return is_string($keySid) && $keySid !== '' && is_string($keySecret) && $keySecret !== ''
            ? $http->withBasicAuth($keySid, $keySecret)
            : $http->withBasicAuth((string) config('services.sms.twilio.sid'), (string) config('services.sms.twilio.token'));
    }
}
