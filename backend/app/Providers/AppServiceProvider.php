<?php

namespace App\Providers;

use App\Services\Push\WebPush;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Monitoring\Monitor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // L'envoi de codes OTP via Twilio est gere par Twilio Verify directement.
        $this->app->bind(SmsSender::class, function () {
            return match (config('services.sms.driver', 'log')) {
                'log', 'twilio_verify' => new LogSmsSender(),
                default => throw new \InvalidArgumentException('Unsupported SMS_DRIVER value.'),
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

        // Supervision : compteurs propres (une limite « throttle:N,1 » partagerait le compteur
        // de l'adresse IP avec la connexion des membres).
        RateLimiter::for('client-errors', fn (Request $r) => Limit::perMinute(20)->by('client-errors:'.$r->ip()));
        RateLimiter::for('agent', fn (Request $r) => Limit::perMinute(120)->by('agent:'.$r->ip()));

        // Supervision : journaux du serveur recopies dans la console (niveau MONITOR_LOG_LEVEL et plus),
        // requetes SQL comptees et requetes lentes journalisees (sans les valeurs).
        if (config('monitoring.enabled')) {
            Event::listen(MessageLogged::class, [Monitor::class, 'fromLog']);
            DB::listen(fn (QueryExecuted $query) => Monitor::onQuery($query));
        }

        // En production : force la generation d'URLs en HTTPS (liens, images /storage).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
