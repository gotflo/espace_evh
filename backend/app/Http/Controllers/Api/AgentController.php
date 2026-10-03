<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\AutomationTick;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Monitoring\HealthService;
use App\Services\Monitoring\Monitor;
use App\Services\Monitoring\UserAdmin;
use App\Services\Notifier;
use App\Services\Sms\TwilioVerifyClient;
use App\Support\Audit;
use App\Support\Monitoring\Redactor;
use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API de controle de l'agent de supervision (appelee uniquement par la console evh_monitoring,
 * requetes signees). Les actions passent par la logique de l'application (contraintes, audit) :
 * la console ne modifie jamais directement la base de l'application.
 */
class AgentController extends Controller
{
    /** Etat detaille : services, integrations, configuration (indicateurs seulement, jamais de secret). */
    public function health(): JsonResponse
    {
        $prod = app()->environment('production');
        $env = base_path('.env');

        return response()->json(HealthService::detailed() + [
            'app_name' => config('app.name'),
            'time' => now()->toIso8601String(),
            'deployed_at' => HealthService::deployedAt(),
            'server_uptime_s' => HealthService::serverUptimeSeconds(),
            'timezone' => config('app.timezone'),
            'config' => [
                'environment' => app()->environment(),
                'production' => $prod,
                'debug' => (bool) config('app.debug'),
                'app_key' => (bool) config('app.key'),
                'https' => str_starts_with((string) config('app.url'), 'https://'),
                'app_url' => config('app.url'),
                'expose_otp' => (bool) config('app.expose_otp'),
                'sms_driver' => (string) config('services.sms.driver'),
                'member_session_days' => (int) round((int) config('sanctum.expiration') / 1440),
                'log_level' => strtolower((string) config('logging.channels.single.level', 'debug')),
                'env_world_readable' => is_file($env) && DIRECTORY_SEPARATOR === '/' ? (bool) (fileperms($env) & 0o004) : null,
                'monitoring_enabled' => (bool) config('monitoring.enabled'),
                'slow_request_ms' => (int) config('monitoring.slow_request_ms'),
                'slow_query_ms' => (int) config('monitoring.slow_query_ms'),
            ],
        ]);
    }

    /** Paquets PHP installes (nom => version), pour la verification des vulnerabilites par la console. */
    public function packages(): JsonResponse
    {
        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $version = InstalledVersions::getPrettyVersion($name);
            if ($version && str_contains($name, '/') && InstalledVersions::isInstalled($name, false)) {
                $packages[$name] = ltrim($version, 'vV');
            }
        }
        unset($packages['laravel/laravel']);
        ksort($packages);

        return response()->json(['packages' => $packages]);
    }

    public function logFiles(): JsonResponse
    {
        return response()->json(['files' => collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn ($path) => ['name' => basename($path), 'size' => filesize($path) ?: 0, 'modified_at' => date(DATE_ATOM, (int) filemtime($path))])
            ->sortByDesc('modified_at')->values()]);
    }

    /** Fin d'un fichier journal, masquee a la source (codes, numeros, jetons, valeurs SQL). */
    public function logFile(Request $request, string $name): JsonResponse
    {
        $f = $request->validate(['lines' => ['nullable', 'integer', 'min:20', 'max:1000'], 'q' => ['nullable', 'string', 'max:120']]);
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.log$/', $name), 404);
        $path = storage_path('logs/'.$name);
        abort_unless(is_file($path), 404, 'Fichier introuvable.');

        $size = filesize($path) ?: 0;
        $read = min($size, 2_000_000);
        $fh = fopen($path, 'rb');
        fseek($fh, -$read, SEEK_END);
        $data = (string) fread($fh, $read);
        fclose($fh);
        // Une entree Laravel peut s'etaler sur plusieurs lignes (pile d'appels) : on garde les entrees.
        $entries = array_values(array_filter(preg_split('/\R(?=\[\d{4}-\d{2}-\d{2}[ T])/', $data) ?: [], fn ($e) => trim($e) !== ''));
        $entries = array_slice($entries, -((int) ($f['lines'] ?? 200)));
        if (! empty($f['q'])) {
            $needle = mb_strtolower($f['q']);
            $entries = array_values(array_filter($entries, fn ($l) => str_contains(mb_strtolower($l), $needle)));
        }

        return response()->json(['name' => $name, 'lines' => array_map(fn ($l) => Redactor::text(mb_substr(trim($l), 0, 4000), 1500), $entries)]);
    }

    public function impact(User $user): JsonResponse
    {
        return response()->json(UserAdmin::impact($user));
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        abort_if((bool) $user->blocked_at, 422, 'Ce compte est déjà bloqué.');
        UserAdmin::block($user, $reason);
        Audit::log('member.blocked', $user, $user->id, [], ['reason' => $reason], $this->via($request));

        return response()->json(['message' => 'Compte bloqué : la personne est déconnectée et ne peut plus se connecter.']);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        if (! $user->blocked_at) {
            return response()->json(['message' => 'Ce compte n\'est pas bloqué.']);
        }
        $previous = $user->blocked_reason;
        UserAdmin::unblock($user);
        Audit::log('member.unblocked', $user, $user->id, ['reason' => $previous], [], $this->via($request));

        return response()->json(['message' => 'Compte débloqué : la personne peut de nouveau se connecter.']);
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $n = UserAdmin::revokeSessions($user);
        Audit::log('member.sessions_revoked', $user, $user->id, [], ['sessions' => $n], $this->via($request));

        return response()->json(['message' => $n ? $n.' session(s) fermée(s).' : 'Aucune session ouverte.', 'sessions' => $n]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $impact = UserAdmin::impact($user);
        $id = $user->id;
        UserAdmin::delete($user);
        // Le membre n'existe plus : l'entree garde l'identifiant dans le contexte, sans lien.
        Audit::log('member.deleted', null, null, [], ['user_id' => $id], $this->via($request) + [
            'deleted' => collect($impact['deleted'])->mapWithKeys(fn ($i) => [$i['label'] => $i['count']])->all(),
        ]);

        return response()->json(['message' => 'Compte supprimé définitivement.', 'impact' => $impact]);
    }

    /** Actions de depannage executees par l'application elle-meme. */
    public function action(string $action): JsonResponse
    {
        switch ($action) {
            case 'run_automation':
                Monitor::$trigger = 'console';
                Artisan::call('app:tick');
                $status = Cache::get(AutomationTick::STATUS_KEY, []);
                $failed = array_keys(array_filter($status['steps'] ?? [], 'is_string'));

                return response()->json([
                    'message' => $failed ? 'Automatismes lancés, étape(s) en échec : '.implode(', ', $failed).'.' : 'Automatismes lancés sans erreur ('.($status['duration_ms'] ?? 0).' ms).',
                    'details' => ['duration_ms' => $status['duration_ms'] ?? null, 'failed_steps' => $failed,
                        'counts' => collect($status['steps'] ?? [])->filter(fn ($s) => is_array($s) && $s['count'] > 0)->map(fn ($s) => $s['count'])->all()],
                ]);

            case 'flush_push':
                $n = Notifier::flushOutbox();
                $waiting = DB::table('push_outbox')->whereNull('sent_at')->count();

                return response()->json(['message' => $n.' envoi(s) rattrapé(s), '.$waiting.' encore en attente.', 'details' => ['sent' => $n, 'waiting' => $waiting]]);

            case 'sms_check':
                if (config('services.sms.driver') !== 'twilio_verify') {
                    return response()->json(['message' => 'Mode « '.config('services.sms.driver').' » : les codes ne passent pas par Twilio (développement).', 'details' => ['driver' => config('services.sms.driver')]]);
                }
                $service = app(TwilioVerifyClient::class)->service();

                return response()->json([
                    'message' => 'Twilio Verify répond : service « '.($service['friendly_name'] ?? '?').' », codes de '.($service['code_length'] ?? '?').' chiffres.',
                    'details' => ['friendly_name' => $service['friendly_name'], 'code_length' => $service['code_length'], 'template' => (bool) $service['template_sid']],
                ]);

            case 'clear_config':
                foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
                    Artisan::call($command);
                }

                return response()->json(['message' => 'Caches de configuration, de routes et de vues vidés sur la plateforme.', 'details' => []]);
        }
        abort(404, 'Action inconnue.');
    }

    /** Alerte de la console envoyee dans l'application (cloche + push) aux comptes de ces numeros. */
    public function notify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phones' => ['required', 'array', 'max:50'],
            'phones.*' => ['string', 'max:20'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:400'],
            'urgency' => ['nullable', 'in:normal,high'],
        ]);
        $ids = User::whereIn('phone', $data['phones'])->whereNull('blocked_at')->pluck('id');
        $n = $ids->isEmpty() ? 0 : Notifier::send($ids, 'monitor_alert', $data['title'], $data['body'] ?? null, null, [], $data['urgency'] ?? 'normal');

        return response()->json(['message' => $n.' destinataire(s).', 'recipients' => $n]);
    }

    /** Trace dans l'audit de l'application : action decidee dans la console, par qui. */
    private function via(Request $request): array
    {
        $actor = rawurldecode((string) $request->header('X-Agent-Actor', ''));

        return ['via' => 'console de supervision', 'operator' => mb_substr($actor, 0, 120) ?: null];
    }
}
