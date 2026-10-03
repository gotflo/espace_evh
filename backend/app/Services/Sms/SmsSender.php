<?php

namespace App\Services\Sms;

/**
 * Contrat d'envoi de SMS local. Twilio Verify est gere par TwilioVerifyClient.
 * LogSmsSender est utilise en developpement.
 */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
