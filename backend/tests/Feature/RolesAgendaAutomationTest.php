<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\MemberRequest;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolesAgendaAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre'): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => substr($phone, -3),
            'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): void
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);
        $user->unsetRelation('roles');
    }

    private function evaluate(User $target)
    {
        return $this->postJson("/api/admin/members/{$target->id}/evaluations", [
            'type' => 'meditation_perso', 'score' => 15, 'evaluated_on' => '2026-09-20',
        ]);
    }

    // ------------------------------------------------------------ AP : uniquement ses tribus (plusieurs possibles)

    public function test_ap_only_sees_and_manages_the_tribes_assigned_to_him(): void
    {
        $dan = Tribe::create(['name' => 'Dan', 'slug' => 'dan']);
        $ap = $this->member('+14185550300', $this->juda, 'Ap');
        $this->give($ap, 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->give($ap, 'assistant_pasteur', 'tribe', $this->levi->id); // deux tribus assignees
        $juda = $this->member('+14185550301', $this->juda);
        $levi = $this->member('+14185550302', $this->levi);
        $outside = $this->member('+14185550303', $dan);
        Sanctum::actingAs($ap);

        $list = collect($this->getJson('/api/admin/members')->assertOk()->json('members'))->pluck('user_id');
        $this->assertContains($juda->id, $list);
        $this->assertContains($levi->id, $list);
        $this->assertNotContains($outside->id, $list, "L'AP ne voit pas les autres tribus");

        // Aucun contournement par appel direct de l'API.
        $this->getJson("/api/admin/members/{$outside->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$outside->id}/spiritual")->assertForbidden();
        $this->getJson("/api/admin/members/{$outside->id}/evaluations")->assertForbidden();
        $this->evaluate($outside)->assertForbidden();
        $this->evaluate($levi)->assertCreated();
        $this->getJson('/api/admin/reports?scope=tribe:'.$dan->id)->assertForbidden();
        $this->getJson('/api/admin/reports?scope=church')->assertForbidden();
        $this->getJson('/api/admin/reports/members?scope=mine')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/admin/stats')->assertOk()->assertJsonPath('total', 3);

        // Publication : ses tribus seulement.
        $options = $this->getJson('/api/admin/audiences')->assertOk();
        $this->assertFalse($options->json('church'));
        $this->assertEqualsCanonicalizing([$this->juda->id, $this->levi->id], collect($options->json('tribes'))->pluck('id')->all());
        $this->postJson('/api/admin/announcements', ['title' => 'Dan', 'category' => 'info', 'scopes' => [['type' => 'tribe', 'id' => $dan->id]]])->assertForbidden();
        $this->postJson('/api/admin/announcements', ['title' => 'Deux tribus', 'category' => 'info',
            'scopes' => [['type' => 'tribe', 'id' => $this->juda->id], ['type' => 'tribe', 'id' => $this->levi->id]]])->assertOk();
        $this->assertSame(1, UserNotification::where("user_id", $juda->id)->count());
        $this->assertSame(1, UserNotification::where("user_id", $levi->id)->where("type", "announcement")->count());
        $this->assertSame(0, UserNotification::where('user_id', $outside->id)->count());
    }

    public function test_accompagnateur_role_lets_an_ap_act_on_one_given_member(): void
    {
        $admin = $this->member('+14185550310', null, 'Admin');
        $this->give($admin, 'super_admin');
        $ap = $this->member('+14185550311', $this->juda, 'Ap');
        $this->give($ap, 'assistant_pasteur', 'tribe', $this->juda->id);
        $target = $this->member('+14185550312', $this->levi);
        $other = $this->member('+14185550313', $this->levi);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/members/{$ap->id}/roles", ['role_key' => 'accompagnateur', 'scope_id' => $ap->id])
            ->assertStatus(422); // pas sur lui-meme
        $this->postJson("/api/admin/members/{$ap->id}/roles", ['role_key' => 'accompagnateur', 'scope_id' => $target->id])
            ->assertOk();
        $this->getJson("/api/admin/members/{$ap->id}")->assertJsonFragment(['key' => 'accompagnateur', 'scope_name' => $target->profile->full_name]);

        Sanctum::actingAs(User::find($ap->id));
        $this->evaluate($target)->assertCreated();
        $this->evaluate($other)->assertForbidden();
    }

    public function test_gem_leader_cannot_write_in_the_journal_of_someone_outside_his_scope(): void
    {
        $garde = $this->member('+14185550320', $this->juda, 'Gad');
        $gem = \App\Models\Gem::create(['name' => 'GEM 1', 'tribe_id' => $this->juda->id]);
        $this->give($garde, 'garde', 'gem', $gem->id);
        $stranger = $this->member('+14185550321', $this->levi);
        Sanctum::actingAs($garde);

        $this->getJson("/api/admin/members/{$stranger->id}/spiritual")->assertForbidden();
        $this->postJson("/api/admin/members/{$stranger->id}/spiritual/entries", ['type' => 'priere', 'entry_date' => '2026-09-20'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------ Diffusion a toute l'eglise

    public function test_broadcast_to_everyone_requires_the_communication_role(): void
    {
        $patriarch = $this->member('+14185550330', $this->juda, 'Patri');
        $this->give($patriarch, 'patriarche', 'tribe', $this->juda->id);
        $judaMember = $this->member('+14185550331', $this->juda);
        $leviMember = $this->member('+14185550332', $this->levi);

        Sanctum::actingAs($patriarch);
        // Viser toute l'eglise sans le droit de diffusion generale : refuse.
        $this->postJson('/api/admin/announcements', ['title' => 'Pour tous ?', 'category' => 'info', 'scopes' => [['type' => 'church']]])->assertForbidden();
        // Viser une tribu hors de sa portee : refuse.
        $this->postJson('/api/admin/announcements', ['title' => 'Lévi', 'category' => 'info', 'scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])->assertForbidden();
        // Sans selection : sa propre tribu.
        $this->postJson('/api/admin/announcements', ['title' => 'Pour ma tribu', 'category' => 'info'])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $judaMember->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $leviMember->id)->count(), 'Limité à sa tribu');

        $com = $this->member('+14185550333', $this->levi, 'Com');
        $this->give($com, 'communication');
        Sanctum::actingAs($com);
        $this->postJson('/api/admin/events', ['title' => 'Grand culte', 'category' => 'culte', 'starts_at' => '2026-10-04 10:00', 'scopes' => [['type' => 'church']]])
            ->assertCreated();
        $this->assertSame(1, UserNotification::where('user_id', $leviMember->id)->where('type', 'event')->count());
        $this->assertSame(1, UserNotification::where('user_id', $judaMember->id)->where('type', 'event')->count());
    }

    public function test_publisher_without_any_scope_gets_a_clear_refusal(): void
    {
        $user = $this->member('+14185550340');
        $role = Role::create(['key' => 'test_pub', 'name' => 'Publieur', 'scope_kind' => 'none']);
        $role->permissions()->sync(\App\Models\Permission::where('key', 'announcements.publish')->pluck('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/announcements', ['title' => 'X', 'category' => 'info'])
            ->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'Communication'));
    }

    // ------------------------------------------------------------ Agenda personnel

    public function test_member_schedules_a_private_event_from_the_calendar(): void
    {
        $owner = $this->member('+14185550350');
        $other = $this->member('+14185550351');
        $admin = $this->member('+14185550352', null, 'Admin');
        $this->give($admin, 'super_admin');

        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/me/events', [
            'title' => 'Rendez-vous avec mon Garde', 'starts_at' => '2026-09-26 09:00', 'ends_at' => '2026-09-26 10:00',
            'recurrence' => 'weekly', 'recurrence_until' => '2026-09-26',
        ])->assertCreated()->json('id');

        $mine = collect($this->getJson('/api/calendar?from=2026-09-21&to=2026-10-04')->json('events'))->firstWhere('event_id', $id);
        $this->assertTrue($mine['personal']);
        $this->assertTrue($mine['can_edit']);

        foreach ([$other, $admin] as $u) {
            Sanctum::actingAs($u);
            $ids = collect($this->getJson('/api/calendar?from=2026-09-21&to=2026-10-04')->json('events'))->pluck('event_id');
            $this->assertNotContains($id, $ids, 'Invisible pour les autres, même un administrateur');
            $this->putJson("/api/me/events/{$id}", ['title' => 'Piraté', 'starts_at' => '2026-09-26 09:00'])->assertNotFound();
        }
        Sanctum::actingAs($admin);
        $this->assertNotContains($id, collect($this->getJson('/api/admin/events')->json('events'))->pluck('id'));

        // Rappel automatique au proprietaire uniquement.
        Event::whereKey($id)->update(['created_at' => now()->subDay()]);
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $owner->id)->where('title', 'Demain : Rendez-vous avec mon Garde')->count());
        $this->assertSame(0, UserNotification::where('title', 'like', '%Rendez-vous avec mon Garde%')->where('user_id', '!=', $owner->id)->count());

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/me/events/{$id}")->assertOk();
        $this->assertNull(Event::find($id));
    }

    public function test_calendar_tells_who_can_edit_church_events(): void
    {
        $admin = $this->member('+14185550360', null, 'Admin');
        $this->give($admin, 'super_admin');
        $member = $this->member('+14185550361');
        $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => '2026-09-27 10:00']);

        Sanctum::actingAs($admin);
        $this->assertTrue($this->getJson('/api/calendar?from=2026-09-21&to=2026-10-04')->json('events.0.can_edit'));
        Sanctum::actingAs($member);
        $this->assertFalse($this->getJson('/api/calendar?from=2026-09-21&to=2026-10-04')->json('events.0.can_edit'));
    }

    // ------------------------------------------------------------ Automatismes

    public function test_opening_the_target_page_marks_notifications_as_read(): void
    {
        $user = $this->member('+14185550370');
        Sanctum::actingAs($user);
        $cal = UserNotification::create(['user_id' => $user->id, 'type' => 'event', 'title' => 'A', 'url' => '/calendrier?date=2026-10-02']);
        $other = UserNotification::create(['user_id' => $user->id, 'type' => 'event', 'title' => 'B', 'url' => '/calendrier?date=2026-10-09']);
        $dash = UserNotification::create(['user_id' => $user->id, 'type' => 'task', 'title' => 'C', 'url' => '/tableau-de-bord#exercices']);
        $fiss = UserNotification::create(['user_id' => $user->id, 'type' => 'fiss', 'title' => 'D', 'url' => '/ma-fiche']);

        $this->postJson('/api/me/notifications/read-url', ['url' => '/calendrier?date=2026-10-02&vue=month'])->assertJsonPath('count', 1);
        $this->postJson('/api/me/notifications/read-url', ['url' => '/tableau-de-bord'])->assertJsonPath('count', 0);
        $this->postJson('/api/me/notifications/read-url', ['url' => '/ma-fiche'])->assertJsonPath('count', 1);

        $this->assertNotNull($cal->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->assertNull($dash->fresh()->read_at);
        $this->assertNotNull($fiss->fresh()->read_at);
    }

    public function test_followups_remind_leaders_of_unwelcomed_members_and_pending_requests(): void
    {
        $patriarch = $this->member('+14185550380', $this->juda, 'Patri');
        $this->give($patriarch, 'patriarche', 'tribe', $this->juda->id);
        $newcomer = $this->member('+14185550381', $this->juda, 'Nouveau');
        Profile::where('user_id', $newcomer->id)->update(['created_at' => now()->subDays(4)]);
        MemberRequest::create(['user_id' => $newcomer->id, 'category' => 'aide', 'message' => 'Besoin', 'status' => 'nouvelle']);
        MemberRequest::query()->update(['created_at' => now()->subHours(50)]);

        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();

        $titles = UserNotification::where('user_id', $patriarch->id)->pluck('title')->all();
        $this->assertSame(['À accueillir : Nouveau 381', 'Demande en attente : Nouveau 381'], $titles);
    }

    public function test_migration_grants_broadcast_to_roles_that_already_published_to_everyone(): void
    {
        // Etat d'avant la mise a jour : un role « voit tout + publie », sans broadcast.all.
        $legacy = Role::create(['key' => 'ancien_pasteur', 'name' => 'Ancien', 'scope_kind' => 'none']);
        $legacy->permissions()->sync(\App\Models\Permission::whereIn('key', ['members.view_all', 'announcements.publish'])->pluck('id'));
        $viewer = Role::create(['key' => 'lecteur', 'name' => 'Lecteur', 'scope_kind' => 'none']);
        $viewer->permissions()->sync(\App\Models\Permission::whereIn('key', ['members.view_all'])->pluck('id'));

        $migration = require database_path('migrations/2026_09_26_100001_add_broadcast_and_member_scoped_roles.php');
        $migration->up();
        $migration->up(); // rejouable sans doublon

        $this->assertTrue($legacy->fresh()->permissions->pluck('key')->contains('broadcast.all'));
        $this->assertFalse($viewer->fresh()->permissions->pluck('key')->contains('broadcast.all'), 'Voir tout ne suffit pas pour diffuser');
    }

    public function test_existing_publishers_keep_broadcasting_after_migration(): void
    {
        // Le role Pasteur Assistant (voit tout + publie) garde la diffusion generale.
        $pa = Role::where('key', 'pasteur_assistant')->first();
        $this->assertTrue($pa->permissions->pluck('key')->contains('broadcast.all'));
        $ap = Role::where('key', 'assistant_pasteur')->first();
        // L'AP ne voit plus toute l'eglise : uniquement ses tribus.
        $this->assertFalse($ap->permissions->pluck('key')->contains('members.view_all'));
        $this->assertTrue($ap->permissions->pluck('key')->contains('reports.view'));
        $this->assertSame(0, DB::table('permissions')->where('key', 'members.view_readonly')->count());
        $this->assertSame(0, DB::table('roles')->where('key', 'responsable')->count());
        $this->assertSame(1, DB::table('roles')->where('key', 'garde')->count());
        $this->assertSame('member', Role::where('key', 'accompagnateur')->value('scope_kind'));
        $this->assertSame(1, DB::table('roles')->where('key', 'communication')->count());
    }
}
