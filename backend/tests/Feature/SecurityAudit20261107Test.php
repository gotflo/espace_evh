<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\LocalTestMode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit de securite du 7 novembre 2026 : un droit confidentiel recu sur une portee ne devient jamais un droit sur
 * toute l'eglise (editeur de roles, attributions), journal limite a ce que l'on peut voir, mode d'essai jamais
 * ouvrable par un en-tete Host forge.
 */
class SecurityAudit20261107Test extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    private Gem $silo;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->silo = Gem::create(['name' => 'GEM Silo', 'tribe_id' => $this->levi->id, 'is_active' => true]);
    }

    private function member(?Tribe $tribe = null, ?Gem $gem = null): User
    {
        $user = User::create(['phone' => '+14185559'.str_pad((string) ++$this->n, 3, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => 'M'.$this->n, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => ($tribe ?? $this->juda)->id, 'gem_id' => $gem?->id]);

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return User::find($user->id);
    }

    public function test_a_scoped_confidential_right_never_becomes_church_wide_through_the_role_editor(): void
    {
        // Administrateur (voit tous les membres, gere les roles) ET Garde d'un GEM (suivi spirituel sur ce GEM).
        $admin = $this->give($this->give($this->member(), 'administrateur'), 'garde', 'gem', $this->bethel->id);
        Sanctum::actingAs($admin);
        $fidele = Role::where('key', 'fidele')->first();

        // Le role de tout le monde ne recoit jamais d'acces confidentiel.
        $this->putJson("/api/admin/roles/{$fidele->id}", ['name' => $fidele->name, 'scope_kind' => 'none',
            'permission_keys' => ['exercises.respond', 'spiritual.view']])->assertStatus(422);
        // Ni un nouveau role sans portee (ce serait le suivi spirituel de toute l'eglise).
        $this->postJson('/api/admin/roles', ['name' => 'Lecteurs', 'scope_kind' => 'none', 'permission_keys' => ['spiritual.view']])
            ->assertStatus(422);
        $this->assertFalse($admin->fresh()->holdsWithoutScope('spiritual.view'));
        $this->assertSame([], DB::table('roles')->where('name', 'Lecteurs')->pluck('id')->all());

        // Le Pasteur Resident lui-meme n'en met pas dans le role des fideles.
        $pr = $this->give($this->member(), 'super_admin');
        Sanctum::actingAs($pr);
        $this->putJson("/api/admin/roles/{$fidele->id}", ['name' => $fidele->name, 'scope_kind' => 'none',
            'permission_keys' => ['exercises.respond', 'spiritual.view']])->assertStatus(422);
    }

    public function test_a_role_with_confidential_access_is_only_given_where_one_has_that_access(): void
    {
        // Administrateur + patriarche de Juda : peut nommer un Garde a Juda, pas a Levi.
        $actor = $this->give($this->give($this->member(), 'administrateur'), 'patriarche', 'tribe', $this->juda->id);
        Role::where('key', 'garde')->update(['rank' => 20]);
        $judaMember = $this->member($this->juda);
        $leviMember = $this->member($this->levi);
        Sanctum::actingAs($actor);

        $this->postJson("/api/admin/members/{$leviMember->id}/roles", ['role_key' => 'garde', 'scope_id' => $this->silo->id])->assertForbidden();
        $this->assertSame(0, DB::table('role_user')->where('user_id', $leviMember->id)->where('scope_kind', 'gem')->count());
        $this->postJson("/api/admin/members/{$judaMember->id}/roles", ['role_key' => 'garde', 'scope_id' => $this->bethel->id])->assertOk();
    }

    public function test_no_one_appoints_himself_leader_through_organisation(): void
    {
        $pa = $this->give($this->member(), 'pasteur_assistant');
        $dept = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        Sanctum::actingAs($pa);
        $this->putJson("/api/admin/departments/{$dept->id}/leaders", ['user_ids' => [$pa->id]])->assertForbidden();
        $this->assertSame(0, $dept->leaders()->count());
        $other = $this->member();
        $this->putJson("/api/admin/departments/{$dept->id}/leaders", ['user_ids' => [$other->id]])->assertOk();
    }

    public function test_the_reason_of_a_deletion_request_stays_out_of_the_journal_for_others(): void
    {
        $pr = $this->give($this->member(), 'super_admin');
        $pa = $this->give($this->member(), 'pasteur_assistant');
        $marie = $this->member();
        AuditLog::create(['user_id' => $pr->id, 'action' => 'member.deletion_requested', 'member_user_id' => $marie->id,
            'new_values' => ['reason' => 'MOTIF-CONFIDENTIEL']]);

        Sanctum::actingAs($pa);
        $entry = collect($this->getJson('/api/admin/audit?action=member.deletion')->assertOk()->json('logs'))->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry['new'], 'motif reserve au Pasteur Resident et a l\'auteur');
        $this->assertStringNotContainsString('MOTIF-CONFIDENTIEL', $this->getJson('/api/admin/audit')->getContent());

        Sanctum::actingAs($pr);
        $this->assertStringContainsString('MOTIF-CONFIDENTIEL', $this->getJson('/api/admin/audit?action=member.deletion')->getContent());
    }

    public function test_a_forged_host_header_never_opens_the_local_test_mode_on_the_real_domain(): void
    {
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'app.test_direct_login' => true]);
        $this->app['env'] = 'local';
        $this->app->instance('request', Request::create('https://x.test/api/auth/request-otp', 'POST'));
        $this->assertFalse(LocalTestMode::isLocalHost());
        $this->assertFalse(LocalTestMode::active());

        config(['app.url' => 'http://localhost:8000']);
        $this->app->instance('request', Request::create('http://localhost:8000/api/auth/request-otp', 'POST'));
        $this->assertTrue(LocalTestMode::isLocalHost());
    }
}
