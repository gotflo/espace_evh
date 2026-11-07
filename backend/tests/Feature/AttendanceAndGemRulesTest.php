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

    private function give(User $user, string $roleKey, ?string $kind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $kind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    public function test_patriarch_places_members_of_his_tribe_in_a_gem_from_the_member_page(): void
    {
        $patriarch = $this->give($this->member('+14185550450', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $paul = $this->member('+14185550451', $this->juda, 'Paul');
        $other = $this->member('+14185550452', $this->levi, 'Autre');
        $gem = Gem::create(['name' => 'GEM Juda', 'tribe_id' => $this->juda->id]);
        $leviGem = Gem::create(['name' => 'GEM Lévi', 'tribe_id' => $this->levi->id]);
        $this->assertFalse($patriarch->hasPermission('members.edit'), 'le patriarche n\'a pas le droit complet d\'appartenance');

        Sanctum::actingAs($patriarch);
        $this->getJson("/api/admin/members/{$paul->id}")->assertOk()
            ->assertJsonPath('belonging_edit', ['status' => false, 'tribe' => false, 'departments' => false, 'gem' => true]);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $gem->id, 'department_ids' => []])
            ->assertOk();
        $this->assertSame($gem->id, Profile::where('user_id', $paul->id)->value('gem_id'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'member.belonging_updated')->where('member_user_id', $paul->id)->count());

        // Le GEM seulement : ni la tribu, ni les departements, ni un GEM d'une autre tribu, ni un membre d'une autre tribu.
        $choir = \App\Models\Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $gem->id, 'department_ids' => [$choir->id]])
            ->assertStatus(422)->assertJsonPath('errors.department_ids.0', 'Les départements de ce membre se changent par leurs responsables.');
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->levi->id, 'gem_id' => null])->assertStatus(422);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $leviGem->id])->assertStatus(422);
        $this->patchJson("/api/admin/members/{$other->id}/belonging", ['tribe_id' => $this->levi->id, 'gem_id' => $leviGem->id])->assertStatus(403);
        $this->assertNull(Profile::where('user_id', $other->id)->value('gem_id'));
        // Retirer du GEM.
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => null])->assertOk();
        $this->assertNull(Profile::where('user_id', $paul->id)->value('gem_id'));

        // Un simple Garde ne compose pas les GEMs.
        $garde = $this->give($this->member('+14185550453', $this->juda, 'Garde'), 'garde', 'gem', $gem->id);
        Sanctum::actingAs($garde);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $gem->id])->assertStatus(403);
    }

    public function test_patriarch_who_also_leads_a_department_can_place_members_in_his_tribe_gems(): void
    {
        // Comme en production : le role « Responsable de departement » donne members.edit (sur son departement).
        $deptRole = Role::where('key', 'responsable_departement')->first();
        $deptRole->permissions()->syncWithoutDetaching([DB::table('permissions')->where('key', 'members.edit')->value('id')]);
        $choir = \App\Models\Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $patriarch = $this->give($this->give($this->member('+14185550460', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id),
            'responsable_departement', 'department', $choir->id);
        $this->assertTrue($patriarch->hasPermission('members.edit'));
        $paul = $this->member('+14185550461', $this->juda, 'Paul');
        $singer = $this->member('+14185550462', $this->juda, 'Chantre');
        $singer->profile->departments()->attach($choir->id);
        $leviSinger = $this->member('+14185550463', $this->levi, 'ChantreLevi');
        $leviSinger->profile->departments()->attach($choir->id);
        $gem = Gem::create(['name' => 'GEM Juda', 'tribe_id' => $this->juda->id]);
        $leviGem = Gem::create(['name' => 'GEM Lévi', 'tribe_id' => $this->levi->id]);

        Sanctum::actingAs($patriarch);
        // Membre de sa tribu hors de son departement : refuse avant (« Action non autorisée sur ce membre »).
        $this->getJson("/api/admin/members/{$paul->id}")
            ->assertJsonPath('belonging_edit', ['status' => false, 'tribe' => false, 'departments' => false, 'gem' => true]);
        $this->patchJson("/api/admin/members/{$paul->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $gem->id, 'department_ids' => []])
            ->assertOk();
        // Membre de sa tribu ET de son departement : droit complet, et le GEM aussi.
        $this->getJson("/api/admin/members/{$singer->id}")
            ->assertJsonPath('belonging_edit', ['status' => true, 'tribe' => false, 'departments' => true, 'gem' => true]);
        $this->patchJson("/api/admin/members/{$singer->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $gem->id, 'department_ids' => [$choir->id]])
            ->assertOk();
        $this->assertSame(2, Profile::where('gem_id', $gem->id)->count());
        // Membre de son departement mais d'une autre tribu : ses departements oui, son GEM non (rien ne se melange).
        $this->getJson("/api/admin/members/{$leviSinger->id}")
            ->assertJsonPath('belonging_edit', ['status' => true, 'tribe' => false, 'departments' => true, 'gem' => false]);
        $this->patchJson("/api/admin/members/{$leviSinger->id}/belonging", ['tribe_id' => $this->levi->id, 'gem_id' => $leviGem->id, 'department_ids' => [$choir->id]])
            ->assertStatus(422)->assertJsonPath('errors.gem_id.0', 'Le GEM se change par le patriarche ou l’AP de la tribu.');
        $this->assertNull(Profile::where('user_id', $leviSinger->id)->value('gem_id'));

        // Page GEMs : seulement sa tribu de patriarche et ses membres, jamais ceux de son departement d'une autre tribu.
        $this->getJson('/api/admin/gems')->assertOk()->assertJsonCount(1, 'tribes')->assertJsonPath('tribes.0.id', $this->juda->id)
            ->assertJsonCount(1, 'gems');
        $this->getJson('/api/admin/gems/candidates?tribe_id='.$this->levi->id)->assertStatus(403);
        $names = collect($this->getJson('/api/admin/gems/candidates?tribe_id='.$this->juda->id)->assertOk()->json('members'))->pluck('full_name');
        $this->assertNotContains('ChantreLevi T', $names);
        $this->putJson("/api/admin/gems/{$leviGem->id}/members", ['user_ids' => [$leviSinger->id]])->assertStatus(403);
    }

    public function test_gem_page_sets_the_members_of_a_gem(): void
    {
        $patriarch = $this->give($this->member('+14185550470', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $garde = $this->member('+14185550471', $this->juda, 'Garde');
        $a = $this->member('+14185550472', $this->juda, 'Anne');
        $b = $this->member('+14185550473', $this->juda, 'Bruno');
        $otherGarde = $this->member('+14185550474', $this->juda, 'AutreGarde');
        $levi = $this->member('+14185550475', $this->levi, 'Levi');
        Sanctum::actingAs($patriarch);
        $id = $this->postJson('/api/admin/gems', ['name' => 'GEM A', 'tribe_id' => $this->juda->id, 'leader_user_id' => $garde->id])->assertCreated()->json('gem.id');
        $other = $this->postJson('/api/admin/gems', ['name' => 'GEM B', 'tribe_id' => $this->juda->id, 'leader_user_id' => $otherGarde->id])->json('gem.id');
        $this->patchJson("/api/admin/members/{$b->id}/belonging", ['tribe_id' => $this->juda->id, 'gem_id' => $other])->assertOk();

        // Anne arrive, Bruno vient du GEM B ; le Garde reste meme s'il n'est pas coche.
        $this->putJson("/api/admin/gems/{$id}/members", ['user_ids' => [$a->id, $b->id]])->assertOk()
            ->assertJsonPath('added', 2)->assertJsonPath('removed', 0);
        $this->assertEqualsCanonicalizing([$garde->id, $a->id, $b->id], Profile::where('gem_id', $id)->pluck('user_id')->all());
        $this->assertSame(1, Profile::where('gem_id', $other)->count(), 'Bruno a quitté le GEM B');
        $candidates = collect($this->getJson('/api/admin/gems/candidates?tribe_id='.$this->juda->id)->assertOk()->json('members'));
        $this->assertSame($id, $candidates->firstWhere('user_id', $a->id)['gem_id']);

        // Retirer Bruno ; le Garde d'un autre GEM et un membre d'une autre tribu sont refuses.
        $this->putJson("/api/admin/gems/{$id}/members", ['user_ids' => [$a->id]])->assertOk()->assertJsonPath('removed', 1);
        $this->assertNull(Profile::where('user_id', $b->id)->value('gem_id'));
        $this->putJson("/api/admin/gems/{$id}/members", ['user_ids' => [$a->id, $otherGarde->id]])->assertStatus(422);
        $this->putJson("/api/admin/gems/{$id}/members", ['user_ids' => [$a->id, $levi->id]])->assertStatus(422);
        $this->assertSame($other, Profile::where('user_id', $otherGarde->id)->value('gem_id'));
        // Journal : Anne ajoutee, Bruno ajoute puis retire.
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'member.belonging_updated')->where('context', 'like', '%gem_page%')->count());

        // Le patriarche d'une autre tribu ne touche pas a ce GEM.
        $leviPatriarch = $this->give($this->member('+14185550476', $this->levi, 'PLevi'), 'patriarche', 'tribe', $this->levi->id);
        Sanctum::actingAs($leviPatriarch);
        $this->putJson("/api/admin/gems/{$id}/members", ['user_ids' => []])->assertStatus(403);
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
