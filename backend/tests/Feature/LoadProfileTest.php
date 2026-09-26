<?php

namespace Tests\Feature;

use App\Models\Department;
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
use Tests\TestCase;

/**
 * Tenue en charge : le nombre de requetes SQL de chaque ecran ne doit pas grandir avec le
 * nombre de membres (sinon chaque membre ajoute ralentit tout le monde, probleme « N+1 »).
 * On mesure chaque ecran avec 60 puis 400 membres et on compare.
 */
class LoadProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @var array<string, int> duree (ms) du dernier appel de chaque ecran */
    private array $timings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        Http::preventStrayRequests();
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

    /** Ajoute $count membres realistes (tribu, GEM, departements, FISS, presences, notifications). */
    private function populate(int $count): void
    {
        $tribes = Tribe::pluck('id')->all();
        $gems = Gem::pluck('id', 'tribe_id')->all();
        $departments = Department::pluck('id')->all();
        $fidele = Role::where('key', 'fidele')->value('id');
        $now = now();

        for ($i = 0; $i < $count; $i++) {
            $n = ++$this->seq;
            $tribe = $tribes[$n % count($tribes)];
            $userId = DB::table('users')->insertGetId(['phone' => '+1418'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                'last_login_at' => $now->copy()->subDays($n % 120), 'created_at' => $now->copy()->subDays($n % 200), 'updated_at' => $now]);
            $profileId = DB::table('profiles')->insertGetId(['user_id' => $userId, 'first_name' => 'Membre'.$n, 'last_name' => 'Test',
                'tribe_id' => $tribe, 'gem_id' => $gems[$tribe], 'is_completed' => true, 'birth_day' => ($n % 28) + 1, 'birth_month' => ($n % 12) + 1,
                'completion' => 50 + $n % 51, 'created_at' => $now->copy()->subDays($n % 200), 'updated_at' => $now]);
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $fidele]);
            DB::table('department_profile')->insert(['profile_id' => $profileId, 'department_id' => $departments[$n % count($departments)]]);
            foreach (['2026-08', '2026-09', '2026-10'] as $k => $period) {
                if (($n + $k) % 3) {
                    DB::table('spiritual_health_forms')->insert(['user_id' => $userId, 'period' => $period, 'meditation' => 10 + $n % 10,
                        'priere' => 12, 'jeune' => 8, 'sanctification_corps' => 'bien', 'created_at' => $now, 'updated_at' => $now, 'locked_at' => $now]);
                }
            }
            DB::table('attendances')->insert(['member_user_id' => $userId, 'attended_on' => '2026-10-11', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('user_notifications')->insert(['user_id' => $userId, 'type' => 'system', 'title' => 'Bienvenue', 'created_at' => $now]);
        }
    }

    private function pastor(): User
    {
        $u = User::create(['phone' => '+14189990000']);
        DB::table('profiles')->insert(['user_id' => $u->id, 'first_name' => 'Pasteur', 'last_name' => 'Test', 'is_completed' => true,
            'tribe_id' => Tribe::value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $u->roles()->attach(Role::where('key', 'super_admin')->value('id'));

        return $u;
    }

    /** @return array<string, int> nombre de requetes SQL par ecran */
    private function measure(User $as, array $urls): array
    {
        Sanctum::actingAs($as);
        $out = [];
        foreach ($urls as $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t = microtime(true);
            $status = $this->getJson($url)->status();
            $this->timings[$url] = (int) round((microtime(true) - $t) * 1000);
            DB::disableQueryLog();
            $this->assertSame(200, $status, "{$url} doit repondre 200");
            $out[$url] = count(DB::getQueryLog());
        }

        return $out;
    }

    public function test_screens_do_not_slow_down_as_the_church_grows(): void
    {
        $pastor = $this->pastor();
        $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => '2026-10-18 09:30', 'recurrence' => 'weekly']);
        $this->makeExercise(['title' => 'Lire Jean 3', 'content' => '...', 'type' => 'lecture', 'is_active' => true]);
        foreach (Tribe::limit(6)->get() as $tribe) {
            $this->makeExercise(['title' => 'Vidéo '.$tribe->name, 'content' => '', 'type' => 'video', 'video_id' => 'KSvbDy7Yv7k',
                'requires_response' => false, 'is_active' => true], [['type' => 'tribe', 'id' => $tribe->id]]);
        }

        $admin = ['/api/me', '/api/admin/stats', '/api/admin/members', '/api/admin/members?status=inactive', '/api/admin/new-members',
            '/api/admin/reports?scope=church&months=12', '/api/admin/reports/members?scope=church&filter=all', '/api/admin/attendance',
            '/api/admin/exercises', '/api/admin/events', '/api/admin/announcements', '/api/admin/validations', '/api/admin/audiences',
            '/api/admin/gems', '/api/admin/organization', '/api/admin/audit', '/api/calendar?from=2026-10-01&to=2026-10-31',
            '/api/admin/exercises/1/tracking'];

        $this->populate(60);
        Cache::flush();
        $small = $this->measure($pastor, $admin);
        $member = User::whereHas('profile', fn ($q) => $q->where('first_name', 'Membre7'))->firstOrFail();
        $mine = ['/api/me', '/api/me/overview', '/api/me/exercises', '/api/me/notifications', '/api/me/notifications/unread-count',
            '/api/me/fiss', '/api/me/announcements', '/api/me/events', '/api/calendar?from=2026-10-01&to=2026-10-31', '/api/me/services'];
        $smallMine = $this->measure($member, $mine);

        $this->populate(340);
        Cache::flush();
        $this->timings = [];
        $large = $this->measure($pastor, $admin);
        $largeMine = $this->measure($member, $mine);
        $slowest = $this->timings;
        arsort($slowest);

        $report = [];
        foreach ([[$small, $large], [$smallMine, $largeMine]] as [$a, $b]) {
            foreach ($a as $url => $q) {
                $report[] = sprintf('%-58s %4d -> %4d requetes', $url, $q, $b[$url]);
                // Quelques requetes de plus sont admises (lots de 200/500), jamais une par membre.
                $this->assertLessThanOrEqual($q + 12, $b[$url], "{$url} : {$q} requetes a 60 membres, {$b[$url]} a 400 (N+1).");
            }
        }
        fwrite(STDERR, "\nRequetes SQL par ecran (60 -> 400 membres)\n".implode("\n", $report)."\n");
        fwrite(STDERR, 'Ecrans les plus lents a 400 membres (ms) : '.json_encode(array_slice($slowest, 0, 6, true))."\n");
        // Garde-fou : aucun ecran ne depasse 1,5 s, meme le rapport annuel calcule sans cache.
        $this->assertLessThan(1500, max($slowest));
    }
}
