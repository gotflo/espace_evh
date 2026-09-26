<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Gem;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TribesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Mesures de volume (hors suite normale) : php artisan test --group=benchmark
 * Tailles : variable BENCH_SIZES (defaut 500,1000,2000,5000).
 *
 * Donnees realistes par membre : tribu, GEM, departement, 12 mois de FISS (2 sur 3 remplies),
 * presence hebdomadaire sur 3 mois, 20 notifications, 2 notes, une partie avec conjoint.
 * Chaque ecran est appele 10 fois (cache des rapports vide avant chaque appel) :
 * mediane et pire temps, nombre de requetes SQL. Resultats ecrits dans
 * storage/app/benchmarks/volume-AAAAMMJJ-HHMM.json. Temps mesures dans le processus
 * (SQLite en memoire, sans reseau) : a comparer entre tailles, pas avec la production.
 */
#[Group('benchmark')]
class VolumeBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private int $count = 0;

    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '1024M');
        Carbon::setTestNow(Carbon::parse('2026-10-17 18:10:00')); // samedi soir : recapitulatif des cultes
        Http::fake(); // aucun appel reseau reel
        $this->seed([RolesAndPermissionsSeeder::class, TribesSeeder::class, DepartmentsSeeder::class]);
        foreach (Tribe::all() as $tribe) {
            Gem::create(['name' => 'GEM '.$tribe->name, 'tribe_id' => $tribe->id]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grow(int $target): void
    {
        $tribes = Tribe::pluck('id')->all();
        $gems = Gem::pluck('id', 'tribe_id')->all();
        $departments = Department::pluck('id')->all();
        $fidele = Role::where('key', 'fidele')->value('id');
        $now = now();
        $periods = [];
        for ($m = 11; $m >= 0; $m--) {
            $periods[] = $now->copy()->subMonthsNoOverflow($m)->format('Y-m');
        }
        $sundays = [];
        for ($w = 1; $w <= 13; $w++) {
            $sundays[] = $now->copy()->subWeeks($w)->startOfWeek()->addDays(6)->toDateString();
        }

        while ($this->count < $target) {
            $batch = min(250, $target - $this->count);
            $users = [];
            for ($i = 0; $i < $batch; $i++) {
                $n = $this->count + $i + 1;
                $users[] = ['phone' => '+1418'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                    'last_login_at' => $now->copy()->subDays($n % 150), 'last_seen_at' => $now->copy()->subDays($n % 120),
                    'activity_status' => $n % 7 === 0 ? 'inactive' : 'active',
                    'created_at' => $now->copy()->subDays($n % 400), 'updated_at' => $now];
            }
            DB::table('users')->insert($users);
            $ids = DB::table('users')->orderByDesc('id')->limit($batch)->pluck('id')->reverse()->values();

            $profiles = $roles = $depts = $forms = $att = $notifs = $evals = [];
            foreach ($ids as $k => $uid) {
                $n = $this->count + $k + 1;
                $tribe = $tribes[$n % count($tribes)];
                $profiles[] = ['user_id' => $uid, 'first_name' => 'Membre'.$n, 'last_name' => 'Nom'.($n % 97), 'tribe_id' => $tribe,
                    'gem_id' => $gems[$tribe], 'is_completed' => true, 'birth_day' => ($n % 28) + 1, 'birth_month' => ($n % 12) + 1,
                    'marital_status' => $n % 3 === 0 ? 'marie' : 'celibataire', 'wedding_day' => $n % 3 === 0 ? ($n % 28) + 1 : null,
                    'wedding_month' => $n % 3 === 0 ? (($n + 5) % 12) + 1 : null, 'completion' => 50 + $n % 51,
                    'created_at' => $now->copy()->subDays($n % 400), 'updated_at' => $now];
                $roles[] = ['user_id' => $uid, 'role_id' => $fidele];
                foreach ($periods as $p => $period) {
                    if (($n + $p) % 3) {
                        $forms[] = ['user_id' => $uid, 'period' => $period, 'meditation' => 8 + $n % 12, 'priere' => 10 + $p % 10,
                            'jeune' => 5 + $n % 15, 'sanctification_corps' => ['bien', 'moyen', 'mal'][$n % 3],
                            'situation_familiale' => 10 + $n % 10, 'created_at' => $now, 'updated_at' => $now, 'locked_at' => $now];
                    }
                }
                foreach ($sundays as $s => $day) {
                    if (($n + $s) % 4) {
                        $att[] = ['member_user_id' => $uid, 'attended_on' => $day, 'event' => 'Culte', 'kind' => 'culte',
                            'status' => $s % 5 ? 'present' : 'retard', 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                for ($j = 0; $j < 20; $j++) {
                    $notifs[] = ['user_id' => $uid, 'type' => 'announcement', 'title' => 'Annonce '.$j, 'priority' => 'normal',
                        'read_at' => $j < 15 ? $now : null, 'created_at' => $now->copy()->subDays($j)];
                }
                $evals[] = ['user_id' => $uid, 'type' => 'meditation_perso', 'score' => 10 + $n % 10, 'max_score' => 20,
                    'evaluated_on' => $now->copy()->subDays(10)->toDateString(), 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('profiles')->insert($profiles);
            DB::table('role_user')->insert($roles);
            $pids = DB::table('profiles')->whereIn('user_id', $ids)->pluck('id', 'user_id');
            foreach ($ids as $k => $uid) {
                $depts[] = ['profile_id' => $pids[$uid], 'department_id' => $departments[($this->count + $k) % count($departments)]];
            }
            DB::table('department_profile')->insert($depts);
            foreach ([$forms, $att, $notifs, $evals] as $rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table(match (true) {
                        isset($chunk[0]['period']) => 'spiritual_health_forms',
                        isset($chunk[0]['attended_on']) => 'attendances',
                        isset($chunk[0]['priority']) => 'user_notifications',
                        default => 'evaluations',
                    })->insert($chunk);
                }
            }
            $this->count += $batch;
        }
    }

    /** @return array{median: float, worst: float, queries: int} */
    private function measure(User $as, string $url, int $runs = 10): array
    {
        Sanctum::actingAs($as);
        $times = [];
        $queries = 0;
        for ($r = 0; $r < $runs; $r++) {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t = hrtime(true);
            $status = $this->getJson($url)->status();
            $times[] = (hrtime(true) - $t) / 1e6;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame(200, $status, $url);
        }
        sort($times);

        return ['median' => round($times[intdiv(count($times), 2)], 1), 'worst' => round(end($times), 1), 'queries' => $queries];
    }

    public function test_volume(): void
    {
        $sizes = array_map('intval', explode(',', (string) (getenv('BENCH_SIZES') ?: '500,1000,2000,5000')));
        $pastor = User::create(['phone' => '+14189990000']);
        DB::table('profiles')->insert(['user_id' => $pastor->id, 'first_name' => 'Pasteur', 'last_name' => 'Test', 'is_completed' => true,
            'tribe_id' => Tribe::value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $ap = User::create(['phone' => '+14189990001']);
        DB::table('profiles')->insert(['user_id' => $ap->id, 'first_name' => 'AP', 'last_name' => 'Test', 'is_completed' => true,
            'created_at' => now(), 'updated_at' => now()]);
        foreach (Tribe::limit(3)->pluck('id') as $tid) {
            $ap->roles()->attach(Role::where('key', 'assistant_pasteur')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => $tid]);
        }
        $exercise = $this->makeExercise(['title' => 'Vidéo', 'content' => '', 'type' => 'video', 'video_id' => 'KSvbDy7Yv7k',
            'requires_response' => false, 'is_active' => true]);
        for ($e = 0; $e < 30; $e++) {
            $this->makeEvent(['title' => 'Événement '.$e, 'category' => 'autre', 'starts_at' => now()->addDays($e % 20)->setTime(19, 0)]);
        }

        foreach ($sizes as $size) {
            $t = hrtime(true);
            $this->grow($size);
            $build = round((hrtime(true) - $t) / 1e9, 1);
            $member = User::whereHas('profile', fn ($q) => $q->where('first_name', 'Membre7'))->firstOrFail();

            $screens = [
                'Pasteur · statistiques' => [$pastor, '/api/admin/stats'],
                'Pasteur · membres (page 1)' => [$pastor, '/api/admin/members'],
                'Pasteur · recherche membre' => [$pastor, '/api/admin/members?q=Membre12'],
                'Pasteur · rapport église 12 mois (sans cache)' => [$pastor, '/api/admin/reports?scope=church&months=12'],
                'Pasteur · rapport liste membres (page 1)' => [$pastor, '/api/admin/reports/members?scope=church&filter=all'],
                'Pasteur · suivi exercice vidéo' => [$pastor, '/api/admin/exercises/'.$exercise->id.'/tracking'],
                'AP 3 tribus · rapport (sans cache)' => [$ap, '/api/admin/reports?scope=mine&months=6'],
                'AP 3 tribus · membres (page 1)' => [$ap, '/api/admin/members'],
                'Membre · pouls (toutes les 45 s)' => [$member, '/api/me/pulse'],
                'Membre · profil /me' => [$member, '/api/me'],
                'Membre · ma vie spirituelle' => [$member, '/api/me/overview'],
                'Membre · calendrier du mois' => [$member, '/api/calendar?from=2026-10-01&to=2026-10-31'],
                'Membre · notifications' => [$member, '/api/me/notifications'],
            ];
            foreach ($screens as $label => [$user, $url]) {
                $this->results[$size][$label] = $this->measure($user, $url);
            }

            // Automatismes : recapitulatif des cultes du samedi soir a tous les membres, etc.
            DB::table('notification_dispatches')->delete();
            $t = hrtime(true);
            $this->artisan('app:tick')->assertSuccessful();
            $this->results[$size]['Automatismes · passage complet (app:tick)'] = ['median' => round((hrtime(true) - $t) / 1e6, 1), 'worst' => null, 'queries' => null];
            $this->results[$size]['_tick_steps'] = Cache::get(\App\Console\Commands\AutomationTick::STATUS_KEY)['steps'] ?? null;
            $this->results[$size]['_build_seconds'] = $build;
            $this->results[$size]['_rows'] = [
                'fiss' => DB::table('spiritual_health_forms')->count(),
                'presences' => DB::table('attendances')->count(),
                'notifications' => DB::table('user_notifications')->count(),
            ];
        }

        // Rapport lisible
        $labels = array_keys(array_filter($this->results[$sizes[0]], fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY));
        $lines = [sprintf('%-48s', 'Écran (médiane / pire, ms · requêtes SQL)').implode('', array_map(fn ($s) => sprintf('%24s', $s.' membres'), $sizes))];
        foreach ($labels as $label) {
            $row = sprintf('%-48s', $label);
            foreach ($sizes as $s) {
                $r = $this->results[$s][$label];
                $row .= sprintf('%24s', $r['worst'] === null ? $r['median'].' ms' : $r['median'].' / '.$r['worst'].' · '.$r['queries'].'q');
            }
            $lines[] = $row;
        }
        foreach ($sizes as $s) {
            $lines[] = "{$s} membres : ".json_encode($this->results[$s]['_rows']).", données créées en {$this->results[$s]['_build_seconds']} s";
            $lines[] = '   étapes app:tick : '.json_encode($this->results[$s]['_tick_steps'], JSON_UNESCAPED_UNICODE);
        }
        fwrite(STDERR, "\n".implode("\n", $lines)."\n");

        $dir = storage_path('app/benchmarks');
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/volume-'.date('Ymd-Hi').'.json', json_encode([
            'php' => PHP_VERSION, 'database' => DB::getDriverName(), 'sizes' => $sizes, 'results' => $this->results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Garde-fou : le pouls (appele par chaque appareil) reste constant et rapide.
        $this->assertLessThanOrEqual($this->results[$sizes[0]]['Membre · pouls (toutes les 45 s)']['queries'] + 2,
            $this->results[end($sizes)]['Membre · pouls (toutes les 45 s)']['queries']);
    }
}
