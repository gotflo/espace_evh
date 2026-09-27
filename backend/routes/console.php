<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatismes (rappels d'evenements, anniversaires, taches, FISS, nettoyage).
// Idempotent : peut tourner souvent sans doublon. Necessite un cron
// "php artisan schedule:run" chaque minute sur l'hebergement ; sinon l'application
// le declenche elle-meme au plus toutes les 5 minutes (voir TriggerAutomation).
Schedule::command('app:tick')->everyFiveMinutes()->withoutOverlapping(10);

// Notifications push restees en attente (envoi apres la reponse interrompu, service push
// injoignable) : rattrapees chaque minute, sans doublon.
Schedule::command('app:push-outbox')->everyMinute()->withoutOverlapping(10);
