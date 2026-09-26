<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rapports de tribu / d'eglise (permission reports.view). La portee demandee est toujours
 * verifiee cote serveur : un AP n'obtient que ses tribus, meme en appelant l'API directement.
 */
class ReportController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        return response()->json(ReportService::options($request->user()));
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:30'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json(ReportService::build($request->user(), $data['scope'], (int) ($data['months'] ?? 6)));
    }

    /** Liste des membres de la portee avec leurs indicateurs (filtres : actifs, inactifs, profil incomplet, FISS manquante). */
    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:30'],
            'filter' => ['nullable', 'in:all,active,inactive,incomplete,fiss_missing'],
        ]);
        $rows = ReportService::memberRows($request->user(), $data['scope'], $data['filter'] ?? 'all');

        return response()->json(['members' => $rows, 'total' => count($rows)]);
    }

    /** Trace l'export d'un rapport PDF (genere sur l'appareil a partir des donnees autorisees). */
    public function logExport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:30'],
            'kind' => ['required', 'in:report,members'],
        ]);
        ReportService::resolveScope($request->user(), $data['scope']);
        Audit::log('report.exported', null, null, [], $data);

        return response()->json(['message' => 'ok']);
    }
}
