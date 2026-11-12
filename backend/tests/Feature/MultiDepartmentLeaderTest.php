<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\Gem;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Responsable de plusieurs departements (ou d'une tribu ET d'un service), comme en production : chaque groupe reste
 * separe. Rien n'est adresse d'office a tous ses groupes, un appel se fait par departement, la tribu n'est jamais
 * melangee aux choristes d'autres tribus, et chaque ecran dit de quel groupe il s'agit.
 */
class MultiDepartmentLeaderTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    private Department $chorale;

    private Department $accueil;

    private User $rachel; // responsable de la Chorale ET de l'Accueil

    private User $simon; // responsable de la Chorale seulement

    private User $paul; // patriarche de Juda ET responsable de l'Accueil

    private User $laban; // patriarche de Levi seulement

    private User $chloe; // Chorale (Levi)

    private User $karim; // Accueil (Levi)

    private User $ben; // Chorale et Accueil (Juda, GEM Bethel)

    private User $jean; // Juda, aucun service

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 10:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        // Role des responsables de departement tel qu'en production (7 novembre 2026).
        Role::firstOrCreate(['key' => DepartmentLeadership::ROLE], ['name' => 'Responsable de département', 'scope_kind' => 'department', 'rank' => 30, 'is_system' => true])
            ->permissions()->sync(Permission::whereIn('key', ['announcements.delete', 'announcements.publish', 'departments.manage', 'events.delete',
                'events.manage', 'events.participants', 'members.edit', 'members.export', 'members.view_scope', 'spiritual.view'])->pluck('id'));

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true]);
        $this->accueil = Department::create(['name' => 'Accueil', 'slug' => 'accueil', 'is_active' => true]);

        $this->rachel = $this->lead($this->lead($this->person('Rachel', 'Deux', $this->juda), $this->chorale), $this->accueil);
        $this->simon = $this->lead($this->person('Simon', 'Chantre', $this->levi), $this->chorale);
        $this->paul = $this->lead($this->give($this->person('Paul', 'Patriarche', $this->juda), 'patriarche', 'tribe', $this->juda->id), $this->accueil);
        $this->laban = $this->give($this->person('Laban', 'Patriarche', $this->levi), 'patriarche', 'tribe', $this->levi->id);
        $this->chloe = $this->person('Chloé', 'Chorale', $this->levi);
        $this->karim = $this->person('Karim', 'Accueil', $this->levi);
        $this->ben = $this->person('Ben', 'LesDeux', $this->juda, $this->bethel);
        $this->jean = $this->person('Jean', 'Juda', $this->juda);
        $this->chorale->members()->syncWithoutDetaching([$this->chloe->profile->id, $this->ben->profile->id]);
        $this->accueil->members()->syncWithoutDetaching([$this->karim->profile->id, $this->ben->profile->id]);
        UserNotification::query()->delete();
    }

    // ------------------------------------------------------------------ Destinataires : rien d'office s'il y a plusieurs groupes

    public function test_nothing_is_addressed_to_all_his_groups_by_default(): void
    {
        $post = fn (array $extra = []) => $this->postJson('/api/admin/announcements', $extra + ['title' => 'Répétition samedi', 'category' => 'info']);

        // Deux departements, ou une tribu et un service : il doit choisir.
        foreach ([$this->rachel, $this->paul] as $leader) {
            $this->as($leader);
            $this->getJson('/api/admin/audiences?for=announcements')->assertOk();
            $post()->assertStatus(422)->assertJsonValidationErrors(['scopes']);
        }
        $this->assertSame(0, Announcement::count());

        // Choix explicite : la Chorale seulement, jamais l'Accueil.
        $this->as($this->rachel);
        $post(['scopes' => [['type' => 'department', 'id' => $this->chorale->id]]])->assertSuccessful();
        $got = UserNotification::where('title', 'Répétition samedi')->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($this->ids([$this->chloe, $this->ben, $this->simon]), $got);

        // Un seul groupe : il reste choisi d'office (responsable d'un seul service, patriarche de sa tribu).
        $this->as($this->simon);
        $post(['title' => 'Chorale seule'])->assertSuccessful();
        $this->assertSame([['type' => 'department', 'id' => $this->chorale->id]], Announcement::where('title', 'Chorale seule')->sole()->audienceList());
        $this->as($this->laban);
        $post(['title' => 'Tribu seule'])->assertSuccessful();
        $this->assertSame([['type' => 'tribe', 'id' => $this->levi->id]], Announcement::where('title', 'Tribu seule')->sole()->audienceList());

        // Evenements : meme regle.
        $this->as($this->rachel);
        $this->postJson('/api/admin/events', ['title' => 'Répétition', 'category' => 'reunion', 'starts_at' => '2026-11-14 10:00'])
            ->assertStatus(422)->assertJsonValidationErrors(['scopes']);
        $this->postJson('/api/admin/events', ['title' => 'Répétition', 'category' => 'reunion', 'starts_at' => '2026-11-14 10:00',
            'scopes' => [['type' => 'department', 'id' => $this->accueil->id]]])->assertCreated();
    }

    // ------------------------------------------------------------------ Presences : une feuille par departement

    public function test_attendance_is_one_sheet_per_department_and_never_mixes_the_tribe_with_a_service(): void
    {
        $roster = fn (array $q = []) => $this->getJson('/api/admin/attendance?'.http_build_query(['date' => '2026-11-08', 'event' => 'Culte'] + $q));
        $names = fn ($response) => collect($response->assertOk()->json('members'))->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        // Deux departements : il choisit lequel.
        $this->as($this->rachel);
        $roster()->assertStatus(422);
        $chorale = $roster(['department_id' => $this->chorale->id]);
        $this->assertSame('Culte · Chorale', $chorale->json('event'));
        $this->assertSame($this->ids([$this->rachel, $this->simon, $this->chloe, $this->ben]), $names($chorale));
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-11-08', 'event' => 'Culte'])->assertStatus(422);

        // Un seul departement : le sien, sans rien choisir.
        $this->as($this->simon);
        $mine = $roster();
        $this->assertSame('Culte · Chorale', $mine->json('event'));
        $this->assertSame($this->ids([$this->rachel, $this->simon, $this->chloe, $this->ben]), $names($mine));

        // Patriarche ET responsable de l'Accueil : sa tribu seule, puis l'Accueil seul.
        $this->as($this->paul);
        $tribe = $roster();
        $this->assertSame('Culte', $tribe->json('event'));
        $this->assertSame($this->ids([$this->rachel, $this->paul, $this->ben, $this->jean]), $names($tribe));
        $this->assertNotContains($this->karim->id, $names($tribe), 'un membre de l\'Accueil d\'une autre tribu n\'est pas sur la feuille de la tribu');
        $this->assertSame($this->ids([$this->rachel, $this->paul, $this->karim, $this->ben]), $names($roster(['department_id' => $this->accueil->id])));
        $roster(['department_id' => $this->chorale->id])->assertForbidden();
    }

    // ------------------------------------------------------------------ Membres, accueil, Servir, Validations

    public function test_each_group_has_its_own_list_figures_and_labels(): void
    {
        $list = fn (string $q) => collect($this->getJson('/api/admin/members?'.$q)->assertOk()->json('members'))->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->as($this->rachel);
        $this->assertSame($this->ids([$this->rachel, $this->simon, $this->chloe, $this->ben]), $list("department_id={$this->chorale->id}"));
        $this->assertSame($this->ids([$this->rachel, $this->paul, $this->karim, $this->ben]), $list("department_id={$this->accueil->id}"));
        $groups = $this->getJson('/api/admin/stats')->assertOk()->json('groups');
        $this->assertSame([['department', 'Accueil', 4], ['department', 'Chorale', 4]], array_map(fn ($g) => [$g['type'], $g['name'], $g['total']], $groups));
        $recent = collect($this->getJson('/api/admin/stats')->json('recent'))->keyBy('user_id');
        $this->assertSame(['Chorale'], $recent[$this->chloe->id]['departments']);

        // Servir : ses deux services marques « responsable », pas les autres.
        $this->accueil->forceFill(['name' => 'Accueil'])->save();
        Department::create(['name' => 'Cuisine', 'slug' => 'cuisine', 'is_active' => true]);
        $services = collect($this->getJson('/api/me/services')->assertOk()->json('services'))->keyBy('name');
        $this->assertTrue($services['Chorale']['i_lead']);
        $this->assertTrue($services['Accueil']['i_lead']);
        $this->assertFalse($services['Cuisine']['i_lead']);

        // Patriarche + Accueil : sa tribu et l'Accueil, chacun ses chiffres ; filtre par GEM.
        $this->as($this->paul);
        $this->assertSame([['tribe', 'Tribu Juda'], ['department', 'Accueil']],
            array_map(fn ($g) => [$g['type'], $g['name']], $this->getJson('/api/admin/stats')->json('groups')));
        $this->assertSame($this->ids([$this->ben]), $list("gem_id={$this->bethel->id}"));

        // Un seul groupe : pas de decoupage.
        $this->as($this->simon);
        $this->assertSame([], $this->getJson('/api/admin/stats')->json('groups'));
    }

    public function test_validations_name_opens_only_a_page_he_may_see(): void
    {
        $zoe = $this->person('Zoé', 'Nouvelle', $this->levi);
        DepartmentJoinRequest::create(['user_id' => $zoe->id, 'department_id' => $this->chorale->id, 'status' => 'pending']);
        DepartmentJoinRequest::create(['user_id' => $this->jean->id, 'department_id' => $this->accueil->id, 'status' => 'pending']);

        $this->as($this->rachel);
        $items = collect($this->getJson('/api/admin/validations')->assertOk()->json('services'))->keyBy(fn ($r) => $r['member']['user_id']);
        $this->assertSame(['Chorale', 'Accueil'], [$items[$zoe->id]['department']['name'], $items[$this->jean->id]['department']['name']]);
        $this->assertFalse($items[$zoe->id]['can_open'], 'pas encore dans ses services : sa fiche ne s\'ouvre pas');
        $this->getJson("/api/admin/members/{$zoe->id}")->assertForbidden();
        $this->as($this->paul); // patriarche de Juda : Jean est de sa tribu
        $this->assertTrue(collect($this->getJson('/api/admin/validations')->json('services'))->firstWhere('member.user_id', $this->jean->id)['can_open']);
    }

    public function test_the_pending_count_badge_matches_the_validations_list_and_drops_after_a_decision(): void
    {
        $zoe = $this->person('Zoé', 'Nouvelle', $this->levi);
        $choir = DepartmentJoinRequest::create(['user_id' => $zoe->id, 'department_id' => $this->chorale->id, 'status' => 'pending']);
        DepartmentJoinRequest::create(['user_id' => $this->jean->id, 'department_id' => $this->accueil->id, 'status' => 'pending']);

        // Meme nombre que l'ecran Validations, pour chacun (responsables, patriarche, simple fidele).
        foreach ([$this->rachel, $this->simon, $this->paul, $this->laban, $this->jean, $this->chloe] as $user) {
            $this->as($user);
            $this->assertSame($this->getJson('/api/admin/validations')->assertOk()->json('count'),
                $this->getJson('/api/admin/validations/count')->assertOk()->json('count'), "compteur de {$user->profile->full_name}");
        }
        $this->as($this->rachel);
        $this->getJson('/api/admin/validations/count')->assertJsonPath('count', 2)->assertJsonPath('by.services', 2);
        $this->as($this->simon);
        $this->getJson('/api/admin/validations/count')->assertJsonPath('by.services', 1);
        $this->as($this->chloe);
        $this->getJson('/api/admin/validations/count')->assertJsonPath('count', 0);

        // Une demande traitee : la pastille baisse.
        $this->as($this->rachel);
        $this->postJson("/api/admin/validations/services/{$choir->id}", ['decision' => 'approved'])->assertOk();
        $this->getJson('/api/admin/validations/count')->assertJsonPath('count', 1)->assertJsonPath('by.services', 1);
        $this->as($this->simon);
        $this->getJson('/api/admin/validations/count')->assertJsonPath('count', 0);
    }

    public function test_the_monthly_reminder_names_each_group_in_one_message(): void
    {
        Profile::where('user_id', $this->rachel->id)->update(['email' => 'rachel@exemple.test']);
        Profile::where('user_id', $this->paul->id)->update(['email' => 'paul@exemple.test']);
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();

        // Rachel (deux departements) : UNE notification qui nomme les deux, et UN courriel.
        $mine = UserNotification::where('user_id', $this->rachel->id)->where('type', 'leader_report')->get();
        $this->assertCount(1, $mine);
        $this->assertSame('2 rapports d’octobre 2026 à remplir', $mine[0]->title);
        $this->assertStringContainsString('Accueil · Chorale', $mine[0]->body);
        $mails = \App\Models\OutboundMessage::where('kind', 'report_reminder')->where('user_id', $this->rachel->id)->get();
        $this->assertCount(1, $mails);
        $html = (new \App\Mail\NotificationMail($mails[0]->subject, $mails[0]->view, $mails[0]->payload))->render();
        $this->assertStringContainsString('Département Accueil', $html);
        $this->assertStringContainsString('Département Chorale', $html);
        // Paul (sa tribu et un departement) : pareil, la tribu et le departement dans le meme message.
        $this->assertSame(['2 rapports d’octobre 2026 à remplir'], UserNotification::where('user_id', $this->paul->id)->where('type', 'leader_report')->pluck('title')->all());
        // Simon (un seul departement) : le message habituel, qui ouvre directement son rapport.
        $simon = UserNotification::where('user_id', $this->simon->id)->where('type', 'leader_report')->sole();
        $this->assertSame('Rapport d’octobre 2026 à remplir · Chorale', $simon->title);

        // Fin du mois : la relance ne nomme plus que le rapport qui manque (Rachel a envoye celui de l'Accueil).
        \App\Models\LeaderReport::create(['kind' => 'department', 'scope_id' => $this->accueil->id, 'scope_name' => 'Accueil', 'period' => '2026-10',
            'author_user_id' => $this->rachel->id, 'status' => 'submitted', 'answers' => [], 'souls' => [], 'submitted_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-26 10:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $last = UserNotification::where('user_id', $this->rachel->id)->where('type', 'leader_report')->latest('id')->first();
        $this->assertSame('Rappel : rapport d’octobre 2026 à remplir · Chorale', $last->title);
        $this->assertSame(2, UserNotification::where('user_id', $this->rachel->id)->where('type', 'leader_report')->count());
    }

    public function test_newcomers_to_welcome_come_as_one_list_per_leader(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        foreach (['Anne', 'Bruno', 'Carole'] as $first) {
            $u = $this->person($first, 'Nouveau', $this->juda);
            Profile::where('user_id', $u->id)->update(['created_at' => now()->subDays(5), 'welcomed_at' => null]);
            User::whereKey($u->id)->update(['approval_decided_at' => now()->subDays(5)]);
        }
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        // Le patriarche de Juda : un seul message avec les trois noms (et non trois notifications).
        $got = UserNotification::where('user_id', $this->paul->id)->where('type', 'member')->get();
        $this->assertCount(1, $got);
        $this->assertSame('3 nouveaux à accueillir', $got[0]->title);
        foreach (['Anne', 'Bruno', 'Carole'] as $first) {
            $this->assertStringContainsString($first, $got[0]->body);
        }
    }

    // ------------------------------------------------------------------ Outils

    private function person(string $first, string $last, ?Tribe $tribe, ?Gem $gem = null): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418558'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function lead(User $user, Department $department): User
    {
        $this->give($user, DepartmentLeadership::ROLE, 'department', $department->id);
        DepartmentLeadership::appoint($department, $user->id, null);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    /** @param array<int, User> $users */
    private function ids(array $users): array
    {
        $ids = array_map(fn (User $u) => $u->id, $users);
        sort($ids);

        return $ids;
    }
}
