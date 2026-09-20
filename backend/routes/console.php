<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rappels FISS : en avance (le 20) puis urgent (dernier jour du mois).
// Necessite un cron "php artisan schedule:run" chaque minute sur l'hebergement.
Schedule::command('fiss:remind --type=advance')->monthlyOn(20, '08:00');
Schedule::command('fiss:remind --type=urgent')->lastDayOfMonth('08:00');
