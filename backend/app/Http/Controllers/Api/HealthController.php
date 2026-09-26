<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\AutomationTick;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Etat de sante de la plateforme, pour une surveillance externe (ex. UptimeRobot) :
 * base de donnees, cache, stockage, espace disque et passage recent des automatismes.
 * Aucune donnee sensible : ni version, ni chemin, ni configuration.
 * 200 si tout va bien, 503 si un element essentiel est en panne.
 */
class HealthController extends Controller
{
    /** Au-dela, les automatismes (rappels, anniversaires...) sont consideres en retard. */
    private const AUTOMATION_MAX_MINUTES = 20;

    public function __invoke(): JsonResponse
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

        $last = Cache::get(AutomationTick::STATUS_KEY);
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

        return response()->json([
            'status' => $status,
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $essential ? 200 : 503);
    }
}
