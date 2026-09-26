<?php

namespace Tests\Feature;

use App\Models\Attendance;
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

class AttendanceAndGemRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->admin = $this->member('+14185550400', null, 'Admin', lastLogin: now());
        $this->admin->roles()->attach(Role::where('key', 'super_admin')->value('id'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first, ?Carbon $lastLogin = null): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => $lastLogin]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    // ------------------------------------------------------------ Presences

    public function test_roster_hides_inactive_members_and_lets_a_comeback_be_found(): void
    {
        $active = $this->member('+14185550401', $this->juda, 'Actif', now()->subWeek());
        $inactive = $this->member('+14185550402', $this->juda, 'Parti', now()->subMonths(5));
        DB::table('users')->where('id', $inactive->id)->update(['created_at' => now()->subMonths(8)]);
        // Calcul quotidien de l'activite (5 mois sans connexion, FISS ni presence => inactif).
        $this->artisan('app:tick', ['--only' => 'activity'])->assertSuccessful();
        $this->assertSame('inactive', User::find($inactive->id)->activityStatus());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'member.inactivated')->where('member_user_id', $inactive->id)->count());
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/admin/attendance?date=2026-09-20&event=Culte')->assertOk();
        $ids = collect($res->json('members'))->pluck('user_id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertSame(1, $res->json('inactive_hidden'));

        // Recherche d'un inactif revenu, puis pointage : il redevient actif.
        $this->getJson('/api/admin/attendance?date=2026-09-20&event=Culte&q=par')
            ->assertJsonPath('matches.0.user_id', $inactive->id);
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-09-20', 'event' => 'Culte', 'present_user_ids' => [$inactive->id, $active->id]])
            ->assertOk()->assertJsonPath('message', 'Session enregistrée (2 présent(s)).');
        $this->assertSame('active', User::find($inactive->id)->activityStatus());

        // Deja pointe ce jour-la : visible sur la feuille de cette session.
        $this->assertContains($inactive->id, collect($this->getJson('/api/admin/attendance?date=2026-09-20&event=Culte')->json('members'))->pluck('user_id'));
    }

    public function test_leader_only_takes_attendance_for_his_scope(): void
    {
        $patriarch = $this->member('+14185550403', $this->juda, 'Patri', now());
        $patriarch->roles()->attach(Role::where('key', 'patriarche')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => $this->juda->id]);
        $mine = $this->member('+14185550404', $this->juda, 'Mien', now());
        $other = $this->member('+14185550405', $this->levi, 'Autre', now());
        Sanctum::actingAs($patriarch);

        $ids = collect($this->getJson('/api/admin/attendance')->json('members'))->pluck('user_id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-09-20', 'event' => 'Culte', 'present_user_ids' => [$other->id]])->assertOk();
        $this->assertSame(0, Attendance::where('member_user_id', $other->id)->count());
    }

    // ------------------------------------------------------------ GEMs

    public function test_gad_must_belong_to_the_tribe_of_the_gem(): void
    {
        $judaMember = $this->member('+14185550410', $this->juda, 'Jean');
        $leviMember = $this->member('+14185550411', $this->levi, 'Luc');
        Sanctum::actingAs($this->admin);

        $candidates = collect($this->getJson("/api/admin/gems/candidates?tribe_id={$this->juda->id}")->assertOk()->json('members'))->pluck('user_id');
        $this->assertContains($judaMember->id, $candidates);
        $this->assertNotContains($leviMember->id, $candidates);

        $this->postJson('/api/admin/gems', ['name' => 'GEM A', 'tribe_id' => $this->juda->id, 'leader_user_id' => $leviMember->id])
            ->assertStatus(422)->assertJsonValidationErrors('leader_user_id');

        $id = $this->postJson('/api/admin/gems', ['name' => 'GEM A', 'tribe_id' => $this->juda->id, 'leader_user_id' => $judaMember->id])
            ->assertCreated()->json('gem.id');
        // Role Garde attribue et le Garde rejoint son GEM.
        $this->assertTrue(DB::table('role_user')->where(['user_id' => $judaMember->id, 'scope_kind' => 'gem', 'scope_id' => $id])->exists());
        $this->assertSame($id, Profile::where('user_id', $judaMember->id)->value('gem_id'));
    }

    public function test_changing_gad_keeps_a_single_leader_per_gem(): void
    {
        $a = $this->member('+14185550420', $this->juda, 'Anne');
        $b = $this->member('+14185550421', $this->juda, 'Ben');
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/admin/gems', ['name' => 'GEM B', 'tribe_id' => $this->juda->id, 'leader_user_id' => $a->id])->json('gem.id');

        $this->putJson("/api/admin/gems/{$id}", ['name' => 'GEM B', 'tribe_id' => $this->juda->id, 'leader_user_id' => $b->id])->assertOk();
        $garde = Role::where('key', 'garde')->value('id');
        $this->assertSame([$b->id], DB::table('role_user')->where(['role_id' => $garde, 'scope_kind' => 'gem', 'scope_id' => $id])->pluck('user_id')->all());

        // Via la fiche membre (role Garde) : meme regle.
        $this->postJson("/api/admin/members/{$a->id}/roles", ['role_key' => 'garde', 'scope_id' => $id])->assertOk();
        $this->assertSame($a->id, Gem::find($id)->leader_user_id);
        $this->assertSame([$a->id], DB::table('role_user')->where(['role_id' => $garde, 'scope_kind' => 'gem', 'scope_id' => $id])->pluck('user_id')->all());

        // Retirer ce role laisse le GEM sans responsable.
        $assignment = DB::table('role_user')->where(['user_id' => $a->id, 'role_id' => $garde])->value('id');
        $this->deleteJson("/api/admin/members/{$a->id}/roles/{$assignment}")->assertOk();
        $this->assertNull(Gem::find($id)->leader_user_id);
    }

    public function test_changing_tribe_leaves_the_old_gem_and_its_leadership(): void
    {
        $garde = $this->member('+14185550430', $this->juda, 'Gad');
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/admin/gems', ['name' => 'GEM C', 'tribe_id' => $this->juda->id, 'leader_user_id' => $garde->id])->json('gem.id');

        // Un GEM ne peut pas etre place dans une autre tribu tant qu'il a des membres.
        $this->putJson("/api/admin/gems/{$id}", ['name' => 'GEM C', 'tribe_id' => $this->levi->id])->assertStatus(422);
        // Un membre ne peut pas etre mis dans un GEM d'une autre tribu.
        $this->patchJson("/api/admin/members/{$garde->id}/belonging", ['tribe_id' => $this->levi->id, 'gem_id' => $id])
            ->assertStatus(422);

        $this->patchJson("/api/admin/members/{$garde->id}/belonging", ['tribe_id' => $this->levi->id])->assertOk();
        $this->assertNull(Profile::where('user_id', $garde->id)->value('gem_id'));
        $this->assertNull(Gem::find($id)->leader_user_id);
        $this->assertFalse(DB::table('role_user')->where(['user_id' => $garde->id, 'scope_kind' => 'gem', 'scope_id' => $id])->exists());
    }

    public function test_member_cannot_change_tribe_directly_from_his_profile(): void
    {
        $member = $this->member('+14185550440', $this->juda, 'Marc');
        Sanctum::actingAs($member);

        $this->putJson('/api/profile', ['first_name' => 'Marc', 'last_name' => 'T', 'gender' => 'homme', 'tribe_id' => $this->levi->id])
            ->assertStatus(422)->assertJsonValidationErrors('tribe_id');
        $this->assertSame($this->juda->id, Profile::where('user_id', $member->id)->value('tribe_id'));
        // Garder sa tribu reste possible.
        $this->putJson('/api/profile', ['first_name' => 'Marc', 'last_name' => 'T', 'gender' => 'homme', 'tribe_id' => $this->juda->id])->assertOk();
    }
}
