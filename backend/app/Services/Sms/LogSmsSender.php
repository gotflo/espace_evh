<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Envoi de SMS simule : le message est ecrit dans les logs (storage/logs/laravel.log).
 * Utilise en developpement pour ne pas dependre d'un fournisseur payant.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info("[SMS simule] vers {$phone} : {$message}");
    }
}
