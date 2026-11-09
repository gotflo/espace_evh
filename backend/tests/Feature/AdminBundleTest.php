<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\Tribe;
use App\Models\User;
use App\Support\PlatformSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fiche d'un membre ouverte en un seul appel (/admin/bundle) : chaque panneau recoit exactement ce que son appel
 * seul aurait donne, avec les memes refus (droit, portee, module). Rien d'autre ne peut etre regroupe.
 */
class AdminBundleTest extends TestCase
{
    use RefreshDatabase;

    private const PARTS = ['fiss', 'resolutions', 'assignments', 'vertumetre', 'meditations', 'spiritual', 'evaluations', 'deletion', 'exercises', 'fiss-history'];

    private Tribe $juda;

    private Tribe $levi;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->marie = $this->member('+14185556001', $this->juda, 'Marie');
        SpiritualEntry::create(['member_user_id' => $this->marie->id, 'author_user_id' => $this->marie->id, 'type' => 'note', 'entry_date' => '2026-11-01', 'note' => 'Suivi confidentiel']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, Tribe $tribe, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'approval_status' => User::APPROVED]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'is_completed' => true, 'tribe_id' => $tribe->id,
            'gender' => 'femme', 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    /** Pour chaque panneau : meme statut et meme contenu que l'appel seul. */
    private function assertSameAsSeparateCalls(User $viewer, User $target): array
    {
        $paths = array_map(fn ($p) => "/admin/members/{$target->id}/{$p}", self::PARTS);
        $this->as($viewer);
        $bundle = $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => $paths]))->assertOk()->json('responses');
        $statuses = [];
        foreach ($paths as $path) {
            $this->as($viewer);
            $alone = $this->getJson('/api'.$path);
            $this->assertArrayHasKey($path, $bundle);
            $this->assertSame($alone->getStatusCode(), $bundle[$path]['status'], "statut de {$path}");
            if ($alone->getStatusCode() === 200) {
                $this->assertEquals($alone->json(), $bundle[$path]['body'], "contenu de {$path}");
            }
            $statuses[basename($path)] = $bundle[$path]['status'];
        }

        return $statuses;
    }

    public function test_each_panel_gets_exactly_what_its_own_call_gives(): void
    {
        $pastor = $this->give($this->member('+14185556010', $this->juda, 'Pasteur'), 'super_admin');
        $statuses = $this->assertSameAsSeparateCalls($pastor, $this->marie);
        $this->assertSame(200, $statuses['spiritual']);
        $this->assertSame(200, $statuses['fiss']);
    }

    public function test_refusals_are_the_same_as_alone_scope_and_rights(): void
    {
        // Patriarche de Juda : sa tribu oui, Levi non.
        $patriarch = $this->give($this->member('+14185556020', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $paul = $this->member('+14185556002', $this->levi, 'Paul');
        $this->assertSame(200, $this->assertSameAsSeparateCalls($patriarch, $this->marie)['spiritual']);
        $outside = $this->assertSameAsSeparateCalls($patriarch, $paul);
        $this->assertSame(403, $outside['spiritual'], 'hors de sa tribu : refuse, comme seul');

        // Simple fidele : tout refuse, et aucun contenu confidentiel dans la reponse groupee.
        $fidele = $this->member('+14185556030', $this->juda, 'Fidele');
        $this->as($fidele);
        $paths = array_map(fn ($p) => "/admin/members/{$this->marie->id}/{$p}", self::PARTS);
        $response = $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => $paths]))->assertOk();
        $this->assertStringNotContainsString('Suivi confidentiel', $response->getContent());
        foreach ($response->json('responses') as $path => $one) {
            $this->assertContains($one['status'], [403, 404], $path);
        }
        $this->assertSameAsSeparateCalls($fidele, $this->marie);
    }

    public function test_the_leaders_home_blocks_are_the_same_in_one_call(): void
    {
        $patriarch = $this->give($this->member('+14185556020', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $pastor = $this->give($this->member('+14185556010', $this->juda, 'Pasteur'), 'super_admin');
        $fidele = $this->member('+14185556030', $this->juda, 'Fidele');
        $paths = ['/admin/stats', '/admin/requests', '/admin/new-members?filter=to_welcome', '/admin/leader-reports', '/admin/validations/count', '/admin/gem-reports'];
        foreach (['patriarche' => $patriarch, 'pasteur' => $pastor, 'fidele' => $fidele] as $label => $viewer) {
            $this->as($viewer);
            $bundle = $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => $paths]))->assertOk()->json('responses');
            foreach ($paths as $path) {
                $this->as($viewer);
                $alone = $this->getJson('/api'.$path);
                $this->assertSame($alone->getStatusCode(), $bundle[$path]['status'], "{$label} : statut de {$path}");
                if ($alone->getStatusCode() === 200) {
                    $this->assertEquals($alone->json(), $bundle[$path]['body'], "{$label} : contenu de {$path}");
                }
            }
        }
        $this->as($fidele);
        $this->assertSame(403, $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => ['/admin/stats']]))->json('responses./admin/stats.status'));
    }

    public function test_a_closed_module_stays_closed_in_the_bundle(): void
    {
        $pastor = $this->give($this->member('+14185556010', $this->juda, 'Pasteur'), 'super_admin');
        PlatformSettings::update(['module.meditation' => false, 'module.vertumetre' => false, 'module.resolutions' => false, 'module.assignments' => false], null);
        $statuses = $this->assertSameAsSeparateCalls($pastor, $this->marie);
        // Module coupe : ferme aussi dans la fiche d'un membre (meditation, Vertumetre, RM, DS).
        foreach (['meditations', 'vertumetre', 'resolutions', 'assignments'] as $part) {
            $this->assertSame(403, $statuses[$part], $part);
        }
        $this->assertSame(200, $statuses['spiritual']);
    }

    public function test_only_listed_reads_can_be_bundled_and_a_session_is_required(): void
    {
        $pastor = $this->give($this->member('+14185556010', $this->juda, 'Pasteur'), 'super_admin');
        $this->as($pastor);
        $others = ['/admin/members', '/admin/settings', '/me', "/admin/members/{$this->marie->id}", "/admin/members/{$this->marie->id}/roles",
            '/admin/members/../settings', "/admin/members/{$this->marie->id}/fiss/../../settings", 'https://exemple.test/admin/members/1/fiss'];
        $r = $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => $others]))->assertOk()->json('responses');
        foreach ($others as $path) {
            $this->assertSame(['status' => 404], $r[$path], $path);
        }
        $this->getJson('/api/admin/bundle')->assertStatus(422);
        $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => array_map(fn ($i) => "/x{$i}", range(1, 13))]))->assertStatus(422);

        // Apres le regroupement, l'appel principal garde son utilisateur et sa route.
        $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => ["/admin/members/{$this->marie->id}/spiritual"]]))->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $pastor->id);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/bundle?'.http_build_query(['paths' => ["/admin/members/{$this->marie->id}/spiritual"]]))->assertUnauthorized();
    }
}
