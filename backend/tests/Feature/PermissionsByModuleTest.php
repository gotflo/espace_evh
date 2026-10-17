<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\DashboardVerse;
use App\Models\Department;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Support\Audit;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Permissions par module : catalogue, compatibilite des roles existants, creation et modification des
 * roles depuis l'ecran, application immediate, refus cote serveur (routes, API, identifiants), anti-escalade.
 */
class PermissionsByModuleTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pastor;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pastor = $this->give($this->member('+14185570010', 'Pasteur'), 'super_admin');
        $this->marie = $this->member('+14185570000', 'Marie');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, ?Tribe $tribe = null): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => ($tribe ?? $this->juda)->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    /** @return array<int, string> */
    private function keysOf(string $roleKey): array
    {
        return Role::where('key', $roleKey)->first()->permissions()->pluck('key')->all();
    }

    public function test_existing_roles_keep_what_they_could_do(): void
    {
        $this->assertNull(Permission::where('key', 'content.manage')->first());
        $this->assertEqualsCanonicalizing(PermissionCatalog::keys(), Permission::pluck('key')->all());
        $this->assertEqualsCanonicalizing(PermissionCatalog::keys(), $this->keysOf('super_admin'));

        // PA : versets et horaires (ancien content.manage), comptes (roles.assign), exports et detail du journal.
        $pa = $this->keysOf('pasteur_assistant');
        foreach (['verses.view', 'verses.edit', 'verses.publish', 'verses.delete', 'schedule.view', 'schedule.edit', 'schedule.delete',
            'accounts.view', 'reports.export', 'members.export', 'audit.view_details', 'exercises.create', 'exercises.view_responses'] as $key) {
            $this->assertContains($key, $pa, $key);
        }
        // Devoirs et ressources : toujours reserves au Pasteur Resident.
        foreach (['resources.view', 'resources.edit', 'assignments.publish', 'platform.manage'] as $key) {
            $this->assertNotContains($key, $pa, $key);
        }
        // Patriarche, Garde : leurs exercices (creer, supprimer, suivre) comme avant.
        foreach (['patriarche', 'garde'] as $role) {
            foreach (['exercises.create', 'exercises.delete', 'exercises.view_responses'] as $key) {
                $this->assertContains($key, $this->keysOf($role), "{$role} {$key}");
            }
        }
        $this->assertContains('reports.export', $this->keysOf('patriarche'));
        $this->assertContains('events.delete', $this->keysOf('communication'));
        $this->assertContains('announcements.delete', $this->keysOf('communication'));
    }

    public function test_the_migration_converts_old_roles_and_rolls_back_cleanly(): void
    {
        // Motif des blocages de rendez-vous, role de responsable de departement, reglages, lectures des ressources,
        // services, appareils de confiance, puis permissions.
        Artisan::call('migrate:rollback', ['--step' => 8]);
        $this->assertNotNull(Permission::where('key', 'content.manage')->first());
        $this->assertNull(Permission::where('key', 'verses.view')->first());
        $this->assertNull(Permission::where('key', 'settings.manage')->first());
        $this->assertFalse(Schema::hasTable('platform_settings'));

        // Role personnalise d'avant le decoupage.
        $role = Role::create(['key' => 'redaction', 'name' => 'Rédaction', 'scope_kind' => 'none', 'rank' => 30, 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', ['content.manage', 'exercises.assign', 'announcements.publish', 'events.manage'])->pluck('id'));
        $holder = $this->member('+14185570020', 'Rédactrice');
        $holder->roles()->attach($role->id);

        Artisan::call('migrate');
        $keys = $role->fresh()->permissions()->pluck('key')->all();
        foreach (['verses.view', 'verses.edit', 'verses.publish', 'verses.delete', 'schedule.view', 'schedule.edit', 'schedule.delete',
            'exercises.create', 'exercises.delete', 'exercises.view_responses', 'announcements.delete', 'events.delete', 'events.participants'] as $key) {
            $this->assertContains($key, $keys, $key);
        }
        $this->assertNull(Permission::where('key', 'content.manage')->first());
        $this->assertTrue($holder->fresh()->roles()->where('key', 'redaction')->exists());
        // Reglages : reserves au Pasteur Resident (et au compte de gestion), retires des autres roles.
        $this->assertContains('settings.manage', $this->keysOf('super_admin'));
        foreach (['pasteur_assistant', 'administrateur', 'patriarche', 'assistant_pasteur'] as $key) {
            $this->assertNotContains('settings.manage', $this->keysOf($key), $key);
        }

        $this->actAs($holder);
        $this->getJson('/api/admin/verses')->assertOk();
        $this->getJson('/api/admin/service-schedule')->assertOk();
        $this->getJson('/api/admin/resolution-resources')->assertForbidden();
    }

    public function test_the_role_editor_lists_permissions_by_module(): void
    {
        $this->actAs($this->pastor);
        $groups = collect($this->getJson('/api/admin/permissions')->assertOk()->json('groups'));
        $this->assertSame(array_keys(PermissionCatalog::MODULES), $groups->pluck('key')->all());
        $all = $groups->flatMap(fn ($g) => $g['permissions']);
        $this->assertEqualsCanonicalizing(PermissionCatalog::keys(), $all->pluck('key')->all());
        $fiss = $all->firstWhere('key', 'spiritual.view');
        $this->assertTrue($fiss['confidential']);
        $this->assertSame('delete', $all->firstWhere('key', 'verses.delete')['kind']);
        $this->assertSame('Données des autres', $fiss['kind_label']);
        $this->assertTrue($all->every(fn ($p) => $p['grantable']));

        $roles = collect($this->getJson('/api/admin/roles')->assertOk()->json('roles'));
        $this->assertFalse($roles->firstWhere('key', 'super_admin')['can_edit']);
        $this->assertSame(1, $roles->firstWhere('key', 'super_admin')['users_count']);
    }

    public function test_a_role_is_created_edited_and_applied_at_once(): void
    {
        $this->actAs($this->pastor);
        $id = $this->postJson('/api/admin/roles', ['name' => 'Rédacteur des versets', 'scope_kind' => 'none', 'permission_keys' => ['verses.view', 'verses.edit']])
            ->assertOk()->json('role_id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created', 'subject_id' => $id]);
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => Role::find($id)->key])->assertOk();

        $this->actAs($this->marie);
        $this->getJson('/api/admin/verses')->assertOk()->assertJsonPath('can.publish', false)->assertJsonPath('can.delete', false);
        $draft = $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Texte donné par le pasteur.', 'reference' => 'Jean 1.1', 'status' => 'draft'])
            ->assertCreated()->json('verse.id');
        // Modifier n'est pas publier, ni supprimer.
        $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Autre texte donné.', 'reference' => 'Jean 1.2', 'status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->deleteJson("/api/admin/verses/{$draft}")->assertForbidden();

        // Le pasteur ajoute la suppression : appliquee des la requete suivante.
        $this->actAs($this->pastor);
        $this->putJson("/api/admin/roles/{$id}", ['name' => 'Rédacteur des versets', 'scope_kind' => 'none', 'permission_keys' => ['verses.view', 'verses.edit', 'verses.delete']])
            ->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.updated', 'subject_id' => $id]);
        $this->actAs($this->marie);
        $this->deleteJson("/api/admin/verses/{$draft}")->assertOk();

        // Role retire : plus d'acces.
        $this->actAs($this->pastor);
        $assignment = $this->marie->roles()->where('roles.id', $id)->first()->pivot->id;
        $this->deleteJson("/api/admin/members/{$this->marie->id}/roles/{$assignment}")->assertOk();
        $this->actAs($this->marie);
        $this->getJson('/api/admin/verses')->assertForbidden();

        // Suppression du role personnalise, journalisee.
        $this->actAs($this->pastor);
        $this->deleteJson("/api/admin/roles/{$id}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'subject_id' => $id]);
    }

    public function test_view_edit_and_delete_are_distinct_permissions(): void
    {
        $this->actAs($this->pastor);
        $id = $this->postJson('/api/admin/roles', ['name' => 'Lecture', 'scope_kind' => 'none',
            'permission_keys' => ['schedule.view', 'announcements.publish', 'events.manage']])->json('role_id');
        $this->give($this->marie, Role::find($id)->key);
        $announcement = Announcement::create(['title' => 'Info', 'body' => 'Texte', 'category' => 'info', 'created_by' => $this->pastor->id]);
        $event = $this->makeEvent(['title' => 'Veillée', 'starts_at' => now()->addDay(), 'category' => 'culte', 'created_by' => $this->pastor->id]);

        $this->actAs($this->marie);
        $this->getJson('/api/admin/service-schedule')->assertOk();
        $this->postJson('/api/admin/service-schedule', ['title' => 'Culte', 'weekday' => 0, 'time' => '10:00'])->assertForbidden();
        $this->getJson('/api/admin/announcements')->assertOk();
        $this->deleteJson("/api/admin/announcements/{$announcement->id}")->assertForbidden();
        $this->getJson('/api/admin/events')->assertOk();
        $this->deleteJson("/api/admin/events/{$event->id}")->assertForbidden();
        $this->getJson("/api/admin/events/{$event->id}/participants")->assertForbidden();
        $this->assertNotNull(Announcement::find($announcement->id));
        $this->assertNotNull(Event::find($event->id));
    }

    public function test_no_one_escalates_through_role_management(): void
    {
        $pa = $this->give($this->member('+14185570009', 'PA'), 'pasteur_assistant');
        $admin = $this->give($this->member('+14185570011', 'Admin', $this->levi), 'administrateur');

        $this->actAs($pa);
        // Ne donne pas une permission qu'il n'a pas.
        $this->postJson('/api/admin/roles', ['name' => 'Pirate', 'scope_kind' => 'none', 'permission_keys' => ['platform.manage']])
            ->assertStatus(422)->assertJsonValidationErrors(['permission_keys']);
        // Ne modifie ni son propre role (rang egal), ni celui du Pasteur Resident.
        $this->putJson('/api/admin/roles/'.Role::where('key', 'pasteur_assistant')->value('id'), ['name' => 'PA', 'scope_kind' => 'none',
            'permission_keys' => array_merge($this->keysOf('pasteur_assistant'), ['resources.edit'])])->assertForbidden();
        $this->putJson('/api/admin/roles/'.Role::where('key', 'super_admin')->value('id'), ['name' => 'PR', 'scope_kind' => 'none', 'permission_keys' => []])
            ->assertForbidden();
        // Ne retire pas le role du Pasteur Resident.
        $prAssignment = $this->pastor->roles()->where('key', 'super_admin')->first()->pivot->id;
        $this->deleteJson("/api/admin/members/{$this->pastor->id}/roles/{$prAssignment}")->assertForbidden();
        $this->assertTrue($this->pastor->fresh()->isSuperAdmin());

        // L'administrateur (rang 80) n'attribue pas le role de PA (rang 85).
        $this->actAs($admin);
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'pasteur_assistant'])->assertForbidden();
        $this->postJson("/api/admin/members/{$this->marie->id}/roles", ['role_key' => 'communication'])->assertOk();

        // Permission inconnue ou en double : refusee.
        $this->actAs($this->pastor);
        $this->postJson('/api/admin/roles', ['name' => 'X', 'scope_kind' => 'none', 'permission_keys' => ['foo.bar']])->assertStatus(422);
        $this->postJson('/api/admin/roles', ['name' => 'X', 'scope_kind' => 'none', 'permission_keys' => ['verses.view', 'verses.view']])->assertStatus(422);
    }

    public function test_direct_api_calls_without_permission_are_refused(): void
    {
        $verse = DashboardVerse::create(['label' => 'Parole', 'text' => 'Texte.', 'reference' => 'Jean 1.1', 'status' => 'published', 'position' => 1]);
        $other = $this->member('+14185570030', 'Paul', $this->levi);
        $this->actAs($this->marie);
        foreach ([
            ['GET', '/api/admin/platform/overview'], ['GET', '/api/admin/accounts'], ['GET', '/api/admin/roles'], ['GET', '/api/admin/permissions'],
            ['POST', '/api/admin/roles'], ['GET', '/api/admin/verses'], ['PUT', "/api/admin/verses/{$verse->id}"], ['DELETE', "/api/admin/verses/{$verse->id}"],
            ['GET', '/api/admin/resolution-resources'], ['POST', '/api/admin/resolution-resources'], ['POST', '/api/admin/assignments'],
            ['POST', '/api/admin/content/video-preview'], ['POST', "/api/admin/members/{$other->id}/roles"], ['GET', "/api/admin/members/{$other->id}"],
            ['GET', '/api/admin/audit'], ['GET', '/api/admin/exercises'], ['POST', '/api/admin/exercises'],
        ] as [$method, $url]) {
            $this->json($method, $url, ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertForbidden();
        }
        $this->assertSame('Texte.', $verse->fresh()->text);
    }

    public function test_a_member_limited_to_a_scope_cannot_read_another_members_data(): void
    {
        $patriarch = $this->give($this->member('+14185570005', 'Patriarche', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'meditation' => 15, 'comment' => 'privé', 'submitted_at' => now()]);
        $this->actAs($patriarch);
        // Marie est de Juda : changer l'identifiant dans l'adresse ne donne rien.
        $this->getJson("/api/admin/members/{$this->marie->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/fiss")->assertForbidden();
        // Un membre ne lit que ses propres donnees (aucun identifiant dans les routes « /me »).
        $this->actAs($this->marie);
        $this->getJson("/api/admin/members/{$patriarch->id}/fiss")->assertForbidden();
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('current.comment', 'privé');
    }

    public function test_exports_need_their_own_permission(): void
    {
        $this->actAs($this->pastor);
        $id = $this->postJson('/api/admin/roles', ['name' => 'Rapports sans export', 'scope_kind' => 'none', 'permission_keys' => ['reports.view', 'members.view_all']])->json('role_id');
        $this->give($this->marie, Role::find($id)->key);

        $this->actAs($this->marie);
        $this->getJson('/api/admin/reports/members?scope=church&page=1')->assertOk();
        $this->getJson('/api/admin/reports/export?kind=members&scope=church')->assertForbidden();
        $this->getJson('/api/admin/reports/export?kind=report&scope=church')->assertForbidden();
        // La liste affichee reste paginee : pas de liste complete hors export.
        $this->assertCount(2, $this->getJson('/api/admin/reports/members?scope=church&all=1')->assertOk()->json('members'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.exported']);

        $patriarch = $this->give($this->member('+14185570005', 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $this->actAs($patriarch);
        $export = $this->getJson('/api/admin/reports/export?kind=members&scope=tribe:'.$this->juda->id)->assertOk();
        $this->assertSame($export->json('total'), count($export->json('members')));
        $this->getJson('/api/admin/reports/export?kind=report&scope=tribe:'.$this->juda->id.'&months=3')->assertOk()->assertJsonStructure(['report', 'exported_at']);
        // Chaque export est journalise par le serveur lui-meme.
        $this->assertSame(2, AuditLog::where('action', 'report.exported')->where('user_id', $patriarch->id)->count());
        // Hors de sa portee : refuse.
        $this->getJson('/api/admin/reports/export?kind=report&scope=tribe:'.$this->levi->id)->assertForbidden();
    }

    public function test_the_journal_hides_personal_details_without_the_details_permission(): void
    {
        $this->actAs($this->pastor);
        $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Texte donné par le pasteur.', 'reference' => 'Jean 3.16', 'status' => 'published'])->assertCreated();
        Audit::log('profile.updated', $this->marie->profile, $this->marie->id, ['email' => null], ['email' => 'marie@exemple.org']);
        $id = $this->postJson('/api/admin/roles', ['name' => 'Journal', 'scope_kind' => 'none', 'permission_keys' => ['audit.view']])->json('role_id');
        $this->give($this->marie, Role::find($id)->key);

        $this->actAs($this->marie);
        $journal = $this->getJson('/api/admin/audit')->assertOk()->assertJsonPath('can_view_details', false);
        $actions = collect($journal->json('logs'))->pluck('action');
        $this->assertContains('verse.created', $actions);
        $this->assertNotContains('profile.updated', $actions);
        $this->assertNull(collect($journal->json('logs'))->firstWhere('action', 'verse.created')['new']);
        $this->assertStringNotContainsString('marie@exemple.org', $journal->getContent());

        $this->actAs($this->pastor);
        $this->assertStringContainsString('marie@exemple.org', $this->getJson('/api/admin/audit')->assertJsonPath('can_view_details', true)->getContent());
    }

    public function test_department_leaders_keep_their_event_rights(): void
    {
        $leader = $this->member('+14185570040', 'Responsable');
        $dept = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $dept->leaders()->attach($leader->id);
        $this->assertTrue($leader->fresh()->hasPermission('events.delete'));
        $this->assertTrue($leader->fresh()->hasPermission('events.participants'));
        $this->assertFalse($leader->fresh()->hasPermission('events.view_responses'));
        DB::table('department_leaders')->where('user_id', $leader->id)->delete();
        $this->assertFalse($leader->fresh()->hasPermission('events.delete'));
    }

    public function test_the_leaders_list_is_exported_by_those_allowed_to_export_lists(): void
    {
        $patriarch = $this->give($this->member('+14185570005', 'Patriarche', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        $this->actAs($this->pastor);
        $this->getJson('/api/admin/leaders/export')->assertOk()->assertJsonPath('scope', 'church')->assertJsonStructure(['patriarchs', 'gardes', 'exported_at']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leaders.exported', 'user_id' => $this->pastor->id]);

        // Patriarche : sa tribu seulement.
        $this->actAs($patriarch);
        $this->assertSame(['Lévi'], collect($this->getJson('/api/admin/leaders/export')->assertOk()->json('tribes'))->pluck('name')->all());

        // Sans droit d'exporter les listes : refuse, meme si l'annuaire est lisible.
        $this->actAs($this->pastor);
        $id = $this->postJson('/api/admin/roles', ['name' => 'Lecteur', 'scope_kind' => 'none', 'permission_keys' => ['members.view_all']])->json('role_id');
        $this->give($this->marie, Role::find($id)->key);
        $this->actAs($this->marie);
        $this->getJson('/api/admin/leaders')->assertOk();
        $this->getJson('/api/admin/leaders/export')->assertForbidden();
    }
}
