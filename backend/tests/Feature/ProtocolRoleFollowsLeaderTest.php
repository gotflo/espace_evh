<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\DepartmentLeadership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le role « Equipe du protocole » est rattache aux responsables du departement Protocole : ils le recoivent
 * et le perdent avec la responsabilite, quelle que soit la porte d'entree (Organisation, role, depart du departement).
 */
class ProtocolRoleFollowsLeaderTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Department $protocole;

    private Department $accueil;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->protocole = Department::create(['name' => 'Protocole', 'slug' => 'protocole', 'is_active' => true]);
        $this->accueil = Department::create(['name' => 'Accueil', 'slug' => 'accueil', 'is_active' => true]);
        $this->admin = $this->member('+14185557100', 'Pasteur');
        $this->admin->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        Sanctum::actingAs($this->admin->fresh());
    }

    private function member(string $phone, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true, 'tribe_id' => $this->juda->id]);

        return $user;
    }

    private function setLeaders(Department $department, array $ids)
    {
        return $this->putJson("/api/admin/departments/{$department->id}/leaders", ['user_ids' => $ids]);
    }

    private function row(User $user)
    {
        return DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => Role::where('key', 'protocole')->value('id')]);
    }

    private function organizes(User $user): bool
    {
        return User::find($user->id)->hasPermission('appointments.organize');
    }

    public function test_naming_a_protocol_leader_gives_the_role_and_removing_takes_it_back(): void
    {
        $sarah = $this->member('+14185557101', 'Sarah');
        $this->assertFalse($this->organizes($sarah));

        $this->setLeaders($this->protocole, [$sarah->id])->assertOk();
        $this->assertSame(1, $this->row($sarah)->where('scope_kind', 'department')->where('scope_id', $this->protocole->id)->count());
        $this->assertTrue($this->organizes($sarah));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $sarah->id, 'title' => 'Nouvelle fonction : Équipe du protocole']);

        $this->setLeaders($this->protocole, [])->assertOk();
        $this->assertSame(0, $this->row($sarah)->count());
        $this->assertFalse($this->organizes($sarah));
    }

    public function test_another_department_gives_nothing(): void
    {
        $paul = $this->member('+14185557102', 'Paul');
        $this->setLeaders($this->accueil, [$paul->id])->assertOk();
        $this->assertSame(0, $this->row($paul)->count());
        $this->assertFalse($this->organizes($paul));
    }

    public function test_the_leader_role_door_does_the_same(): void
    {
        $lea = $this->member('+14185557103', 'Léa');
        $this->postJson("/api/admin/members/{$lea->id}/roles", ['role_key' => DepartmentLeadership::ROLE, 'scope_id' => $this->protocole->id])->assertOk();
        $this->assertTrue($this->organizes($lea));

        $leaderRoleId = Role::where('key', DepartmentLeadership::ROLE)->value('id');
        $assignment = DB::table('role_user')->where(['user_id' => $lea->id, 'role_id' => $leaderRoleId])->value('id');
        $this->deleteJson("/api/admin/members/{$lea->id}/roles/{$assignment}")->assertOk();
        $this->assertFalse($this->organizes($lea));
        $this->assertSame(0, $this->row($lea)->count());
    }

    public function test_leaving_the_department_takes_the_role_back(): void
    {
        $eve = $this->member('+14185557104', 'Ève');
        $this->setLeaders($this->protocole, [$eve->id])->assertOk();
        Sanctum::actingAs(User::find($eve->id));
        $this->deleteJson("/api/me/services/{$this->protocole->id}")->assertOk();
        $this->assertSame(0, $this->row($eve)->count());
        $this->assertFalse($this->organizes($eve));
    }

    public function test_a_role_given_by_hand_stays_when_the_leadership_ends(): void
    {
        $tim = $this->member('+14185557105', 'Tim');
        $this->postJson("/api/admin/members/{$tim->id}/roles", ['role_key' => 'protocole'])->assertOk();
        $this->setLeaders($this->protocole, [$tim->id])->assertOk();
        $this->assertSame(2, $this->row($tim)->count());

        $this->setLeaders($this->protocole, [])->assertOk();
        $this->assertSame(1, $this->row($tim)->count());
        $this->assertSame(1, $this->row($tim)->whereNull('scope_kind')->count());
        $this->assertTrue($this->organizes($tim));
    }

    public function test_a_team_member_given_by_hand_can_still_be_removed_but_not_the_leader_assignment(): void
    {
        $ana = $this->member('+14185557106', 'Ana');
        $this->setLeaders($this->protocole, [$ana->id])->assertOk();
        $this->deleteJson("/api/admin/members/{$ana->id}/roles/".$this->row($ana)->value('id'))->assertStatus(422);
        $this->assertTrue($this->organizes($ana));

        $bob = $this->member('+14185557107', 'Bob');
        $this->postJson("/api/admin/members/{$bob->id}/roles", ['role_key' => 'protocole'])->assertOk();
        $this->deleteJson("/api/admin/members/{$bob->id}/roles/".$this->row($bob)->value('id'))->assertOk();
        $this->assertFalse($this->organizes($bob));
    }

    public function test_naming_a_protocol_leader_cannot_grant_an_access_one_does_not_have(): void
    {
        $role = Role::create(['key' => 'gestion_org', 'name' => 'Gestion', 'scope_kind' => 'none', 'rank' => 80, 'is_system' => false]);
        $role->permissions()->attach(Permission::whereIn('key', ['departments.manage', 'members.view_all'])->pluck('id'));
        // Il peut nommer un responsable de departement : seul l'acces du protocole lui manque.
        $role->permissions()->syncWithoutDetaching(Role::where('key', DepartmentLeadership::ROLE)->first()->permissions->pluck('id'));
        $actor = $this->member('+14185557108', 'Org');
        $actor->roles()->attach($role->id);
        Sanctum::actingAs(User::find($actor->id));
        $other = $this->member('+14185557109', 'Noa');

        $this->setLeaders($this->protocole, [$actor->id])->assertForbidden();
        $this->setLeaders($this->protocole, [$other->id])->assertForbidden();
        $this->assertFalse($this->organizes($other));
        $this->setLeaders($this->accueil, [$other->id])->assertOk();
    }

    public function test_deleting_the_department_removes_the_assignment(): void
    {
        $zoe = $this->member('+14185557110', 'Zoé');
        $this->setLeaders($this->protocole, [$zoe->id])->assertOk();
        DepartmentLeadership::forget($this->protocole);
        $this->assertSame(0, $this->row($zoe)->count());
    }

    public function test_the_migration_covers_leaders_already_named(): void
    {
        $max = $this->member('+14185557111', 'Max');
        $this->protocole->leaders()->attach($max->id);
        $this->assertFalse($this->organizes($max));

        $migration = require database_path('migrations/2026_10_31_100001_protocol_role_follows_department_leader.php');
        $migration->up();
        $migration->up(); // sans doublon

        $this->assertSame(1, $this->row($max)->count());
        $this->assertTrue($this->organizes($max));
    }
}
