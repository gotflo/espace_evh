<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\HealthService;
use Illuminate\Http\JsonResponse;

/**
 * Etat de sante de la plateforme, pour une surveillance externe (ex. UptimeRobot) :
 * base de donnees, cache, stockage, espace disque et passage recent des automatismes.
 * Aucune donnee sensible : ni version, ni chemin, ni configuration.
 * 200 si tout va bien, 503 si un element essentiel est en panne.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $health = HealthService::run();

        return response()->json([
            'status' => $health['status'],
            'checks' => $health['checks'],
            'time' => now()->toIso8601String(),
        ], $health['essential'] ? 200 : 503);
    }
}
