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
        'validations' => ['fiss_edit_requests', 'tribe_change_requests', 'vertumetre_edit_requests', 'member_deletion_requests', 'department_join_requests'],
        'fiss' => ['spiritual_health_forms'],
        'attendance' => ['attendances'],
        'leader_reports' => ['leader_reports'],
        'gem_reports' => ['gem_weekly_reports'],
        // Contenus publies par les pasteurs seulement (comme les exercices) : la reponse d'un membre ne
        // fait pas recharger l'ecran de toute l'eglise.
        'resolutions' => ['resolution_resources'],
        'assignments' => ['assignments'],
        // Service : demandes d'inscription et decisions des responsables (le membre voit la reponse aussitot).
        'services' => ['department_join_requests', 'departments'],
        // Versets du tableau de bord (publication, programmation, ordre).
        'verses' => ['dashboard_verses'],
        // Reglages de la plateforme (modules actives...) : l'application se met a jour chez chacun.
        'settings' => ['platform_settings'],
        // Rendez-vous : un creneau pris ou libere se voit aussitot chez le protocole et chez les membres.
        'appointments' => ['appointments', 'appointment_windows', 'appointment_blocks', 'appointment_settings'],
    ];

    public function show(Request $request): JsonResponse
    {
        $versions = Cache::remember('pulse:versions', 10, function () {
            $out = [];
            foreach (self::SOURCES as $key => $tables) {
                $parts = [];
                foreach ($tables as $table) {
                    // platform_settings : cle texte (pas de colonne id).
                    $row = DB::table($table)->selectRaw($table === 'platform_settings' ? 'count(*) as c, 0 as m, max(updated_at) as u' : 'count(*) as c, max(id) as m, max(updated_at) as u')->first();
                    $parts[] = $row->c.'.'.$row->m.'.'.$row->u;
                }
                // Nouvelles inscriptions : seulement les demandes et decisions (pas chaque visite d'un membre).
                if ($key === 'validations') {
                    $m = DB::table('users')->selectRaw("sum(case when approval_status = 'pending' then 1 else 0 end) as p, max(approval_requested_at) as r, max(approval_decided_at) as d")->first();
                    $parts[] = $m->p.'.'.$m->r.'.'.$m->d;
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
