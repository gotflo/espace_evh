<?php

namespace App\Services\Sms;

/**
 * Contrat d'envoi de SMS. Implementations :
 *  - LogSmsSender : ecrit le message dans les logs (developpement).
 *  - TwilioSmsSender : vrai SMS via Twilio (production, a brancher plus tard).
 */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
