<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Choix de l'implementation d'envoi de SMS selon SMS_DRIVER (.env).
        // 'log' = code ecrit dans les logs (dev). 'twilio' sera ajoute plus tard.
        $this->app->bind(SmsSender::class, function () {
            return match (config('services.sms.driver', 'log')) {
                default => new LogSmsSender(),
            };
        });
    }

    public function boot(): void
    {
        // En production : force la generation d'URLs en HTTPS (liens, images /storage).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
