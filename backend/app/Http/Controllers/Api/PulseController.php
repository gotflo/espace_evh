<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PlatformManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * « Pouls » de l'application : un seul appel leger, toutes les ~45 s, remplace le
 * rafraichissement periodique de chaque ecran. Il renvoie le nombre de notifications non
 * lues et une signature de chaque type de donnees ; un ecran ne se recharge que si la
 * signature de ce qui l'interesse a change. Les signatures communes sont mises en cache 10 s
 * et calculees par un seul appel a la fois : 500 membres connectes ne font qu'une serie de
 * requetes, meme si les modifications s'enchainent.
 *
 * Deux sortes de signatures :
 * - communes (fiss, attendance, members...) : pour les ecrans des responsables ;
 * - personnelle (mine) : ce qui ne concerne que ce membre (sa FISS, ses reponses, ses presences...).
 *   Les ecrans des membres l'utilisent : l'action d'un membre ne recharge pas toute l'eglise.
 */
class PulseController extends Controller
{
    /** Signatures communes : ['at' => moment du calcul, 'versions' => [...]]. */
    public const CACHE_KEY = 'pulse:versions';

    /** Dernier calcul connu, servi quand un autre appel est deja en train de calculer. */
    private const LAST_KEY = 'pulse:versions:last';

    /** Moment de la derniere modification reussie (toute l'application). */
    private const CHANGED_KEY = 'pulse:changed';

    /** Nombre de lignes des grosses tables (voir BIG_TABLES). */
    private const COUNTS_KEY = 'pulse:big-counts';

    private const CACHE_SECONDS = 10;

    /** Apres une modification, les autres membres attendent au plus ce delai (secondes) ; l'auteur, non. */
    private const MIN_REFRESH_SECONDS = 3.0;

    private const COUNTS_SECONDS = 300;

    private const SOURCES = [
        'announcements' => ['announcements'],
        // La reponse d'un membre ne recharge pas l'ecran des autres : elle compte pour les responsables seulement (voir show).
        'events' => ['events'],
        'event_responses' => ['event_participations'],
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

    /**
     * Grosses tables : max(id) et max(updated_at) se lisent par index, mais count(*) parcourt toute la table.
     * Leur nombre de lignes (seul moyen de voir une suppression ancienne) est relu toutes les 5 min, ou
     * tout de suite apres une suppression de presences (forgetCounts).
     */
    private const BIG_TABLES = ['event_participations', 'profiles', 'spiritual_health_forms', 'attendances', 'gem_weekly_reports', 'member_requests'];

    /** Ce qui ne concerne que le membre : table => colonne du membre (tout passe par un index sur cette colonne). */
    private const PERSONAL = [
        'spiritual_health_forms' => 'user_id',
        'fiss_edit_requests' => 'user_id',
        'vertumetre_forms' => 'user_id',
        'vertumetre_edit_requests' => 'user_id',
        'monthly_resolutions' => 'user_id',
        'assignment_responses' => 'user_id',
        'evaluations' => 'user_id',
        'attendances' => 'member_user_id',
        'event_participations' => 'user_id',
        'appointments' => 'user_id',
    ];

    /** Permissions qui montrent les reponses aux evenements : leurs ecrans suivent ces reponses en direct. */
    private const EVENT_RESPONSE_PERMISSIONS = ['events.manage', 'events.participants'];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $personal = self::personal($user);
        $versions = self::common((int) $user->id);

        // Reponses aux evenements : dans la signature « events » des responsables, jamais des membres.
        $responses = $versions['event_responses'] ?? '';
        unset($versions['event_responses']);
        if ($personal['sees_responses']) {
            $versions['events'] = substr(md5(($versions['events'] ?? '').'|'.$responses), 0, 12);
        }
        $versions['mine'] = $personal['signature'];

        return response()->json([
            'unread' => $user->notifications()->whereNull('read_at')->count(),
            'versions' => $versions,
        ]);
    }

    /**
     * Une modification vient de reussir (voir RefreshPulse). Les signatures ne sont pas effacees : l'auteur
     * verra son changement a son prochain appel, les autres au plus tard MIN_REFRESH_SECONDS apres le calcul
     * precedent. Une rafale de modifications ne fait donc plus recalculer a chaque appel.
     */
    public static function markChanged(?int $userId): void
    {
        $now = microtime(true);
        Cache::put(self::CHANGED_KEY, $now, 60);
        if ($userId) {
            Cache::put(self::wroteKey($userId), $now, self::CACHE_SECONDS + 5);
        }
    }

    /** Des lignes viennent d'etre supprimees d'une grosse table : le prochain calcul les recompte. */
    public static function forgetCounts(): void
    {
        Cache::forget(self::COUNTS_KEY);
    }

    private static function wroteKey(int $userId): string
    {
        return 'pulse:wrote:'.$userId;
    }

    /** Les signatures en cache sont-elles encore bonnes pour cet appel ? */
    private static function usable(mixed $entry, float $wrote): bool
    {
        if (! is_array($entry) || ! isset($entry['at']) || $entry['at'] < $wrote) {
            return false;
        }

        return $entry['at'] >= (float) Cache::get(self::CHANGED_KEY, 0) || microtime(true) - $entry['at'] < self::MIN_REFRESH_SECONDS;
    }

    /**
     * Un seul calcul a la fois. Pendant un calcul, les autres appels recoivent aussitot le dernier resultat
     * connu au lieu d'attendre (jamais d'empilement) ; seul l'auteur d'une modification attend brievement,
     * pour voir son changement, et un delai depasse ne relance jamais de calcul supplementaire.
     *
     * @return array<string, string>
     */
    private static function common(int $userId): array
    {
        $entry = Cache::get(self::CACHE_KEY);
        // Ancien format encore en cache juste apres une mise a jour : ignore.
        $entry = is_array($entry) && isset($entry['at'], $entry['versions']) ? $entry : null;
        $wrote = (float) Cache::get(self::wroteKey($userId), 0);
        if (self::usable($entry, $wrote)) {
            return $entry['versions'];
        }

        $compute = function () use ($wrote) {
            $current = Cache::get(self::CACHE_KEY);
            if (self::usable($current, $wrote)) {
                return $current['versions'];
            }
            $at = microtime(true);
            $versions = self::versions();
            Cache::put(self::CACHE_KEY, ['at' => $at, 'versions' => $versions], self::CACHE_SECONDS);
            Cache::put(self::LAST_KEY, $versions, 86400);

            return $versions;
        };

        $lock = Cache::lock('pulse:compute', self::CACHE_SECONDS);
        if ($lock->get()) {
            try {
                return $compute();
            } finally {
                $lock->release();
            }
        }

        $stale = is_array($entry) ? $entry['versions'] : Cache::get(self::LAST_KEY);
        if (is_array($stale) && ! (is_array($entry) && $entry['at'] < $wrote)) {
            return $stale;
        }
        try {
            return $lock->block(2, $compute);
        } catch (LockTimeoutException) {
            return is_array($stale) ? $stale : [];
        }
    }

    /**
     * Signature personnelle, en une seule requete (chaque partie passe par l'index du membre), et droit de
     * voir les reponses aux evenements (sans charger tous ses roles a chaque pouls).
     *
     * @return array{signature: string, sees_responses: bool}
     */
    private static function personal(User $user): array
    {
        $id = (int) $user->id;
        $query = DB::table('events')->where('created_by', $id)->where('is_personal', true)->selectRaw('count(*) as c, max(updated_at) as u');
        foreach (self::PERSONAL as $table => $column) {
            $query->unionAll(DB::table($table)->where($column, $id)->selectRaw('count(*) as c, max(updated_at) as u'));
        }
        // Lecture terminee : l'avancement ne compte pas, il change a chaque signal de lecture.
        $query->unionAll(DB::table('assignment_views')->where('user_id', $id)->whereNotNull('completed_at')->selectRaw('count(*) as c, null as u'));
        $query->unionAll(DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->leftJoin('permission_role as pr', 'pr.role_id', '=', 'ru.role_id')
            ->leftJoin('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('ru.user_id', $id)
            ->where(fn (Builder $w) => $w->where('r.key', User::SUPER_ADMIN)->orWhereIn('p.key', self::EVENT_RESPONSE_PERMISSIONS))
            ->selectRaw('count(*) as c, null as u'));
        // Responsable de departement : memes droits que ses roles (voir User::DEPARTMENT_LEADER_PERMISSIONS).
        $query->unionAll(DB::table('department_leaders')->where('user_id', $id)->selectRaw('count(*) as c, null as u'));

        $rows = $query->get();
        $leads = $rows->pop();
        $role = $rows->pop();
        $seesResponses = $user->isPlatformManager()
            ? collect(self::EVENT_RESPONSE_PERMISSIONS)->contains(fn ($key) => PlatformManager::allows($key))
            : (int) $role->c > 0 || (int) $leads->c > 0;

        return [
            'signature' => substr(md5($rows->map(fn ($r) => $r->c.'.'.$r->u)->implode('|')), 0, 12),
            'sees_responses' => $seesResponses,
        ];
    }

    /** @return array<string, string> signature courte de chaque source */
    private static function versions(): array
    {
        $tables = collect(self::SOURCES)->flatten()->unique()->values()->all();
        $query = DB::query();
        foreach ($tables as $i => $table) {
            // platform_settings : cle texte (pas de colonne id).
            if ($table !== 'platform_settings') {
                $query->selectSub(self::source($table)->selectRaw('max(id)'), 'm'.$i);
            }
            $query->selectSub(self::source($table)->selectRaw('max(updated_at)'), 'u'.$i);
            if (! in_array($table, self::BIG_TABLES, true)) {
                $query->selectSub(self::source($table)->selectRaw('count(*)'), 'c'.$i);
            }
        }
        // Nouvelles inscriptions : seulement les demandes et decisions (pas chaque visite d'un membre).
        $query->selectSub(DB::table('users')->where('approval_status', 'pending')->selectRaw('count(*)'), 'pending')
            ->selectSub(DB::table('users')->selectRaw('max(approval_requested_at)'), 'requested')
            ->selectSub(DB::table('users')->selectRaw('max(approval_decided_at)'), 'decided');
        $row = $query->first();

        $counts = Cache::remember(self::COUNTS_KEY, self::COUNTS_SECONDS, function () {
            $out = [];
            foreach (self::BIG_TABLES as $table) {
                $out[$table] = DB::table($table)->count();
            }

            return $out;
        });

        $signatures = [];
        foreach ($tables as $i => $table) {
            $signatures[$table] = ($counts[$table] ?? $row->{'c'.$i}).'.'.($row->{'m'.$i} ?? 0).'.'.$row->{'u'.$i};
        }

        $out = [];
        foreach (self::SOURCES as $key => $sources) {
            $parts = array_map(fn ($table) => $signatures[$table], $sources);
            if ($key === 'validations') {
                $parts[] = $row->pending.'.'.$row->requested.'.'.$row->decided;
            }
            $out[$key] = substr(md5(implode('|', $parts)), 0, 12);
        }

        return $out;
    }

    /** L'agenda personnel d'un membre ne concerne que lui (voir personal) : il ne recharge pas le calendrier des autres. */
    private static function source(string $table): Builder
    {
        return $table === 'events' ? DB::table('events')->where('is_personal', false) : DB::table($table);
    }
}
