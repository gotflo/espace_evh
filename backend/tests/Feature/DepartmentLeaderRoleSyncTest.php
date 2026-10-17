<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\DepartmentLeadership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le role « Responsable de departement » et les responsables nommes dans Organisation restent
 * synchronises : nommer par l'un nomme par l'autre, retirer l'un retire l'autre, et le responsable
 * apparait partout (Servir, annuaire des responsables, Organisation, rapports mensuels).
 */
class DepartmentLeaderRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Department $intercession;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->intercession = Department::create(['name' => 'Intercession', 'slug' => 'intercession', 'is_active' => true]);
        $this->admin = $this->member('+14185557000', 'Pasteur', 'Admin');
        $this->admin->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        Sanctum::actingAs($this->admin->fresh());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, string $last = 'T', bool $completed = true): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'is_completed' => $completed, 'tribe_id' => $this->juda->id]);

        return $user;
    }

    private function assignRole(User $user, Department $department)
    {
        return $this->postJson("/api/admin/members/{$user->id}/roles", ['role_key' => DepartmentLeadership::ROLE, 'scope_id' => $department->id]);
    }

    private function hasRole(User $user, Department $department): bool
    {
        return DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => Role::where('key', DepartmentLeadership::ROLE)->value('id'),
            'scope_kind' => 'department', 'scope_id' => $department->id])->exists();
    }

    public function test_the_role_names_the_leader_who_then_appears_everywhere(): void
    {
        $guillaine = $this->member('+14185557001', 'Guillaine', 'Melet');
        $this->assignRole($guillaine, $this->intercession)->assertOk();

        // Organisation : responsable, et ajoutee au departement dont elle n'etait pas membre.
        $this->assertTrue($this->intercession->leaders()->whereKey($guillaine->id)->exists());
        $this->assertTrue($this->intercession->members()->where('profiles.user_id', $guillaine->id)->exists());
        $org = collect($this->getJson('/api/admin/organization')->assertOk()->json('departments'))->firstWhere('name', 'Intercession');
        $this->assertSame(['Guillaine Melet'], array_column($org['leaders'], 'name'));

        // Bannieres « Responsable » : page Servir et annuaire des responsables.
        $service = collect($this->getJson('/api/me/services')->assertOk()->json('services'))->firstWhere('name', 'Intercession');
        $this->assertSame('Guillaine Melet', $service['leader']);
        $directory = $this->getJson('/api/admin/leaders')->assertOk()->json('department_leaders');
        $this->assertSame([['Guillaine Melet', ['Intercession']]], array_map(fn ($p) => [$p['name'], $p['departments']], $directory));

        // Comptes : une seule etiquette (le role), pas de doublon « Responsable · Intercession ».
        $account = collect($this->getJson('/api/admin/accounts')->assertOk()->json('accounts'))->firstWhere('id', $guillaine->id);
        $this->assertSame(['Responsable de département · Intercession'], array_map(fn ($r) => $r['name'].' · '.$r['scope_name'], $account['roles']));
        $this->assertSame([], $account['led_departments']);
        $this->assertSame([], $this->getJson("/api/admin/members/{$guillaine->id}")->assertOk()->json('led_departments'));

        // Son espace : une seule fonction affichee, le rapport mensuel du departement, les droits de responsable.
        Sanctum::actingAs($guillaine->fresh());
        $roles = $this->getJson('/api/me')->assertOk()->json('roles');
        $this->assertSame([['department_leader', 'Intercession']], array_map(fn ($r) => [$r['key'], $r['scope_name']], array_values(array_filter($roles, fn ($r) => $r['scope_kind'] === 'department'))));
        $mine = $this->getJson('/api/admin/leader-reports')->assertOk()->json('mine');
        $this->assertSame([['department', 'Intercession']], array_map(fn ($s) => [$s['kind'], $s['scope_name']], $mine));
        $this->assertTrue($guillaine->fresh()->hasPermission('departments.approve'));
    }

    public function test_removing_the_role_removes_the_leader_but_keeps_the_member(): void
    {
        $joyce = $this->member('+14185557002', 'Joyce', 'Mugombozi');
        $this->assignRole($joyce, $this->intercession)->assertOk();
        $assignment = DB::table('role_user')->where('user_id', $joyce->id)->value('id');

        $this->deleteJson("/api/admin/members/{$joyce->id}/roles/{$assignment}")->assertOk();
        $this->assertFalse($this->intercession->leaders()->whereKey($joyce->id)->exists());
        $this->assertTrue($this->intercession->members()->where('profiles.user_id', $joyce->id)->exists());
        $service = collect($this->getJson('/api/me/services')->json('services'))->firstWhere('name', 'Intercession');
        $this->assertNull($service['leader']);
    }

    public function test_naming_in_organisation_gives_and_takes_back_the_role(): void
    {
        $a = $this->member('+14185557003', 'Agathe', 'Tshipama');
        $b = $this->member('+14185557004', 'Issa', 'Lamboni');

        $this->putJson("/api/admin/departments/{$this->intercession->id}/leaders", ['user_ids' => [$a->id]])->assertOk();
        $this->assertTrue($this->hasRole($a, $this->intercession));

        $this->putJson("/api/admin/departments/{$this->intercession->id}/leaders", ['user_ids' => [$b->id]])->assertOk();
        $this->assertFalse($this->hasRole($a, $this->intercession));
        $this->assertTrue($this->hasRole($b, $this->intercession));
        // Une seule notification pour la nomination (pas une de plus pour le role).
        $this->assertSame(1, UserNotification::where('user_id', $b->id)->count());

        // Attribuer ensuite le role a quelqu'un deja nomme ne cree ni doublon ni erreur.
        $this->assignRole($b, $this->intercession)->assertOk();
        $this->assertSame(1, DB::table('role_user')->where('user_id', $b->id)->count());
        $this->assertSame([$b->id], $this->intercession->leaders()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_leader_of_several_departments_follows_each_one_separately(): void
    {
        $chorale = Department::create(['name' => 'Chorale test', 'slug' => 'chorale-test', 'is_active' => true, 'tracks_rehearsal' => true]);
        $other = Department::create(['name' => 'Accueil test', 'slug' => 'accueil-test', 'is_active' => true]);
        $leader = $this->member('+14185557020', 'Dominique', 'Bayiha');
        $prayer = $this->member('+14185557021', 'Priante');
        $singer = $this->member('+14185557022', 'Chanteuse');
        $both = $this->member('+14185557023', 'Partout');
        $outsider = $this->member('+14185557024', 'Ailleurs');
        $join = fn (User $u, Department $d) => Profile::where('user_id', $u->id)->first()->departments()->attach($d->id);
        $join($prayer, $this->intercession);
        $join($singer, $chorale);
        $join($both, $this->intercession);
        $join($both, $chorale);
        $join($outsider, $other);
        $this->assignRole($leader, $this->intercession)->assertOk();
        $this->assignRole($leader, $chorale)->assertOk();

        Sanctum::actingAs($leader->fresh());
        // Ses fonctions, ses rapports mensuels : un par departement.
        $roles = collect($this->getJson('/api/me')->assertOk()->json('roles'))->where('key', 'department_leader')->pluck('scope_name')->sort()->values()->all();
        $this->assertSame(['Chorale test', 'Intercession'], $roles);
        $mine = collect($this->getJson('/api/admin/leader-reports')->assertOk()->json('mine'))->pluck('scope_name')->sort()->values()->all();
        $this->assertSame(['Chorale test', 'Intercession'], $mine);

        // Appel des repetitions : un departement a la fois, jamais un departement qu'il ne dirige pas.
        $roster = fn (Department $d) => collect($this->getJson("/api/admin/attendance?kind=repetition&event=R%C3%A9p%C3%A9tition&department_id={$d->id}")
            ->assertOk()->json('members'))->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$prayer->id, $both->id, $leader->id], $roster($this->intercession));
        $this->assertEqualsCanonicalizing([$singer->id, $both->id, $leader->id], $roster($chorale));
        $this->getJson("/api/admin/attendance?kind=repetition&department_id={$other->id}")->assertForbidden();

        // Meme jour, meme membre dans les deux departements : deux feuilles distinctes, aucune n'efface l'autre.
        $save = fn (Department $d, array $statuses) => $this->postJson('/api/admin/attendance', ['attended_on' => '2026-10-15', 'event' => 'Répétition',
            'kind' => 'repetition', 'department_id' => $d->id, 'statuses' => $statuses])->assertOk();
        $save($this->intercession, [$prayer->id => 'present', $both->id => 'retard']);
        $save($chorale, [$singer->id => 'present', $both->id => 'present', $outsider->id => 'present']);
        $this->assertSame(['Répétition · Chorale test' => 'present', 'Répétition · Intercession' => 'retard'],
            DB::table('attendances')->where('member_user_id', $both->id)->orderBy('event')->pluck('status', 'event')->all());
        $this->assertSame(0, DB::table('attendances')->where('member_user_id', $outsider->id)->count());
        $this->assertSame('present', DB::table('attendances')->where('member_user_id', $prayer->id)->value('status'));

        // Partout ailleurs : son nom sur chacun de ses services, une seule ligne dans l'annuaire.
        Sanctum::actingAs($this->admin->fresh());
        $services = collect($this->getJson('/api/me/services')->json('services'))->keyBy('name');
        $this->assertSame(['Dominique Bayiha', 'Dominique Bayiha'], [$services['Intercession']['leader'], $services['Chorale test']['leader']]);
        $directory = $this->getJson('/api/admin/leaders')->json('department_leaders');
        $this->assertSame([['Dominique Bayiha', ['Chorale test', 'Intercession']]], array_map(fn ($p) => [$p['name'], $p['departments']], $directory));
    }

    public function test_the_role_follows_the_same_rules_as_organisation(): void
    {
        // Profil incomplet : refuse des deux cotes, et rien n'est attribue.
        $incomplete = $this->member('+14185557005', 'Sans', 'Profil', completed: false);
        $this->assignRole($incomplete, $this->intercession)->assertStatus(422);
        $this->assertFalse($this->hasRole($incomplete, $this->intercession));

        // 5 responsables au plus.
        foreach (range(1, 5) as $i) {
            $this->assignRole($this->member('+1418555710'.$i, 'Resp'.$i), $this->intercession)->assertOk();
        }
        $sixth = $this->member('+14185557110', 'Sixieme');
        $this->assignRole($sixth, $this->intercession)->assertStatus(422)->assertJsonFragment(['message' => 'Le département Intercession a déjà 5 responsables : retirez-en un dans Organisation.']);
        $this->assertFalse($this->hasRole($sixth, $this->intercession));
        $this->assertSame(5, $this->intercession->leaders()->count());
    }

    public function test_the_role_is_a_protected_base_role_and_dies_with_its_department(): void
    {
        $role = Role::where('key', DepartmentLeadership::ROLE)->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertSame('department', $role->scope_kind);
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertForbidden();

        $leader = $this->member('+14185557006', 'Grace', 'Lungili');
        $this->assignRole($leader, $this->intercession)->assertOk();
        $this->deleteJson("/api/admin/departments/{$this->intercession->id}")->assertOk();
        $this->assertSame(0, DB::table('role_user')->where('user_id', $leader->id)->count());
    }

    public function test_the_migration_adopts_the_custom_role_and_aligns_both_lists(): void
    {
        // Etat en ligne avant la mise a jour : role personnalise cree par les pasteurs, deux facons de nommer.
        Role::where('key', DepartmentLeadership::ROLE)->delete();
        $custom = Role::create(['key' => 'responsable_de_departement', 'name' => 'Responsable de département', 'scope_kind' => 'department', 'rank' => 30, 'is_system' => false]);
        $custom->permissions()->sync(Permission::whereIn('key', ['members.view_scope', 'departments.manage'])->pluck('id'));
        $wedding = Department::firstOrCreate(['slug' => 'wedding-planner'], ['name' => 'Wedding Planner', 'is_active' => true]);
        $byRole = $this->member('+14185557007', 'Guillaine', 'Melet');
        $byOrganisation = $this->member('+14185557008', 'Theorone', 'Nita');
        $byRole->roles()->attach($custom->id, ['scope_kind' => 'department', 'scope_id' => $this->intercession->id]);
        $byRole->roles()->attach($custom->id, ['scope_kind' => 'department', 'scope_id' => 999]); // departement disparu
        $wedding->leaders()->attach($byOrganisation->id);

        (include database_path('migrations/2026_10_16_100001_sync_department_leader_role.php'))->up();

        $role = Role::findOrFail($custom->id);
        $this->assertSame([DepartmentLeadership::ROLE, true, 'Responsable de département'], [$role->key, (bool) $role->is_system, $role->name]);
        $this->assertEqualsCanonicalizing(['members.view_scope', 'departments.manage'], $role->permissions()->pluck('key')->all());
        $this->assertTrue($this->intercession->leaders()->whereKey($byRole->id)->exists());
        $this->assertTrue($this->intercession->members()->where('profiles.user_id', $byRole->id)->exists());
        $this->assertTrue($this->hasRole($byOrganisation, $wedding));
        $this->assertSame(0, DB::table('role_user')->where('scope_id', 999)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'department.leaders_synced']);
    }
}
