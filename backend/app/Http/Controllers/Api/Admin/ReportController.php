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

    /** Liste des membres de la portee avec leurs indicateurs (filtres : actifs, inactifs, profil incomplet, FISS manquante), 60 par page. */
    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:30'],
            'filter' => ['nullable', 'in:all,active,inactive,incomplete,fiss_missing'],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $result = ReportService::memberRows($request->user(), $data['scope'], $data['filter'] ?? 'all', (string) ($data['q'] ?? ''), $page);

        return response()->json([
            'members' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'has_more' => $page * 60 < $result['total'],
        ]);
    }

    /**
     * Export PDF : seule source des donnees du document. Permission verifiee (rapport : reports.export ;
     * liste complete des membres : members.export), portee verifiee, et export journalise dans la meme
     * requete : sans la permission, aucune donnee a mettre en PDF.
     */
    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:30'],
            'kind' => ['required', 'in:report,members'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'filter' => ['nullable', 'in:all,active,inactive,incomplete,fiss_missing'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();
        abort_unless($user->hasPermission($data['kind'] === 'members' ? 'members.export' : 'reports.export'), 403,
            $data['kind'] === 'members' ? 'Vous ne pouvez pas exporter la liste des membres.' : 'Vous ne pouvez pas exporter ce rapport.');

        if ($data['kind'] === 'report') {
            $months = (int) ($data['months'] ?? 6);
            $payload = ['report' => ReportService::build($user, $data['scope'], $months)];
            $context = ['scope' => $data['scope'], 'kind' => 'report', 'months' => $months];
        } else {
            $filter = $data['filter'] ?? 'all';
            $result = ReportService::memberRows($user, $data['scope'], $filter, (string) ($data['q'] ?? ''), null);
            $payload = ['members' => $result['rows'], 'total' => $result['total']];
            $context = ['scope' => $data['scope'], 'kind' => 'members', 'filter' => $filter, 'count' => $result['total']];
        }
        Audit::log('report.exported', null, null, [], $context);

        return response()->json($payload + ['exported_at' => now()->toIso8601String()]);
    }
}
