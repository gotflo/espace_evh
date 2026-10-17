<?php

use App\Support\AutomationLock;
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
// Hostinger desactive proc_open : Schedule::command (qui lance un processus) echoue. Les taches
// s'executent donc dans le processus du planificateur (Artisan::call), sans proc_open.
// Le cron signale sa presence (TriggerAutomation se met alors en retrait) ; un seul passage a la fois.
Schedule::call(function () {
    AutomationLock::markCronSeen();
    AutomationLock::run(fn () => Artisan::call('app:tick'));
})->name('app:tick')->everyFiveMinutes()->withoutOverlapping(10);

// Notifications push restees en attente (envoi apres la reponse interrompu, service push
// injoignable) : rattrapees chaque minute, sans doublon.
Schedule::call(fn () => Artisan::call('app:push-outbox'))->name('app:push-outbox')->everyMinute()->withoutOverlapping(10);

// Menage quotidien : jetons expires, anciens codes SMS et envois, fichiers de cache perimes (heure creuse).
Schedule::call(fn () => Artisan::call('app:housekeeping'))->name('app:housekeeping')->dailyAt('03:30')->withoutOverlapping(30);

// Courriels et textos en file (devoir, rappels, demandes de suppression) et courriel du jour des pasteurs.
Schedule::call(fn () => Artisan::call('app:send-outbox'))->name('app:send-outbox')->everyMinute()->withoutOverlapping(10);
