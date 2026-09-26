<?php

namespace App\Providers;

use App\Services\Push\WebPush;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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

        // Une seule instance : les cles VAPID sont lues une fois par processus.
        $this->app->singleton(WebPush::class);
    }

    public function boot(): void
    {
        // Jetons : « derniere utilisation » ecrite au plus toutes les 5 minutes (charge).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Limites dediees (compteurs separes de la limite generale de l'API : une limite
        // « throttle:N,1 » posee sur une route partagerait le compteur global de l'utilisateur).
        RateLimiter::for('member-requests', fn (Request $r) => Limit::perMinute(10)->by('member-requests:'.($r->user()?->id ?: $r->ip())));
        // Lecteur video : un signal toutes les ~15 s (marge pour pauses, reprises, onglets multiples).
        RateLimiter::for('video-progress', fn (Request $r) => Limit::perMinute(40)->by('video-progress:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('member-search', fn (Request $r) => Limit::perMinute(60)->by('member-search:'.($r->user()?->id ?: $r->ip())));

        // En production : force la generation d'URLs en HTTPS (liens, images /storage).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
