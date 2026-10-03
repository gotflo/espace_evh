<?php

namespace App\Services\Monitoring;

use App\Models\User;
use App\Support\Monitoring\Redactor;
use App\Support\Monitoring\ServiceMap;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent de supervision : compteurs de requetes, journal central (erreurs, journaux du
 * serveur, navigateur, securite), integrations et taches. Les mesures sont lues par la console
 * de supervision (projet separe evh_monitoring, sous-domaine distinct). Ne bloque jamais l'application :
 * toute erreur d'enregistrement est ignoree, et apres un echec la collecte se met en pause
 * quelques secondes (base injoignable : pas d'attente a chaque ligne de journal).
 */
class Monitor
{
    public const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    /** Requetes SQL de la requete HTTP en cours. */
    public static int $queries = 0;

    public static float $dbMs = 0;

    /** Origine d'une tache lancee depuis la console (sinon : planificateur ou application). */
    public static ?string $trigger = null;

    private static bool $recording = false;

    private static float $pausedUntil = 0;

    public static function enabled(): bool
    {
        return (bool) config('monitoring.enabled') && microtime(true) >= self::$pausedUntil;
    }

    public static function requestId(): ?string
    {
        $id = Context::get('request_id');

        return is_string($id) ? $id : null;
    }

    /** Execute une ecriture de supervision sans jamais propager d'erreur ni s'auto-alimenter. */
    private static function write(callable $fn): void
    {
        if (self::$recording || ! self::enabled()) {
            return;
        }
        self::$recording = true;
        try {
            $fn();
        } catch (\Throwable) {
            self::$pausedUntil = microtime(true) + 10;
        } finally {
            self::$recording = false;
        }
    }

    // ------------------------------------------------------------------ Requetes HTTP

    public static function startRequest(): string
    {
        self::$queries = 0;
        self::$dbMs = 0;
        $id = bin2hex(random_bytes(8));
        Context::add('request_id', $id);

        return $id;
    }

    /** Route generique (jamais l'URL reelle : ni identifiants ni parametres). */
    public static function routeOf(Request $request): string
    {
        $route = $request->route();

        return $route && method_exists($route, 'uri') ? mb_substr($route->uri(), 0, 160) : '(route inconnue)';
    }

    public static function recordRequest(Request $request, Response $response, float $startedAt): void
    {
        self::write(function () use ($request, $response, $startedAt) {
            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            $status = $response->getStatusCode();
            $route = self::routeOf($request);
            $method = strtoupper($request->getMethod());
            $service = ServiceMap::service($route);
            $slowMs = max(100, (int) config('monitoring.slow_request_ms', 1500));
            $queries = self::$queries;
            $dbMs = (int) round(self::$dbMs);
            $now = now();
            $bucket = $now->copy()->setTime($now->hour, intdiv($now->minute, 5) * 5)->format('Y-m-d H:i:s');

            // Valeurs entieres calculees ici : integrees telles quelles dans l'expression d'ajout.
            $slow = $ms >= $slowMs ? 1 : 0;
            $h = [$ms <= 100 ? 1 : 0, $ms > 100 && $ms <= 300 ? 1 : 0, $ms > 300 && $ms <= 1000 ? 1 : 0, $ms > 1000 && $ms <= 3000 ? 1 : 0];
            DB::table('monitor_request_stats')->upsert([[
                'bucket' => $bucket, 'service' => $service, 'route' => $route, 'method' => $method, 'status' => $status,
                'hits' => 1, 'total_ms' => $ms, 'max_ms' => $ms, 'slow_hits' => $slow,
                'h_100' => $h[0], 'h_300' => $h[1], 'h_1000' => $h[2], 'h_3000' => $h[3],
                'queries' => $queries, 'db_ms' => $dbMs,
            ]], ['bucket', 'route', 'method', 'status'], [
                'hits' => DB::raw('hits + 1'),
                'total_ms' => DB::raw('total_ms + '.$ms),
                'max_ms' => DB::raw('CASE WHEN max_ms < '.$ms.' THEN '.$ms.' ELSE max_ms END'),
                'slow_hits' => DB::raw('slow_hits + '.$slow),
                'h_100' => DB::raw('h_100 + '.$h[0]),
                'h_300' => DB::raw('h_300 + '.$h[1]),
                'h_1000' => DB::raw('h_1000 + '.$h[2]),
                'h_3000' => DB::raw('h_3000 + '.$h[3]),
                'queries' => DB::raw('queries + '.$queries),
                'db_ms' => DB::raw('db_ms + '.$dbMs),
            ]);

            $actor = $request->user();
            $userId = $actor instanceof User ? $actor->id : null;

            $reason = match (true) {
                $status >= 500 => 'error',
                in_array($status, [401, 403, 419], true) => 'denied',
                $status === 429 => 'throttled',
                $slow === 1 => 'slow',
                default => null,
            };
            if ($reason) {
                DB::table('monitor_requests')->insert([
                    'created_at' => $now, 'request_id' => self::requestId() ?? '-', 'reason' => $reason,
                    'method' => $method, 'route' => $route, 'service' => $service, 'status' => $status,
                    'duration_ms' => $ms, 'queries' => $queries, 'db_ms' => $dbMs,
                    'user_id' => $userId, 'ip' => $request->ip(),
                ]);
            }

            // Membre actif ce jour-la (une ecriture par membre et par jour au plus).
            if ($userId && $status < 400 && Cache::add('monitor:day:'.$now->toDateString().':'.$userId, 1, 93600)) {
                DB::table('monitor_user_days')->insertOrIgnore(['day' => $now->toDateString(), 'user_id' => $userId]);
            }
        });
    }

    /** Ecouteur SQL : compte les requetes et journalise les plus lentes (sans les valeurs). */
    public static function onQuery(QueryExecuted $query): void
    {
        self::$queries++;
        self::$dbMs += $query->time;
        if (self::$recording || $query->time < max(50, (int) config('monitoring.slow_query_ms', 500))) {
            return;
        }
        $sql = mb_substr(preg_replace('/\s+/', ' ', $query->sql) ?? $query->sql, 0, 400);
        self::event('warning', 'database', 'db.slow_query', 'Requête SQL lente ('.(int) $query->time.' ms)', [
            'sql' => $sql, 'ms' => (int) $query->time, 'connection' => $query->connectionName,
        ], fingerprint: Redactor::fingerprint('slow_query', $sql));
    }

    // ------------------------------------------------------------------ Journal central

    /**
     * @param  array<string, mixed>  $context
     */
    public static function event(
        string $level, string $service, string $type, string $message, array $context = [],
        ?string $outcome = null, ?int $userId = null, ?string $fingerprint = null,
    ): void {
        self::write(function () use ($level, $service, $type, $message, $context, $outcome, $userId, $fingerprint) {
            $request = app()->runningInConsole() ? null : request();
            $actor = $request?->user();
            DB::table('monitor_events')->insert([
                'created_at' => now(),
                'level' => array_key_exists($level, self::LEVELS) ? $level : 'info',
                'service' => mb_substr($service, 0, 30),
                'type' => mb_substr($type, 0, 50),
                'outcome' => $outcome,
                'message' => Redactor::text($message, 500),
                'context' => $context ? json_encode(Redactor::context($context), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'fingerprint' => $fingerprint,
                'user_id' => $userId ?? ($actor instanceof User ? $actor->id : null),
                'request_id' => self::requestId(),
                // Erreur du navigateur : la page concernee (et non l'adresse de remontee).
                'route' => $service === 'browser' ? (is_string($context['page'] ?? null) ? mb_substr($context['page'], 0, 160) : null)
                    : ($request ? self::routeOf($request) : null),
                'ip' => $request?->ip(),
            ]);
        });
    }

    /** Journaux du serveur (Log::..., report()) au-dela du niveau choisi : recopies dans la console. */
    public static function fromLog(MessageLogged $log): void
    {
        $min = self::LEVELS[config('monitoring.log_level', 'warning')] ?? 300;
        if ((self::LEVELS[$log->level] ?? 0) < $min || self::$recording) {
            return;
        }
        $context = $log->context;
        $e = $context['exception'] ?? null;
        unset($context['exception']);

        if ($e instanceof \Throwable) {
            $class = get_class($e);
            $file = Redactor::path($e->getFile());
            $service = match (true) {
                $e instanceof \Illuminate\Database\QueryException, $e instanceof \PDOException => 'database',
                $e instanceof \App\Services\Sms\TwilioVerifyException => 'sms',
                app()->runningInConsole() => 'automation',
                default => 'api',
            };
            self::event($log->level, $service, 'exception', class_basename($class).' : '.$e->getMessage(), $context + [
                'exception' => $class,
                'file' => $file.':'.$e->getLine(),
                'trace' => Redactor::trace($e),
                'previous' => $e->getPrevious() ? class_basename($e->getPrevious()).' : '.Redactor::text($e->getPrevious()->getMessage(), 200) : null,
            ], 'failure', fingerprint: Redactor::fingerprint($class, $e->getMessage(), (string) $file, (string) $e->getLine()));

            return;
        }

        $message = (string) $log->message;
        $service = match (true) {
            (bool) preg_match('/^(OTP|SMS|Twilio)/i', $message) => 'sms',
            (bool) preg_match('/^Push/i', $message) => 'push',
            (bool) preg_match('/^Automatismes?/i', $message) => 'automation',
            app()->runningInConsole() => 'automation',
            default => 'api',
        };
        self::event($log->level, $service, 'log', $message, $context, in_array($log->level, ['warning', 'notice', 'info', 'debug'], true) ? null : 'failure',
            fingerprint: Redactor::fingerprint('log', $service, $message));
    }

    /** Nettoyage de securite : si la console ne purge plus (non installee), les mesures ne grossissent pas sans fin. */
    public static function prune(): int
    {
        $days = max(7, (int) config('monitoring.agent_max_days', 120));
        $limit = now()->subDays($days);
        $n = 0;
        foreach (['monitor_events' => 'created_at', 'monitor_requests' => 'created_at', 'monitor_job_runs' => 'started_at'] as $table => $col) {
            $n += DB::table($table)->where($col, '<', $limit)->delete();
        }
        foreach (['monitor_request_stats', 'monitor_integration_stats'] as $table) {
            $n += DB::table($table)->where('bucket', '<', $limit->format('Y-m-d H:i:s'))->delete();
        }
        $n += DB::table('monitor_user_days')->where('day', '<', $limit->toDateString())->delete();

        return $n;
    }

    // ------------------------------------------------------------------ Integrations et taches

    public static function integration(string $integration, string $operation, bool $ok, int $ms = 0): void
    {
        self::integrationCounts($integration, $operation, $ok ? 1 : 0, $ok ? 0 : 1, $ms);
    }

    public static function integrationCounts(string $integration, string $operation, int $ok, int $failed, int $ms = 0): void
    {
        if ($ok + $failed === 0) {
            return;
        }
        self::write(function () use ($integration, $operation, $ok, $failed, $ms) {
            $ok = max(0, $ok);
            $failed = max(0, $failed);
            $ms = max(0, $ms);
            DB::table('monitor_integration_stats')->upsert([[
                'bucket' => now()->startOfHour()->format('Y-m-d H:i:s'), 'integration' => $integration, 'operation' => $operation,
                'ok' => $ok, 'failed' => $failed, 'total_ms' => $ms,
            ]], ['bucket', 'integration', 'operation'], [
                'ok' => DB::raw('ok + '.$ok),
                'failed' => DB::raw('failed + '.$failed),
                'total_ms' => DB::raw('total_ms + '.$ms),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function jobRun(string $command, float $startedAt, string $status, array $summary = []): void
    {
        self::write(function () use ($command, $startedAt, $status, $summary) {
            DB::table('monitor_job_runs')->insert([
                'command' => $command,
                'trigger' => self::$trigger ?? (app()->runningInConsole() ? 'cron' : 'application'),
                'started_at' => now()->subMilliseconds((int) round((microtime(true) - $startedAt) * 1000)),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'status' => $status,
                'summary' => $summary ? json_encode(Redactor::context($summary), JSON_UNESCAPED_UNICODE) : null,
            ]);
        });
    }
}
