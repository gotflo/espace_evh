<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPush;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        foreach (PushSubscription::with('user.profile')->orderBy('id')->limit(30)->get() as $s) {
            $this->line(sprintf('  appareil #%d : %s (%s) · %s · abonné le %s · dernier message accepté : %s', $s->id,
                $s->user?->profile?->full_name ?: 'membre #'.$s->user_id, substr((string) $s->user?->phone, -4),
                self::device((string) $s->user_agent), $s->created_at?->format('Y-m-d H:i') ?? '?',
                $s->last_used_at?->format('Y-m-d H:i') ?? 'jamais'));
            // Accuse de reception envoye par le telephone lui-meme.
            $this->line('      reçu sur le téléphone : '.($s->last_received_at?->format('Y-m-d H:i') ?? 'jamais (le message n\'arrive pas jusqu\'à l\'application)')
                .($s->last_error ? ' · affichage en ERREUR : '.$s->last_error : ($s->last_received_at ? ' · notification affichée' : '')));
        }
        $recent = PushSubscription::where('last_used_at', '>=', now()->subDay())->count();
        $this->line("Appareils ayant reçu un message dans les 24 h : {$recent}");

        $waiting = DB::table('push_outbox')->whereNull('sent_at')->count();
        $stuck = DB::table('push_outbox')->whereNull('sent_at')->where('created_at', '<', now()->subMinutes(5))->count();
        $this->line("Boîte d'envoi : {$waiting} en attente, dont {$stuck} depuis plus de 5 min (le cron doit les rattraper)");
        foreach (DB::table('push_outbox')->whereNull('sent_at')->orderBy('id')->limit(10)->get() as $r) {
            $this->line("  en attente #{$r->id} du {$r->created_at} : {$r->attempts} essai(s)".($r->claimed_at ? ", pris le {$r->claimed_at}" : ', jamais pris').($r->result ? " - {$r->result}" : ''));
        }
        foreach (DB::table('push_outbox')->whereNotNull('result')->orderByDesc('id')->limit(5)->get(['id', 'created_at', 'result']) as $r) {
            $this->line("  envoi #{$r->id} du {$r->created_at} : {$r->result}");
        }
        $flushed = Cache::get('push-outbox:last-run');
        $this->line('Dernier rattrapage (cron chaque minute, automatismes en secours) : '
            .($flushed ? 'il y a '.(int) Carbon::parse($flushed)->diffInMinutes(now(), true).' min' : 'JAMAIS - le cron ne lance pas app:push-outbox'));

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

    /** Systeme et version (ex. « iPhone iOS 18.1 »), d'apres le navigateur de l'appareil. */
    private static function device(string $ua): string
    {
        if (preg_match('/(iPhone|iPad).*? OS (\d+)_(\d+)/', $ua, $m)) {
            return "{$m[1]} iOS {$m[2]}.{$m[3]}";
        }
        if (preg_match('/Android (\d+)/', $ua, $m)) {
            return 'Android '.$m[1];
        }

        return str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac OS') ? 'Mac' : 'appareil inconnu');
    }
}
