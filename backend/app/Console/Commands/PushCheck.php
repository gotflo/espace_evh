<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic des notifications push sur le serveur :
 *   php artisan app:push-check                 etat general (configuration, cles, appareils, boite d'envoi)
 *   php artisan app:push-check +14185551234    envoie en plus un test aux appareils de ce membre
 */
class PushCheck extends Command
{
    protected $signature = 'app:push-check {membre? : telephone ou identifiant du membre a qui envoyer un test}';

    protected $description = 'Diagnostic des notifications push (configuration, cles, appareils, envoi de test).';

    public function handle(WebPush $webPush): int
    {
        $enabled = (bool) config('services.webpush.enabled');
        $this->line('Push activées (WEBPUSH_ENABLED) : '.($enabled ? 'oui' : 'NON'));
        $this->line('Extension OpenSSL : '.(extension_loaded('openssl') ? 'oui ('.OPENSSL_VERSION_TEXT.')' : 'NON'));
        $this->line('Extension cURL : '.(extension_loaded('curl') ? 'oui' : 'NON'));
        $this->line('Clés VAPID : '.$webPush->keySource());
        $error = $webPush->selfCheck();
        $this->line('Chiffrement et signature : '.($error === null ? 'OK' : 'ERREUR - '.$error));
        $this->line('Adresse du site (APP_URL) : '.config('app.url'));

        $subs = PushSubscription::count();
        $hosts = PushSubscription::pluck('endpoint')->map(fn ($e) => (string) parse_url($e, PHP_URL_HOST))->countBy();
        $this->line("Appareils abonnés : {$subs}".($hosts->isNotEmpty() ? ' ('.$hosts->map(fn ($n, $h) => "{$h} : {$n}")->implode(', ').')' : ''));
        $recent = PushSubscription::where('last_used_at', '>=', now()->subDay())->count();
        $this->line("Appareils ayant reçu un message dans les 24 h : {$recent}");

        $waiting = DB::table('push_outbox')->whereNull('sent_at')->count();
        $stuck = DB::table('push_outbox')->whereNull('sent_at')->where('created_at', '<', now()->subMinutes(5))->count();
        $this->line("Boîte d'envoi : {$waiting} en attente, dont {$stuck} depuis plus de 5 min (le cron doit les rattraper)");
        foreach (DB::table('push_outbox')->whereNotNull('result')->orderByDesc('id')->limit(5)->get(['id', 'created_at', 'result']) as $r) {
            $this->line("  envoi #{$r->id} du {$r->created_at} : {$r->result}");
        }

        $who = $this->argument('membre');
        if (! $who) {
            return $error === null && $enabled ? self::SUCCESS : self::FAILURE;
        }
        $user = ctype_digit((string) $who) ? User::find((int) $who) : User::where('phone', $who)->first();
        if (! $user) {
            $this->error('Membre introuvable.');

            return self::FAILURE;
        }
        $devices = $user->pushSubscriptions()->get();
        if ($devices->isEmpty()) {
            $this->warn('Ce membre n\'a aucun appareil abonné : il doit activer les notifications dans l\'application.');

            return self::FAILURE;
        }
        $result = $webPush->sendMany($devices, ['title' => 'Test du serveur', 'body' => 'Diagnostic des notifications.', 'url' => '/notifications',
            'type' => 'system', 'tag' => 'diagnostic', 'priority' => 'high'], 600, 'high', true);
        foreach ($result['devices'] as $d) {
            $sub = $devices->firstWhere('id', $d['id']);
            $this->line(sprintf('  appareil #%d (%s) : %s%s', $d['id'], (string) parse_url((string) $sub?->endpoint, PHP_URL_HOST),
                $d['status'] ?: 'pas de réponse', $d['error'] ? ' - '.$d['error'] : ''));
        }
        $this->info("Envoyés : {$result['sent']}, expirés (retirés) : {$result['expired']}, en échec : {$result['failed']}.");

        return $result['sent'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
