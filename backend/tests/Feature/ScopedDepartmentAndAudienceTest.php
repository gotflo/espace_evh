<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit du 2026-11-07 : un droit recu par un role de departement ne vaut que pour ce departement (gestion des
 * departements, inscriptions aux services, destinataires des publications). En production, ce role porte en plus
 * departments.manage, members.edit et announcements.publish : on les lui donne ici aussi.
 */
class ScopedDepartmentAndAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Department $choir;

    private Department $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $ids = DB::table('permissions')->whereIn('key', ['departments.manage', 'departments.approve', 'members.edit', 'announcements.publish', 'events.manage'])->pluck('id');
        Role::where('key', 'responsable_departement')->first()->permissions()->syncWithoutDetaching($ids);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->choir = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $this->kitchen = Department::create(['name' => 'Cuisine', 'slug' => 'cuisine', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, array $extra = []): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create($extra + ['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'is_completed' => true, 'tribe_id' => $this->juda->id]);

        return $user;
    }

    private function choirLeader(): User
    {
        $leader = $this->member('+14185558001', 'Chef');
        $leader->roles()->attach(Role::where('key', 'responsable_departement')->value('id'), ['scope_kind' => 'department', 'scope_id' => $this->choir->id]);
        $this->choir->leaders()->attach($leader->id);
        $leader->profile->departments()->attach($this->choir->id);

        return $leader->fresh();
    }

    public function test_a_department_leader_manages_only_his_own_department(): void
    {
        $leader = $this->choirLeader();
        Sanctum::actingAs($leader);

        $org = $this->getJson('/api/admin/organization')->assertOk();
        $this->assertFalse($org->json('can_create_departments'));
        $depts = collect($org->json('departments'))->keyBy('name');
        $this->assertTrue($depts['Chorale']['can_edit']);
        $this->assertFalse($depts['Chorale']['can_delete']);
        $this->assertFalse($depts['Chorale']['can_appoint']);
        $this->assertFalse($depts['Cuisine']['can_edit']);

        $this->postJson('/api/admin/departments', ['name' => 'Nouveau'])->assertForbidden();
        $this->deleteJson("/api/admin/departments/{$this->kitchen->id}")->assertForbidden();
        $this->putJson("/api/admin/departments/{$this->kitchen->id}", ['name' => 'Piraté'])->assertForbidden();
        $this->putJson("/api/admin/departments/{$this->kitchen->id}/leaders", ['user_ids' => [$leader->id]])->assertForbidden();
        $this->getJson("/api/admin/departments/{$this->kitchen->id}/members")->assertForbidden();
        $this->putJson("/api/admin/departments/{$this->choir->id}", ['tracks_rehearsal' => true])->assertOk();
        $this->putJson("/api/admin/departments/{$this->choir->id}/leaders", ['user_ids' => [$leader->id]])->assertForbidden();
        $this->assertSame('Cuisine', $this->kitchen->fresh()->name);

        // Un pasteur gere tous les departements.
        $pastor = $this->member('+14185558002', 'Pasteur');
        $pastor->roles()->attach(Role::where('key', 'pasteur_assistant')->value('id'));
        Sanctum::actingAs($pastor->fresh());
        $org = $this->getJson('/api/admin/organization')->assertOk();
        $this->assertTrue($org->json('can_create_departments'));
        $this->assertTrue(collect($org->json('departments'))->firstWhere('name', 'Cuisine')['can_delete']);
        $this->postJson('/api/admin/departments', ['name' => 'Accueil'])->assertOk();
    }

    public function test_service_join_requests_reach_and_are_decided_only_by_that_department(): void
    {
        $leader = $this->choirLeader();
        $paul = $this->member('+14185558010', 'Paul');
        $anne = $this->member('+14185558011', 'Anne');
        Sanctum::actingAs($paul);
        $this->postJson("/api/me/services/{$this->kitchen->id}/join")->assertSuccessful();
        Sanctum::actingAs($anne);
        $this->postJson("/api/me/services/{$this->choir->id}/join")->assertSuccessful();
        $kitchenRequest = DepartmentJoinRequest::where('department_id', $this->kitchen->id)->sole();
        $choirRequest = DepartmentJoinRequest::where('department_id', $this->choir->id)->sole();

        Sanctum::actingAs($leader);
        $services = collect($this->getJson('/api/admin/validations')->assertOk()->json('services'));
        $this->assertSame([$choirRequest->id], $services->pluck('id')->all(), 'seulement la demande de SON departement');
        $this->postJson("/api/admin/validations/services/{$kitchenRequest->id}", ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $kitchenRequest->fresh()->status);
        $this->postJson("/api/admin/validations/services/{$choirRequest->id}", ['decision' => 'approved'])->assertOk();
    }

    public function test_publishing_uses_only_the_scope_of_the_role_that_grants_the_right(): void
    {
        $gem = Gem::create(['name' => 'GEM A', 'tribe_id' => $this->juda->id]);
        $leader = $this->choirLeader();
        $leader->roles()->attach(Role::where('key', 'garde')->value('id'), ['scope_kind' => 'gem', 'scope_id' => $gem->id]);
        $leader = $leader->fresh();
        Sanctum::actingAs($leader);

        // Annonces : droit recu par le role de departement seulement -> son departement, pas son GEM.
        $ann = $this->getJson('/api/admin/audiences?for=announcements')->assertOk();
        $this->assertSame([], $ann->json('gems'));
        $this->assertSame([$this->choir->id], collect($ann->json('departments'))->pluck('id')->all());
        $this->postJson('/api/admin/announcements', ['title' => 'Info', 'body' => 'Texte', 'category' => 'info', 'scopes' => [['type' => 'gem', 'id' => $gem->id]]])
            ->assertForbidden();
        $this->postJson('/api/admin/announcements', ['title' => 'Info', 'body' => 'Texte', 'category' => 'info', 'scopes' => [['type' => 'department', 'id' => $this->choir->id]]])
            ->assertSuccessful();

        // Exercices : droit du Garde -> son GEM, pas son departement.
        $ex = $this->getJson('/api/admin/audiences?for=exercises')->assertOk();
        $this->assertSame([$gem->id], collect($ex->json('gems'))->pluck('id')->all());
        $this->assertSame([], $ex->json('departments'));
    }
}
