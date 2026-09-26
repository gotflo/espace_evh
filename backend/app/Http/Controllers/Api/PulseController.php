<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * « Pouls » de l'application : un seul appel leger, toutes les ~45 s, remplace le
 * rafraichissement periodique de chaque ecran. Il renvoie le nombre de notifications non
 * lues et une signature de chaque type de donnees ; un ecran ne se recharge que si la
 * signature de ce qui l'interesse a change. Les signatures sont communes a tous et mises
 * en cache 10 s : 500 membres connectes ne font qu'une serie de requetes toutes les 10 s.
 */
class PulseController extends Controller
{
    private const SOURCES = [
        'announcements' => ['announcements'],
        'events' => ['events', 'event_participations'],
        'exercises' => ['exercises'],
        'requests' => ['member_requests'],
        'members' => ['profiles'],
        'validations' => ['fiss_edit_requests', 'tribe_change_requests'],
        'fiss' => ['spiritual_health_forms'],
        'attendance' => ['attendances'],
    ];

    public function show(Request $request): JsonResponse
    {
        $versions = Cache::remember('pulse:versions', 10, function () {
            $out = [];
            foreach (self::SOURCES as $key => $tables) {
                $parts = [];
                foreach ($tables as $table) {
                    $row = DB::table($table)->selectRaw('count(*) as c, max(id) as m, max(updated_at) as u')->first();
                    $parts[] = $row->c.'.'.$row->m.'.'.$row->u;
                }
                $out[$key] = substr(md5(implode('|', $parts)), 0, 12);
            }

            return $out;
        });

        return response()->json([
            'unread' => $request->user()->notifications()->whereNull('read_at')->count(),
            'versions' => $versions,
        ]);
    }
}
