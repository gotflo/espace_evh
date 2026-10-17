<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Monitoring\Monitor;
use App\Services\Push\WebPush;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // L'envoi de codes OTP via Twilio est gere par Twilio Verify directement.
        $this->app->bind(SmsSender::class, function () {
            return match (config('services.sms.driver', 'log')) {
                'log', 'twilio_verify' => new LogSmsSender,
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
        // Actions d'un membre qui prevennent d'autres personnes (famille, services, demandes) ou qui
        // ajoutent des donnees (agenda, journal) : pas de rafale, ni de notifications en boucle.
        RateLimiter::for('member-writes', fn (Request $r) => [
            Limit::perMinute(20)->by('member-writes:'.($r->user()?->id ?: $r->ip())),
            Limit::perHour(120)->by('member-writes-h:'.($r->user()?->id ?: $r->ip())),
        ]);
        // Photo envoyee avec le profil : le decodage d'une image coute de la memoire et du temps.
        RateLimiter::for('profile-photo', fn (Request $r) => $r->hasFile('photo')
            ? Limit::perMinute(6)->by('profile-photo:'.($r->user()?->id ?: $r->ip()))
            : Limit::none());
        // Compte de gestion (autorisation d'un appareil) : 5 essais par minute et par adresse, verrouillage apres 5 codes faux.
        RateLimiter::for('manager-activation', fn (Request $r) => Limit::perMinute(5)->by('manager-activation:'.$r->ip()));

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

        // En production : force la generation d'URLs en HTTPS sur le domaine officiel (liens signes des
        // courriels, images /storage) : un en-tete Host forge ne peut pas detourner un lien envoye au pasteur.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            if ($root = config('app.url')) {
                URL::forceRootUrl(rtrim((string) $root, '/'));
            }
        }
    }
}
