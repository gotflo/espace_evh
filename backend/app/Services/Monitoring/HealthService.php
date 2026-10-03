<?php

namespace App\Services\Monitoring;

use App\Console\Commands\AutomationTick;
use App\Services\Push\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Etat de sante de la plateforme. La version publique (/api/health) ne contient aucune donnee
 * sensible ; la version detaillee (console) ajoute la configuration des integrations.
 */
class HealthService
{
    /** Au-dela, les automatismes (rappels, anniversaires...) sont consideres en retard. */
    public const AUTOMATION_MAX_MINUTES = 20;

    /**
     * @return array{status: string, essential: bool, checks: array<string, array<string, mixed>>}
     */
    public static function run(): array
    {
        $checks = [];

        $t = microtime(true);
        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (\Throwable $e) {
            report($e);
            $checks['database'] = ['ok' => false];
        }

        try {
            Cache::put('health:probe', 1, 60);
            $checks['cache'] = ['ok' => Cache::get('health:probe') === 1];
        } catch (\Throwable) {
            $checks['cache'] = ['ok' => false];
        }

        $checks['storage'] = ['ok' => is_writable(storage_path('logs')) && is_writable(storage_path('framework/cache'))];

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $checks['disk'] = ['ok' => ! $free || ! $total || $free / $total > 0.05];

        // Notifications push : activees, cles et chiffrement utilisables (verification locale,
        // sans envoi), aucun envoi bloque dans la boite d'envoi, dernier message recu par un appareil.
        try {
            $enabled = (bool) config('services.webpush.enabled');
            $error = $enabled ? app(WebPush::class)->selfCheck() : 'desactivees (WEBPUSH_ENABLED)';
            $stuck = DB::table('push_outbox')->whereNull('sent_at')->where('created_at', '<', now()->subMinutes(5))->count();
            $last = DB::table('push_subscriptions')->max('last_used_at');
            $checks['push'] = array_filter([
                'ok' => $enabled && $error === null && $stuck === 0,
                'devices' => DB::table('push_subscriptions')->count(),
                'waiting' => $stuck,
                'last_delivery_hours' => $last ? (int) Carbon::parse($last)->diffInHours(now(), true) : null,
                'error' => $error,
            ], fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            report($e);
            $checks['push'] = ['ok' => false, 'error' => 'verification impossible'];
        }

        try {
            $last = Cache::get(AutomationTick::STATUS_KEY);
        } catch (\Throwable) {
            $last = null;
        }
        $minutes = isset($last['at']) ? (int) Carbon::parse($last['at'])->diffInMinutes(now(), true) : null;
        $failed = isset($last['steps']) ? count(array_filter($last['steps'], 'is_string')) : 0;
        $checks['automation'] = [
            'ok' => $minutes !== null && $minutes <= self::AUTOMATION_MAX_MINUTES && $failed === 0,
            'last_run_minutes' => $minutes,
            'failed_steps' => $failed,
        ];

        // Seules la base et le cache sont indispensables pour servir les membres.
        $essential = $checks['database']['ok'] && $checks['cache']['ok'];
        $status = ! $essential ? 'down' : (collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'degraded');

        return ['status' => $status, 'essential' => $essential, 'checks' => $checks];
    }

    /**
     * Version detaillee pour la console : chiffres utiles au diagnostic et etat des integrations
     * (configure ou non), sans jamais exposer une valeur secrete.
     *
     * @return array<string, mixed>
     */
    public static function detailed(): array
    {
        $health = self::run();
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $health['checks']['disk'] += [
            'free_percent' => $free && $total ? round($free / $total * 100, 1) : null,
            'free_gb' => $free ? round($free / 1073741824, 1) : null,
        ];
        $health['checks']['database'] += ['driver' => DB::connection()->getDriverName()];

        $last = Cache::get(AutomationTick::STATUS_KEY);
        $health['checks']['automation'] += [
            'last_run_at' => $last['at'] ?? null,
            'duration_ms' => $last['duration_ms'] ?? null,
            'steps' => collect($last['steps'] ?? [])->map(fn ($s) => is_string($s) ? ['ok' => false, 'error' => \App\Support\Monitoring\Redactor::text($s, 200)] : ['ok' => true] + $s)->all(),
            'trigger' => DB::table('monitor_job_runs')->where('command', 'app:tick')->orderByDesc('id')->value('trigger'),
            'cron_seen' => DB::table('monitor_job_runs')->where('trigger', 'cron')->where('started_at', '>=', now()->subMinutes(30))->exists(),
            'auto_tick' => (bool) config('services.automation.auto_tick'),
        ];

        $driver = (string) config('services.sms.driver');
        $twilioReady = $driver === 'twilio_verify'
            && preg_match('/^AC[a-f0-9]{32}$/i', (string) config('services.sms.twilio.sid'))
            && preg_match('/^VA[a-f0-9]{32}$/i', (string) config('services.sms.twilio.verify_service_sid'))
            && (config('services.sms.twilio.api_key_secret') || config('services.sms.twilio.token'));
        $sms = DB::table('monitor_integration_stats')->where('integration', 'twilio_verify')->where('bucket', '>=', now()->subDay()->startOfHour())
            ->selectRaw('COALESCE(SUM(ok),0) as ok, COALESCE(SUM(failed),0) as failed')->first();
        $health['checks']['sms'] = [
            'ok' => $driver === 'twilio_verify' ? (bool) $twilioReady && (int) $sms->failed < 3 : app()->environment('local', 'testing'),
            'driver' => $driver,
            'configured' => (bool) $twilioReady,
            'calls_24h' => (int) $sms->ok + (int) $sms->failed,
            'failed_24h' => (int) $sms->failed,
            'note' => $driver === 'twilio_verify' ? null : 'Mode « log » : les codes ne partent pas par SMS (développement uniquement).',
        ];

        $mailer = (string) config('mail.default');
        $health['checks']['mail'] = [
            'ok' => true,
            'configured' => ! in_array($mailer, ['log', 'array'], true),
            'driver' => $mailer,
            'note' => in_array($mailer, ['log', 'array'], true) ? 'Aucun envoi de courriel configuré (MAIL_MAILER).' : null,
        ];

        $health['checks']['scheduler'] = [
            'ok' => $health['checks']['automation']['cron_seen'] || (bool) config('services.automation.auto_tick'),
            'cron_seen' => $health['checks']['automation']['cron_seen'],
        ];

        return $health + ['php' => PHP_VERSION, 'laravel' => app()->version(), 'environment' => app()->environment()];
    }

    /** Duree de fonctionnement du serveur (Linux), si l'hebergeur la rend lisible. */
    public static function serverUptimeSeconds(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');

        return $raw && preg_match('/^(\d+)/', $raw, $m) ? (int) $m[1] : null;
    }

    /** Date de la version installee de l'interface (fichier d'entree de l'application). */
    public static function deployedAt(): ?string
    {
        foreach ([public_path('index.html'), public_path('build/manifest.json'), base_path('bootstrap/cache/config.php')] as $file) {
            if (is_file($file)) {
                return Carbon::createFromTimestamp((int) filemtime($file))->toIso8601String();
            }
        }

        return null;
    }
}
