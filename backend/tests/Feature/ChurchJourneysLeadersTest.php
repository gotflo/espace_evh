<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\FissEditRequest;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\LeaderReport;
use App\Models\MemberDeletionRequest;
use App\Models\OutboundMessage;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\Tribe;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\GemRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parcours de bout en bout des responsables : chaque role fait ce que son ecran permet (memes adresses que l'application),
 * puis on verifie le retour vers la gestion et vers les autres roles : notifications (cloche), courriels en file,
 * ce que les membres et les autres responsables voient ensuite par leur propre API, et ce qui leur reste refuse.
 *
 * Distribution : tribu Juda (GEMs Bethel et Silo), tribu Levi ; departements Chorale et Cuisine ; le Garde de Bethel,
 * le patriarche et l'AP de Juda, l'AP de Levi, les responsables de la Chorale et de la Cuisine, la communication, le PA,
 * le PR et l'administrateur, avec les droits de production (7 novembre 2026).
 */
class ChurchJourneysLeadersTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Gem $bethel;

    private Gem $silo;

    private Department $chorale;

    private Department $cuisine;

    // Membres
    private User $anne;    // Juda, Bethel, Chorale, courriel

    private User $boaz;    // Juda, Bethel, Cuisine

    private User $claire;  // Juda, Silo, courriel

    private User $david;   // Juda, Silo

    private User $eli;     // Juda, sans GEM

    private User $fanny;   // Juda, sans GEM, courriel

    private User $lydie;   // Levi, Chorale, courriel

    private User $marc;    // Levi, Cuisine

    // Responsables
    private User $garde;          // Gedeon, Garde de Bethel

    private User $patriarch;      // Abraham, patriarche de Juda

    private User $ap;             // Aaron, AP de Juda

    private User $apLevi;         // Ruth, AP de Levi

    private User $choirLeader;    // Myriam, responsable de la Chorale (tribu Levi)

    private User $kitchenLeader;  // Samuel, responsable de la Cuisine (tribu Levi)

    private User $communication;  // Celeste

    private User $pa;             // Paul, Pasteur Assistant

    private User $pr;             // Pierre, Pasteur Resident

    private User $admin;          // Adam, administrateur (droits de production)

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Lundi 2 novembre 2026 : rapport mensuel d'octobre attendu, rapport de GEM de la semaine du 26 octobre.
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        // Jamais de vrai envoi : push coupe, textos en mode journal, aucun detournement des courriels.
        config(['services.webpush.enabled' => false, 'services.sms.driver' => 'log', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->productionRoles();
        Cache::flush();

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id]);
        $this->silo = Gem::create(['name' => 'Silo', 'tribe_id' => $this->juda->id]);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $this->cuisine = Department::create(['name' => 'Cuisine', 'slug' => 'cuisine']);

        $this->pr = $this->give($this->person('Pierre', 'Résident', null, null, 'pr@eglise.test'), 'super_admin');
        $this->pa = $this->give($this->person('Paul', 'Assistant', null, null, 'pa@eglise.test'), 'pasteur_assistant');
        $this->admin = $this->give($this->person('Adam', 'Admin', null, null, 'admin@eglise.test'), 'administrateur');
        $this->communication = $this->give($this->person('Céleste', 'Com'), 'communication');
        $this->patriarch = $this->give($this->person('Abraham', 'Juda', $this->juda, null, 'abraham@eglise.test'), 'patriarche', 'tribe', $this->juda->id);
        $this->ap = $this->give($this->person('Aaron', 'Assistant', null, null, 'aaron@eglise.test'), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->apLevi = $this->give($this->person('Ruth', 'Lévi', null, null, 'ruth@eglise.test'), 'assistant_pasteur', 'tribe', $this->levi->id);
        $this->garde = $this->person('Gédéon', 'Garde', $this->juda, null, 'gedeon@eglise.test');
        GemRules::appointLeader($this->bethel, $this->garde->id, $this->pr->id);
        $this->choirLeader = $this->give($this->person('Myriam', 'Chœur', $this->levi, null, 'myriam@eglise.test', [$this->chorale]),
            'responsable_departement', 'department', $this->chorale->id);
        $this->chorale->leaders()->attach($this->choirLeader->id);
        $this->kitchenLeader = $this->give($this->person('Samuel', 'Cuisine', $this->levi, null, null, [$this->cuisine]),
            'responsable_departement', 'department', $this->cuisine->id);
        $this->cuisine->leaders()->attach($this->kitchenLeader->id);

        $this->anne = $this->person('Anne', 'Béthel', $this->juda, $this->bethel, 'anne@eglise.test', [$this->chorale]);
        $this->boaz = $this->person('Boaz', 'Béthel', $this->juda, $this->bethel, null, [$this->cuisine]);
        $this->claire = $this->person('Claire', 'Silo', $this->juda, $this->silo, 'claire@eglise.test');
        $this->david = $this->person('David', 'Silo', $this->juda, $this->silo);
        $this->eli = $this->person('Eli', 'Juda', $this->juda);
        $this->fanny = $this->person('Fanny', 'Juda', $this->juda, null, 'fanny@eglise.test');
        $this->lydie = $this->person('Lydie', 'Lévi', $this->levi, null, 'lydie@eglise.test', [$this->chorale]);
        $this->marc = $this->person('Marc', 'Lévi', $this->levi, null, null, [$this->cuisine]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================ Outils

    /** @param array<int, Department> $departments */
    private function person(string $first, string $last, ?Tribe $tribe = null, ?Gem $gem = null, ?string $email = null, array $departments = []): User
    {
        $user = User::create(['phone' => '+1418556'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        $profile = Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'homme',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'gem_id' => $gem?->id, 'email' => $email]);
        if ($departments) {
            $profile->departments()->attach(array_map(fn (Department $d) => $d->id, $departments));
        }
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return User::find($user->id);
    }

    /** Roles tels qu'en production (7 novembre 2026), comme DataExtractionSweepTest. */
    private function productionRoles(): void
    {
        $set = function (string $key, array $perms, string $scope = 'none', int $rank = 30) {
            $role = Role::firstOrCreate(['key' => $key], ['name' => $key, 'scope_kind' => $scope, 'rank' => $rank, 'is_system' => true]);
            $role->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id'));
        };
        $set('administrateur', ['accounts.view', 'departments.approve', 'departments.manage', 'exercises.respond', 'roles.assign', 'roles.manage'], 'none', 80);
        $set('responsable_departement', ['announcements.delete', 'announcements.publish', 'departments.manage', 'events.delete', 'events.manage',
            'events.participants', 'members.edit', 'members.export', 'members.view_scope', 'spiritual.view'], 'department', 30);
        $set('protocole', ['appointments.organize', 'exercises.respond'], 'none', 30);
        $set('communication', ['announcements.delete', 'announcements.publish', 'broadcast.all', 'events.delete', 'events.manage',
            'events.participants', 'exercises.respond'], 'none', 45);
    }

    /** Agit en tant que cette personne, compte recharge (roles a jour, aucun cache d'une requete precedente). */
    private function as(User $user): User
    {
        $fresh = User::findOrFail($user->id);
        Sanctum::actingAs($fresh);

        return $fresh;
    }

    /** @return array<int> ids tries */
    private function ids(User ...$users): array
    {
        $ids = array_map(fn (User $u) => $u->id, $users);
        sort($ids);

        return $ids;
    }

    /** @return array<int> membres (profil termine) d'une tribu, tries, sans les exclus */
    private function tribeIds(Tribe $tribe, User ...$except): array
    {
        $ids = Profile::where('tribe_id', $tribe->id)->where('is_completed', true)->pluck('user_id')->map(fn ($id) => (int) $id)
            ->diff(array_map(fn (User $u) => $u->id, $except))->values()->all();
        sort($ids);

        return $ids;
    }

    /** @return array<int> destinataires (ids tries, doublons compris) d'une notification */
    private function recipients(string $type, string $title): array
    {
        return UserNotification::where('type', $type)->where('title', $title)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, string> titres de la cloche, vus par la personne elle-meme */
    private function bell(User $user): array
    {
        $this->as($user);

        return array_column($this->getJson('/api/me/notifications?limit=50')->assertOk()->json('notifications'), 'title');
    }

    /** @return array<int> destinataires (ids tries) des courriels en file d'un type */
    private function mailedTo(string $kind): array
    {
        return OutboundMessage::where('kind', $kind)->where('channel', 'email')->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, User> responsables dont on suit le badge des validations */
    private function validators(): array
    {
        return [
            'chorale' => $this->choirLeader, 'cuisine' => $this->kitchenLeader, 'garde' => $this->garde, 'patriarche' => $this->patriarch,
            'ap' => $this->ap, 'ap_levi' => $this->apLevi, 'communication' => $this->communication, 'pa' => $this->pa, 'pr' => $this->pr, 'admin' => $this->admin,
        ];
    }

    /** @return array<string, int> badge de Validations de chaque responsable */
    private function validationCounts(): array
    {
        $out = [];
        foreach ($this->validators() as $label => $user) {
            $this->as($user);
            $out[$label] = (int) $this->getJson('/api/admin/validations')->assertOk()->json('count');
        }

        return $out;
    }

    /** @return array<string, int> responsables dont le badge a change, et de combien */
    private function delta(array $before, array $after): array
    {
        return array_filter(array_map(fn ($label) => $after[$label] - $before[$label], array_combine(array_keys($before), array_keys($before))));
    }

    // ================================================================ 1. Annonces

    public function test_announcements_reach_exactly_the_chosen_audience_and_the_management_side(): void
    {
        // Responsable de la Chorale : seul son departement lui est propose.
        $this->as($this->choirLeader);
        $options = $this->getJson('/api/admin/audiences?for=announcements')->assertOk()->json();
        $this->assertFalse($options['church']);
        $this->assertSame([[], []], [$options['tribes'], $options['gems']]);
        $this->assertSame(['Chorale'], array_column($options['departments'], 'name'));

        $this->postJson('/api/admin/announcements', ['title' => 'Répétition générale samedi', 'body' => 'Rendez-vous à 14 h au temple.',
            'category' => 'info', 'scopes' => [['type' => 'department', 'id' => $this->chorale->id]]])
            ->assertOk()->assertJsonPath('message', 'Annonce publiée (2 destinataire(s)).');
        $choirNote = Announcement::where('title', 'Répétition générale samedi')->sole();

        // Il ne vise ni un GEM, ni une tribu, ni toute l'eglise, ni un autre departement.
        foreach ([['type' => 'gem', 'id' => $this->bethel->id], ['type' => 'tribe', 'id' => $this->juda->id], ['type' => 'church'],
            ['type' => 'department', 'id' => $this->cuisine->id]] as $scope) {
            $this->postJson('/api/admin/announcements', ['title' => 'Hors périmètre', 'category' => 'info', 'scopes' => [$scope]])->assertForbidden();
        }
        $this->assertSame(0, Announcement::where('title', 'Hors périmètre')->count());

        // Retour : seuls les membres de la Chorale sont prevenus (l'auteur non), et la voient dans leur espace.
        $this->assertSame($this->ids($this->anne, $this->lydie), $this->recipients('announcement', 'Répétition générale samedi'));
        $this->assertSame('Rendez-vous à 14 h au temple.', UserNotification::where('user_id', $this->anne->id)->where('type', 'announcement')->value('body'));
        foreach ([$this->anne, $this->lydie] as $singer) {
            $this->as($singer);
            $feed = $this->getJson('/api/me/announcements')->assertOk()->json();
            $this->assertSame(['Répétition générale samedi'], array_column($feed['announcements'], 'title'));
            $this->assertSame(1, $feed['unread']);
            $this->assertContains('Répétition générale samedi', $this->bell($singer));
        }
        foreach ([$this->boaz, $this->claire, $this->marc, $this->garde, $this->patriarch, $this->choirLeader, $this->kitchenLeader] as $other) {
            $this->as($other);
            $this->assertSame([], $this->getJson('/api/me/announcements')->assertOk()->json('announcements'));
        }

        // Le PA publie pour toute l'eglise : chacun (sauf lui) la recoit, en priorite haute (annonce importante).
        $this->as($this->pa);
        $this->postJson('/api/admin/announcements', ['title' => 'Jeûne de fin d’année', 'body' => 'Trois jours de jeûne et de prière.',
            'category' => 'important', 'scopes' => [['type' => 'church']]])->assertOk();
        $everyone = Profile::where('is_completed', true)->where('user_id', '!=', $this->pa->id)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(17, $everyone);
        $this->assertSame($everyone, $this->recipients('announcement', 'Jeûne de fin d’année'));
        $this->assertSame('high', UserNotification::where('user_id', $this->lydie->id)->where('title', 'Jeûne de fin d’année')->value('priority'));
        foreach ([$this->anne, $this->marc, $this->garde, $this->apLevi, $this->pr, $this->admin, $this->choirLeader] as $member) {
            $this->as($member);
            $this->assertContains('Jeûne de fin d’année', array_column($this->getJson('/api/me/announcements')->json('announcements'), 'title'));
        }
        $this->as($this->pa);
        $this->assertSame([], $this->getJson('/api/me/announcements')->json('announcements'));

        // Lu par Anne : le badge retombe pour elle seule.
        $this->as($this->anne);
        $this->postJson('/api/me/announcements/read')->assertOk();
        $this->assertSame(0, $this->getJson('/api/me/announcements/unread-count')->json('count'));
        $this->as($this->lydie);
        $this->assertSame(2, $this->getJson('/api/me/announcements/unread-count')->json('count'));

        // Cote gestion : le PR et la communication voient les deux annonces ; le responsable de la Chorale la sienne ;
        // le patriarche aucune (hors de sa portee) ; le Garde n'a pas l'onglet.
        $managed = function (User $u) {
            $this->as($u);

            return array_column($this->getJson('/api/admin/announcements')->assertOk()->json('announcements'), 'recipients_count', 'title');
        };
        $this->assertSame(['Jeûne de fin d’année' => 17, 'Répétition générale samedi' => 2], collect($managed($this->pr))->sortKeys()->all());
        $this->assertSame(['Jeûne de fin d’année', 'Répétition générale samedi'], collect($managed($this->communication))->keys()->sort()->values()->all());
        $this->assertSame(['Répétition générale samedi' => 2], $managed($this->choirLeader));
        $this->assertSame([], $managed($this->patriarch));
        $this->as($this->garde);
        $this->getJson('/api/admin/announcements')->assertForbidden();

        // Seul l'auteur (ou la diffusion generale) la retire : pas le patriarche, ni le responsable de la Cuisine.
        foreach ([$this->patriarch, $this->kitchenLeader] as $other) {
            $this->as($other);
            $this->deleteJson("/api/admin/announcements/{$choirNote->id}")->assertForbidden();
        }
        $this->assertNotNull(Announcement::find($choirNote->id));

        // Une annonce ne part pas par courriel (cloche et push seulement) ; chaque publication est tracee.
        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(2, AuditLog::where('action', 'announcement.published')->count());
    }

    // ================================================================ 2. Evenements

    public function test_a_tribe_event_reaches_its_tribe_collects_rsvps_and_reminds_the_audience(): void
    {
        $this->as($this->ap);
        $this->assertSame(['Juda'], array_column($this->getJson('/api/admin/audiences?for=events')->assertOk()->json('tribes'), 'name'));
        $id = $this->postJson('/api/admin/events', ['title' => 'Veillée de Juda', 'description' => 'Louange et intercession.', 'category' => 'priere',
            'starts_at' => '2026-11-04 19:00', 'ends_at' => '2026-11-04 21:00', 'location' => 'Salle B',
            'scopes' => [['type' => 'tribe', 'id' => $this->juda->id]]])->assertCreated()->json('event.id');
        $this->postJson('/api/admin/events', ['title' => 'Ailleurs', 'category' => 'priere', 'starts_at' => '2026-11-04 19:00',
            'scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])->assertForbidden();

        // Toute la tribu Juda est prevenue, Levi non.
        $juda = $this->tribeIds($this->juda);
        $this->assertCount(8, $juda);
        $this->assertSame($juda, $this->recipients('event', 'Nouvel événement : Veillée de Juda'));
        $notice = UserNotification::where('user_id', $this->anne->id)->where('type', 'event')->sole();
        $this->assertSame(['mercredi 4 novembre à 19h00 · Salle B', '/calendrier?date=2026-11-04'], [$notice->body, $notice->url]);

        // Visible dans « Mes evenements » et le calendrier des membres de Juda ; invisible pour Levi.
        $sees = function (User $u) use ($id) {
            $this->as($u);
            $mine = in_array($id, array_column($this->getJson('/api/me/events')->assertOk()->json('events'), 'event_id'), true);
            $calendar = in_array('Veillée de Juda', array_column($this->getJson('/api/calendar?from=2026-11-04&to=2026-11-04')->assertOk()->json('events'), 'title'), true);

            return [$mine, $calendar];
        };
        foreach ([$this->anne, $this->david, $this->garde, $this->patriarch] as $u) {
            $this->assertSame([true, true], $sees($u), $u->profile->full_name);
        }
        foreach ([$this->lydie, $this->marc, $this->choirLeader, $this->apLevi] as $u) {
            $this->assertSame([false, false], $sees($u), $u->profile->full_name);
        }

        // Reponses : Anne vient (et aide), Boaz ne vient pas ; un membre de Levi ne peut pas repondre.
        $this->as($this->lydie);
        $this->postJson("/api/me/events/{$id}/rsvp", ['response' => 'present'])->assertForbidden();
        $this->as($this->anne);
        $this->postJson("/api/me/events/{$id}/rsvp", ['response' => 'present', 'volunteer' => true])->assertOk()->assertJsonPath('going_count', 1);
        $this->as($this->boaz);
        $this->postJson("/api/me/events/{$id}/rsvp", ['response' => 'absent'])->assertOk()->assertJsonPath('going_count', 1);

        // L'AP (auteur) et le patriarche de Juda voient les inscrits ; ni l'AP de Levi, ni le responsable de la Chorale, ni le Garde.
        foreach ([$this->ap, $this->patriarch, $this->pr] as $leader) {
            $this->as($leader);
            $list = $this->getJson("/api/admin/events/{$id}/participants")->assertOk()->json();
            $this->assertSame([['Anne Béthel'], ['Anne Béthel'], 1], [array_column($list['going'], 'name'), array_column($list['volunteers'], 'name'), $list['absent_count']]);
        }
        foreach ([$this->apLevi, $this->choirLeader, $this->garde, $this->anne] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/events/{$id}/participants")->assertForbidden();
        }
        $this->as($this->patriarch);
        $row = collect($this->getJson('/api/admin/events')->assertOk()->json('events'))->firstWhere('id', $id);
        $this->assertSame([1, 1], [$row['going_count'], $row['volunteer_count']]);
        $this->as($this->apLevi);
        $this->assertNull(collect($this->getJson('/api/admin/events')->assertOk()->json('events'))->firstWhere('id', $id));

        // La veille au soir : rappel a toute l'audience, sauf celui qui a dit ne pas venir ; une seule fois.
        Carbon::setTestNow(Carbon::parse('2026-11-03 19:30:00'));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame($this->tribeIds($this->juda, $this->boaz), $this->recipients('event_reminder', 'Demain : Veillée de Juda'));
        $this->assertStringContainsString('19h00 · Salle B', UserNotification::where('user_id', $this->anne->id)->where('title', 'Demain : Veillée de Juda')->value('body'));

        // Peu avant : seuls les inscrits, en priorite haute.
        Carbon::setTestNow(Carbon::parse('2026-11-04 18:15:00'));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame([$this->anne->id], $this->recipients('event_reminder', 'Bientôt : Veillée de Juda'));
        $this->assertSame('high', UserNotification::where('title', 'Bientôt : Veillée de Juda')->value('priority'));
        $this->assertContains('Bientôt : Veillée de Juda', $this->bell($this->anne));

        // Levi n'a rien recu de cet evenement ; aucun courriel.
        $this->assertSame(0, UserNotification::whereIn('user_id', $this->tribeIds($this->levi))->where('title', 'like', '%Veillée de Juda%')->count());
        $this->assertSame(0, OutboundMessage::count());

        // La communication publie pour toute l'eglise : chacun est prevenu ; l'AP de Juda ne gere pas cet evenement.
        $this->as($this->communication);
        $concert = $this->postJson('/api/admin/events', ['title' => 'Concert de Noël', 'category' => 'culte', 'starts_at' => '2026-12-20 18:00',
            'location' => 'Temple', 'scopes' => [['type' => 'church']]])->assertCreated()->json('event.id');
        $everyone = Profile::where('is_completed', true)->where('user_id', '!=', $this->communication->id)->orderBy('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($everyone, $this->recipients('event', 'Nouvel événement : Concert de Noël'));
        $this->as($this->marc);
        $this->assertContains('Concert de Noël', array_column($this->getJson('/api/calendar?from=2026-12-20&to=2026-12-20')->assertOk()->json('events'), 'title'));
        $this->as($this->ap);
        $this->getJson("/api/admin/events/{$concert}/participants")->assertForbidden();
        $this->putJson("/api/admin/events/{$concert}", ['title' => 'Concert annulé ?'])->assertForbidden();
        $this->assertSame('Concert de Noël', Event::find($concert)->title);
        $this->assertSame(2, AuditLog::where('action', 'event.created')->count());
    }

    // ================================================================ 3. Exercices

    public function test_a_tribe_exercise_is_answered_and_each_leader_reads_only_the_answers_of_his_scope(): void
    {
        $this->as($this->patriarch);
        $id = $this->postJson('/api/admin/exercises', ['title' => 'Méditer Jean 15', 'content' => 'Que change « demeurer en lui » cette semaine ?',
            'type' => 'reflexion', 'closes_at' => '2026-11-07 21:00', 'scopes' => [['type' => 'tribe', 'id' => $this->juda->id]]])
            ->assertCreated()->json('id');
        $this->postJson('/api/admin/exercises', ['title' => 'Ailleurs', 'content' => 'x', 'type' => 'reflexion',
            'scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])->assertForbidden();

        // Toute la tribu (sauf l'auteur) est prevenue, avec la date limite ; Levi non.
        $this->assertSame($this->tribeIds($this->juda, $this->patriarch), $this->recipients('task', 'Nouvel exercice : Méditer Jean 15'));
        $this->assertSame('Réflexion avant le samedi 7 novembre à 21h00.', UserNotification::where('user_id', $this->anne->id)->where('type', 'task')->value('body'));
        $this->as($this->lydie);
        $this->assertNotContains($id, array_column($this->getJson('/api/me/exercises')->assertOk()->json('exercises'), 'id'));
        $this->getJson("/api/me/exercises/{$id}")->assertForbidden();
        $this->postJson("/api/me/exercises/{$id}/respond", ['response' => 'Intrusion'])->assertForbidden();

        // Anne (Bethel) et Claire (Silo) repondent.
        $this->as($this->anne);
        $this->assertSame('todo', collect($this->getJson('/api/me/exercises')->json('exercises'))->firstWhere('id', $id)['status']);
        $this->postJson("/api/me/exercises/{$id}/respond", ['response' => 'Rester attaché au Christ dans la prière.'])->assertOk()->assertJsonPath('status', 'done');
        $this->as($this->claire);
        $this->postJson("/api/me/exercises/{$id}/respond", ['response' => 'Porter du fruit au travail.'])->assertOk();

        // Le patriarche (auteur) lit toutes les reponses et le suivi ; l'AP de Juda, le PA et le PR suivent aussi.
        $this->as($this->patriarch);
        $answers = array_column($this->getJson("/api/admin/exercises/{$id}/responses")->assertOk()->json('responses'), 'response', 'name');
        ksort($answers);
        $this->assertSame(['Anne Béthel' => 'Rester attaché au Christ dans la prière.', 'Claire Silo' => 'Porter du fruit au travail.'], $answers);
        foreach ([$this->patriarch, $this->ap, $this->pa, $this->pr] as $leader) {
            $this->as($leader);
            $tracking = $this->getJson("/api/admin/exercises/{$id}/tracking")->assertOk()->json();
            $this->assertSame([7, 2, 5], [$tracking['summary']['total'], $tracking['summary']['done'], $tracking['summary']['todo']]);
            $this->assertSame('Porter du fruit au travail.', collect($tracking['members'])->firstWhere('user_id', $this->claire->id)['response']);
        }

        // Le Garde de Bethel lit la reponse d'Anne (son GEM) sur sa fiche, pas celle de Claire (Silo).
        $this->as($this->garde);
        $anne = $this->getJson("/api/admin/members/{$this->anne->id}/exercises")->assertOk()->json('exercises.0');
        $this->assertSame([$id, 'done', 'Rester attaché au Christ dans la prière.'], [$anne['id'], $anne['status'], $anne['response']]);
        $this->getJson("/api/admin/members/{$this->claire->id}/exercises")->assertForbidden();
        // Le suivi global d'un exercice de toute la tribu reste au patriarche, a l'AP et aux pasteurs (regle de RoleAccessMatrixTest).
        $this->getJson("/api/admin/exercises/{$id}/responses")->assertForbidden();
        $this->getJson("/api/admin/exercises/{$id}/tracking")->assertForbidden();
        $this->assertNotContains($id, array_column($this->getJson('/api/admin/exercises')->assertOk()->json('exercises'), 'id'));

        // Le responsable de la Chorale (aucun droit sur les exercices), la communication et l'AP de Levi : rien.
        $this->as($this->choirLeader);
        $this->getJson('/api/admin/exercises')->assertForbidden();
        $this->postJson('/api/admin/exercises', ['title' => 'X', 'content' => 'x', 'type' => 'reflexion'])->assertForbidden();
        $this->getJson("/api/admin/exercises/{$id}/tracking")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->anne->id}/exercises")->assertForbidden();
        $this->as($this->communication);
        $this->getJson('/api/admin/exercises')->assertForbidden();
        $this->as($this->apLevi);
        $this->getJson("/api/admin/exercises/{$id}/tracking")->assertForbidden();
        $this->getJson("/api/admin/exercises/{$id}/responses")->assertForbidden();

        // Relance par le patriarche : ceux qui n'ont pas fini, une fois par jour.
        $this->as($this->patriarch);
        $this->postJson("/api/admin/exercises/{$id}/remind")->assertOk()->assertJsonPath('count', 5);
        $this->assertSame($this->tribeIds($this->juda, $this->patriarch, $this->anne, $this->claire), $this->recipients('task_reminder', 'Rappel : Méditer Jean 15'));
        $this->postJson("/api/admin/exercises/{$id}/remind")->assertStatus(429);

        // Le Garde donne son propre exercice a son GEM (pas a Silo, ni a la tribu) : Bethel seul est prevenu,
        // et le patriarche le suit dans son historique (exercices des Gardes de sa tribu).
        $this->as($this->garde);
        $this->assertSame(['Béthel'], array_column($this->getJson('/api/admin/audiences?for=exercises')->assertOk()->json('gems'), 'name'));
        foreach ([['type' => 'gem', 'id' => $this->silo->id], ['type' => 'tribe', 'id' => $this->juda->id]] as $scope) {
            $this->postJson('/api/admin/exercises', ['title' => 'Hors GEM', 'content' => 'x', 'type' => 'lecture', 'scopes' => [$scope]])->assertForbidden();
        }
        $gemExercise = $this->postJson('/api/admin/exercises', ['title' => 'Lire Psaume 23', 'content' => 'Lire et noter un verset.', 'type' => 'lecture',
            'scopes' => [['type' => 'gem', 'id' => $this->bethel->id]]])->assertCreated()->json('id');
        $this->assertSame($this->ids($this->anne, $this->boaz), $this->recipients('task', 'Nouvel exercice : Lire Psaume 23'));
        $this->getJson("/api/admin/exercises/{$gemExercise}/tracking")->assertOk()->assertJsonPath('summary.total', 2);
        $this->as($this->patriarch);
        $this->assertContains($gemExercise, array_column($this->getJson('/api/admin/exercises')->assertOk()->json('exercises'), 'id'));
        $this->getJson("/api/admin/exercises/{$gemExercise}/tracking")->assertOk();
        $this->as($this->apLevi);
        $this->getJson("/api/admin/exercises/{$gemExercise}/tracking")->assertForbidden();
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 4. Presences

    public function test_the_garde_takes_the_attendance_of_his_gem_and_the_patriarch_sees_it_in_his_reports(): void
    {
        $sheet = ['attended_on' => '2026-11-01', 'event' => 'Culte du dimanche', 'kind' => 'culte'];
        $roster = function (User $u) {
            $this->as($u);

            return array_column($this->getJson('/api/admin/attendance?'.http_build_query(['date' => '2026-11-01', 'event' => 'Culte du dimanche', 'kind' => 'culte']))
                ->assertOk()->json('members'), 'present', 'full_name');
        };

        // Le Garde fait l'appel de son GEM ; Claire (Silo) est ignoree.
        $this->assertSame(['Anne Béthel', 'Boaz Béthel', 'Gédéon Garde'], array_keys($roster($this->garde)));
        $this->postJson('/api/admin/attendance', $sheet + ['present_user_ids' => [$this->anne->id, $this->boaz->id, $this->claire->id]])
            ->assertOk()->assertJsonPath('message', 'Session enregistrée (2 présent(s)).');
        $stored = Attendance::where('attended_on', '2026-11-01')->orderBy('member_user_id')->get();
        $this->assertSame($this->ids($this->anne, $this->boaz), $stored->pluck('member_user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$this->garde->id], $stored->pluck('recorded_by')->unique()->map(fn ($id) => (int) $id)->values()->all());

        // Le patriarche voit la feuille de toute la tribu, avec les presences du Garde ; il y ajoute Claire.
        $tribe = $roster($this->patriarch);
        $this->assertCount(8, $tribe);
        $this->assertSame([true, true, false, false], [$tribe['Anne Béthel'], $tribe['Boaz Béthel'], $tribe['Claire Silo'], $tribe['David Silo']]);
        $this->postJson('/api/admin/attendance', $sheet + ['present_user_ids' => [$this->anne->id, $this->boaz->id, $this->claire->id]])->assertOk();

        // Le Garde corrige son GEM (Boaz n'etait pas la) : la presence de Claire, hors de son GEM, est conservee.
        $this->as($this->garde);
        $this->postJson('/api/admin/attendance', $sheet + ['present_user_ids' => [$this->anne->id]])->assertOk();
        $this->assertSame($this->ids($this->anne, $this->claire), Attendance::where('attended_on', '2026-11-01')->orderBy('member_user_id')
            ->pluck('member_user_id')->map(fn ($id) => (int) $id)->all());

        // Rapports du patriarche : la session et ses presents, et le compte de chacun.
        Cache::flush();
        $this->as($this->patriarch);
        $month = collect($this->getJson("/api/admin/reports?scope=tribe:{$this->juda->id}&months=1")->assertOk()->json('monthly'))->firstWhere('month', '2026-11');
        $this->assertSame([1, 2], [$month['attendance_sessions'], $month['attendance_present']]);
        $rows = array_column($this->getJson("/api/admin/reports/members?scope=tribe:{$this->juda->id}")->assertOk()->json('members'), 'attendance_3m', 'name');
        $this->assertSame([1, 0, 1], [$rows['Anne Béthel'], $rows['Boaz Béthel'], $rows['Claire Silo']]);

        // Ni le Garde, ni l'AP de Levi n'ont le rapport de la tribu Juda.
        foreach ([$this->garde, $this->apLevi] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/reports?scope=tribe:{$this->juda->id}")->assertForbidden();
        }

        // Le responsable de la Chorale fait l'appel de la repetition de SON departement seulement.
        $this->as($this->choirLeader);
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-10-31', 'event' => 'Répétition', 'kind' => 'repetition',
            'department_id' => $this->chorale->id, 'statuses' => [$this->anne->id => 'present', $this->lydie->id => 'retard', $this->boaz->id => 'present']])->assertOk();
        $this->assertSame([$this->anne->id => 'present', $this->lydie->id => 'retard'],
            Attendance::where('event', 'Répétition · Chorale')->orderBy('member_user_id')->pluck('status', 'member_user_id')->all());
        $this->getJson('/api/admin/attendance?'.http_build_query(['date' => '2026-10-31', 'event' => 'Répétition', 'kind' => 'repetition', 'department_id' => $this->cuisine->id]))
            ->assertForbidden();
        $this->assertSame(0, UserNotification::count(), 'un appel ne notifie personne');
    }

    // ================================================================ 5. Rapport hebdomadaire du GEM

    public function test_the_weekly_gem_report_goes_to_the_patriarch_and_the_ap_of_the_tribe_only(): void
    {
        // Appel du dimanche fait par le Garde : les cases « culte » du rapport sont precochees.
        $this->as($this->garde);
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-11-01', 'event' => 'Culte du dimanche', 'kind' => 'culte',
            'present_user_ids' => [$this->anne->id, $this->garde->id]])->assertOk();
        $page = $this->getJson('/api/admin/gem-reports')->assertOk()->json();
        $this->assertSame(['2026-10-26', ['Béthel']], [$page['due_week'], array_column($page['gems'], 'name')]);
        $prefill = $page['gems'][0]['culte_prefill'];
        sort($prefill);
        $this->assertSame($this->ids($this->anne, $this->garde), $prefill);

        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", ['week_start' => '2026-10-26', 'meeting_held' => true, 'comment' => 'Boaz travaillait dimanche.',
            'attendance' => [
                ['user_id' => $this->anne->id, 'culte' => true, 'rencontre' => true],
                ['user_id' => $this->boaz->id, 'culte' => false, 'rencontre' => true],
                ['user_id' => $this->garde->id, 'culte' => true, 'rencontre' => true],
            ]])->assertOk()->assertJsonPath('report.culte_count', 2)->assertJsonPath('report.meeting_count', 3);
        // Pas pour un autre GEM ; le patriarche ne remplit pas a la place du Garde.
        $this->putJson("/api/admin/gem-reports/{$this->silo->id}", ['week_start' => '2026-10-26', 'meeting_held' => false, 'attendance' => []])->assertForbidden();
        $this->as($this->patriarch);
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", ['week_start' => '2026-10-26', 'meeting_held' => false, 'attendance' => []])->assertForbidden();

        // Le patriarche et l'AP de Juda sont prevenus, eux seuls.
        $this->assertSame($this->ids($this->patriarch, $this->ap), $this->recipients('gem_report', 'Rapport de GEM reçu · Béthel'));
        $n = UserNotification::where('user_id', $this->ap->id)->where('type', 'gem_report')->sole();
        $this->assertSame('Semaine du 26 octobre au 1 novembre 2026 · culte : 2 sur 3 · rencontre : 3 sur 3', $n->body);
        $this->assertSame('/admin/responsables?gem='.$this->bethel->id, $n->url);
        $this->assertContains('Rapport de GEM reçu · Béthel', $this->bell($this->patriarch));

        // Ils le lisent (et les pasteurs) ; un responsable de Levi, de la Chorale ou un membre non.
        foreach ([$this->patriarch, $this->ap, $this->pa, $this->pr] as $reader) {
            $this->as($reader);
            $week = $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertOk()->json('weeks.0');
            $this->assertSame(['submitted', 'Boaz travaillait dimanche.'], [$week['status'], $week['comment']]);
        }
        foreach ([$this->apLevi, $this->choirLeader, $this->anne, $this->admin] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertForbidden();
        }
        // Annuaire du patriarche : Bethel envoye, Silo (sans Garde) sans attente.
        $this->as($this->patriarch);
        $this->assertSame([['Béthel', 'Gédéon Garde', 'submitted'], ['Silo', null, null]], array_map(fn ($g) => [$g['gem'], $g['garde']['name'] ?? null, $g['week_report']],
            $this->getJson('/api/admin/leaders')->assertOk()->json('gardes')));

        $this->assertSame(1, GemWeeklyReport::count());
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 6. Rapport mensuel

    public function test_the_monthly_tribe_report_is_reminded_then_submitted_to_the_ap_pa_and_pr(): void
    {
        // Premiere semaine du mois : invitation a remplir (notification et courriel a ceux qui ont une adresse).
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $invited = UserNotification::where('type', 'leader_report')->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame($this->ids($this->patriarch, $this->choirLeader, $this->kitchenLeader), $invited);
        $this->assertSame($this->ids($this->patriarch, $this->choirLeader), $this->mailedTo('report_reminder'));

        $this->as($this->patriarch);
        $index = $this->getJson('/api/admin/leader-reports')->assertOk()->json();
        $this->assertSame(['2026-10', 'Juda', 'missing'], [$index['due_period'], $index['mine'][0]['scope_name'], $index['mine'][0]['periods'][0]['status']]);
        $body = fn (array $answers, bool $submit) => ['kind' => 'tribe', 'scope_id' => $this->juda->id, 'period' => '2026-10', 'answers' => $answers, 'submit' => $submit];
        $answers = [
            'rencontres_nombre' => 4, 'themes' => 'La persévérance dans la prière', 'dynamique' => 'bonne', 'recadrement' => 'non',
            'activites' => ['evangelisation' => 'Deux sorties au centre-ville', 'appels' => 'Tous les membres ont été appelés'],
            'gems_fonctionnement' => 'oui', 'sante_responsable' => 'Bonne', 'sante_membres_niveau' => 'bon',
            'sante_membres' => 'Membres assidus', 'projets' => 'Une veillée de prière le 4', 'ames_autres' => ['Paul Invité'],
        ];
        // Brouillon : personne n'est prevenu.
        $this->putJson('/api/admin/leader-reports', $body(['rencontres_nombre' => 4], false))->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame([], $this->recipients('leader_report', 'Rapport mensuel reçu · Tribu Juda'));
        // Pas le rapport d'une autre tribu.
        $this->putJson('/api/admin/leader-reports', ['kind' => 'tribe', 'scope_id' => $this->levi->id, 'period' => '2026-10', 'answers' => $answers, 'submit' => true])->assertForbidden();
        $id = $this->putJson('/api/admin/leader-reports', $body($answers, true))->assertOk()->assertJsonPath('status', 'submitted')->json('report_id');

        // Destinataires : l'AP de la tribu et les autorites pastorales (PA, PR) ; pas l'AP de Levi, l'administrateur, le Garde.
        $this->assertSame($this->ids($this->ap, $this->pa, $this->pr), $this->recipients('leader_report', 'Rapport mensuel reçu · Tribu Juda'));
        $n = UserNotification::where('user_id', $this->pa->id)->where('title', 'Rapport mensuel reçu · Tribu Juda')->sole();
        $this->assertStringContainsString('envoyé par Abraham Juda · 1 âme(s) gagnée(s).', $n->body);
        $this->assertSame('/admin/rapports-mensuels?rapport='.$id, $n->url);

        // Le PA le lit et le voit recu ; l'AP aussi ; les autres non.
        $this->as($this->pa);
        $shown = $this->getJson("/api/admin/leader-reports/{$id}")->assertOk()->json();
        $this->assertSame(['Une veillée de prière le 4', false], [$shown['answers']['projets'], $shown['can_edit']]);
        $this->assertSame('submitted', collect($this->getJson('/api/admin/leader-reports')->json('received'))->firstWhere('scope_name', 'Juda')['status']);
        $this->as($this->ap);
        $this->getJson("/api/admin/leader-reports/{$id}")->assertOk();
        foreach ([$this->apLevi, $this->garde, $this->choirLeader, $this->admin, $this->anne] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/leader-reports/{$id}")->assertForbidden();
        }

        // Derniere semaine : la relance ne va qu'a ceux qui n'ont pas envoye (la Chorale et la Cuisine), plus au patriarche.
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $late = UserNotification::where('type', 'leader_report')->where('title', 'like', 'Rappel%')->orderBy('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($this->ids($this->choirLeader, $this->kitchenLeader), $late);
        $this->assertSame(1, OutboundMessage::where('kind', 'report_reminder')->where('user_id', $this->patriarch->id)->count());
        $this->assertSame($this->ids($this->patriarch, $this->choirLeader, $this->choirLeader), $this->mailedTo('report_reminder'));
        $this->assertSame(1, AuditLog::where('action', 'leader_report.submitted')->count());
        $this->assertSame(1, LeaderReport::where('status', 'submitted')->count());
    }

    // ================================================================ 7. Composition des GEMs

    public function test_the_patriarch_composes_a_new_gem_and_its_garde_and_members_are_told(): void
    {
        $this->as($this->patriarch);
        $this->assertSame([['Béthel', 'Silo'], ['Juda']], [array_column($this->getJson('/api/admin/gems')->assertOk()->json('gems'), 'name'),
            array_column($this->getJson('/api/admin/gems')->json('tribes'), 'name')]);
        $this->assertContains('Eli Juda', array_column($this->getJson("/api/admin/gems/candidates?tribe_id={$this->juda->id}")->assertOk()->json('members'), 'full_name'));
        $this->getJson("/api/admin/gems/candidates?tribe_id={$this->levi->id}")->assertForbidden();
        $this->postJson('/api/admin/gems', ['name' => 'Ailleurs', 'tribe_id' => $this->levi->id])->assertForbidden();
        $this->postJson('/api/admin/gems', ['name' => 'Hébron', 'tribe_id' => $this->juda->id, 'leader_user_id' => $this->lydie->id])->assertStatus(422);

        $created = $this->postJson('/api/admin/gems', ['name' => 'Hébron', 'tribe_id' => $this->juda->id, 'leader_user_id' => $this->eli->id])
            ->assertCreated()->json('gem');
        $this->assertSame(['Hébron', 'Eli Juda'], [$created['name'], $created['leader']]);
        $hebron = Gem::findOrFail($created['id']);
        $this->assertTrue(DB::table('role_user')->where(['user_id' => $this->eli->id, 'role_id' => Role::where('key', 'garde')->value('id'),
            'scope_kind' => 'gem', 'scope_id' => $hebron->id])->exists());
        $this->assertSame($hebron->id, (int) Profile::where('user_id', $this->eli->id)->value('gem_id'));

        // Composition : un membre d'une autre tribu ou le Garde d'un autre GEM sont refuses ; David (de Silo) et Fanny entrent.
        $this->putJson("/api/admin/gems/{$hebron->id}/members", ['user_ids' => [$this->david->id, $this->lydie->id]])->assertStatus(422);
        $this->putJson("/api/admin/gems/{$hebron->id}/members", ['user_ids' => [$this->david->id, $this->garde->id]])->assertStatus(422);
        $this->putJson("/api/admin/gems/{$hebron->id}/members", ['user_ids' => [$this->david->id, $this->fanny->id]])
            ->assertOk()->assertJsonPath('added', 2)->assertJsonPath('removed', 0);
        $this->assertSame($hebron->id, (int) Profile::where('user_id', $this->david->id)->value('gem_id'));
        $this->assertSame(2, AuditLog::where('action', 'member.belonging_updated')->count());

        // Retour : chaque arrivant connait son GEM et son Garde ; le Garde est prevenu de chaque arrivee ; l'auteur, rien.
        foreach ([$this->david, $this->fanny] as $member) {
            $n = UserNotification::where('user_id', $member->id)->where('type', 'member')->sole();
            $this->assertSame(['Vous faites partie du GEM Hébron', 'Votre Garde : Eli Juda.'], [$n->title, $n->body]);
        }
        $this->assertSame(['Nouveau membre dans votre GEM : David Silo', 'Nouveau membre dans votre GEM : Fanny Juda'],
            UserNotification::where('user_id', $this->eli->id)->where('type', 'member')->orderBy('title')->pluck('title')->all());
        $this->assertSame(0, UserNotification::whereIn('user_id', [$this->patriarch->id, $this->garde->id, $this->anne->id])->count());

        // Le nouveau Garde voit aussitot ses membres (et eux seuls), et son rapport de la semaine.
        $this->as($this->eli);
        $listed = array_column($this->getJson('/api/admin/members')->assertOk()->json('members'), 'user_id');
        $this->assertContains($this->david->id, $listed);
        $this->assertContains($this->fanny->id, $listed);
        $this->assertNotContains($this->anne->id, $listed);
        $this->assertNotContains($this->claire->id, $listed);
        $this->getJson("/api/admin/members/{$this->david->id}")->assertOk();
        $this->getJson("/api/admin/members/{$this->claire->id}")->assertForbidden();
        $this->assertSame(['Hébron'], array_column($this->getJson('/api/admin/gem-reports')->assertOk()->json('gems'), 'name'));
        $this->as($this->garde);
        $this->getJson("/api/admin/members/{$this->david->id}")->assertForbidden();

        // Ni l'AP de Levi, ni un responsable de departement, ni un Garde ne composent les GEMs de Juda.
        foreach ([$this->apLevi, $this->choirLeader, $this->garde] as $other) {
            $this->as($other);
            $this->putJson("/api/admin/gems/{$hebron->id}/members", ['user_ids' => []])->assertForbidden();
        }
        $this->assertSame(3, Profile::where('gem_id', $hebron->id)->count());
        $this->assertSame(0, OutboundMessage::count());

        // BUG (constate le 2026-11-07 sur la version commitee 939d06f) : nomme Garde depuis la page GEMs, Eli ne recevait
        // aucun avis de sa nouvelle fonction, alors qu'une nomination par la fiche du membre (RoleController::assign,
        // « Nouvelle fonction : ... ») ou par Organisation (responsable de departement) previent toujours la personne :
        // GemController::store -> GemRules::appointLeader -> LeaderRole::sync n'inserait que la ligne role_user.
        // Corrige dans l'arbre de travail (GemController::tellNewGarde, non commite) : l'assertion passe desormais.
        $this->assertSame(1, UserNotification::where('user_id', $this->eli->id)->where('type', 'role')->count(),
            'Le Garde nomme sur la page GEMs devrait etre prevenu de sa nouvelle fonction.');
        $this->assertStringContainsString('Hébron', UserNotification::where('user_id', $this->eli->id)->where('type', 'role')->value('title'));
        $this->assertSame(0, UserNotification::where('type', 'role')->where('user_id', '!=', $this->eli->id)->count());
    }

    // ================================================================ 8. Notes et suivi spirituel

    public function test_the_garde_notes_and_follows_a_member_who_sees_it_and_other_leaders_cannot_erase_it(): void
    {
        $this->as($this->garde);
        $this->postJson("/api/admin/members/{$this->anne->id}/evaluations", ['type' => 'devoir', 'title' => 'Lecture de Jean', 'score' => 16,
            'evaluated_on' => '2026-11-01', 'comment' => 'Très bonne participation.'])->assertCreated()->assertJsonPath('average', 16);
        $this->postJson("/api/admin/members/{$this->anne->id}/spiritual/entries", ['type' => 'visite', 'entry_date' => '2026-11-01',
            'note' => 'Visite à domicile, prière pour sa famille.'])->assertOk();
        $evaluation = Evaluation::sole();
        $entry = SpiritualEntry::sole();
        // Hors de son GEM : refuse.
        $this->postJson("/api/admin/members/{$this->claire->id}/evaluations", ['type' => 'devoir', 'score' => 10, 'evaluated_on' => '2026-11-01'])->assertForbidden();
        $this->postJson("/api/admin/members/{$this->claire->id}/spiritual/entries", ['type' => 'visite', 'entry_date' => '2026-11-01'])->assertForbidden();

        // Anne est prevenue de sa note (elle seule) et la retrouve dans son espace, avec le suivi.
        $this->assertSame([$this->anne->id], UserNotification::where('type', 'evaluation')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $n = UserNotification::where('type', 'evaluation')->sole();
        $this->assertSame(['Nouvelle note : Devoir', 'Lecture de Jean · 16 / 20', '/ma-vie-spirituelle'], [$n->title, $n->body, $n->url]);
        $this->as($this->anne);
        $mine = $this->getJson('/api/me/evaluations')->assertOk()->json();
        $this->assertSame(['Lecture de Jean', 'Très bonne participation.'], [$mine['evaluations'][0]['title'], $mine['evaluations'][0]['comment']]);
        $this->assertEquals(16, $mine['average']);
        $journal = $this->getJson('/api/me/spiritual')->assertOk()->json('entries.0');
        $this->assertSame(['Visite à domicile, prière pour sa famille.', 'Gédéon Garde', false], [$journal['note'], $journal['author'], $journal['mine']]);

        // Le patriarche voit la note et le suivi du Garde, mais ne peut pas les effacer.
        $this->as($this->patriarch);
        $row = $this->getJson("/api/admin/members/{$this->anne->id}/evaluations")->assertOk()->json('evaluations.0');
        $this->assertSame(['Gédéon Garde', false], [$row['author'], $row['can_delete']]);
        $this->assertFalse($this->getJson("/api/admin/members/{$this->anne->id}/spiritual")->assertOk()->json('entries.0.can_delete'));
        $this->deleteJson("/api/admin/evaluations/{$evaluation->id}")->assertForbidden();
        $this->deleteJson("/api/admin/members/{$this->anne->id}/spiritual/entries/{$entry->id}")->assertForbidden();
        // Le responsable de la Chorale (Anne y chante) consulte son suivi sans pouvoir ecrire ni effacer ; pas ses notes.
        $this->as($this->choirLeader);
        $this->assertFalse($this->getJson("/api/admin/members/{$this->anne->id}/spiritual")->assertOk()->json('can_record'));
        $this->deleteJson("/api/admin/members/{$this->anne->id}/spiritual/entries/{$entry->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->anne->id}/evaluations")->assertForbidden();
        $this->deleteJson("/api/admin/evaluations/{$evaluation->id}")->assertForbidden();
        // L'AP de Levi : rien.
        $this->as($this->apLevi);
        $this->getJson("/api/admin/members/{$this->anne->id}/evaluations")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->anne->id}/spiritual")->assertForbidden();
        $this->assertSame([1, 1], [Evaluation::count(), SpiritualEntry::count()]);

        // Son auteur retire sa note ; une autorite pastorale (PA) peut retirer le suivi d'un autre.
        $this->as($this->garde);
        $this->deleteJson("/api/admin/evaluations/{$evaluation->id}")->assertOk();
        $this->as($this->pa);
        $this->deleteJson("/api/admin/members/{$this->anne->id}/spiritual/entries/{$entry->id}")->assertOk();
        $this->assertSame([0, 0], [Evaluation::count(), SpiritualEntry::count()]);
        $this->assertSame(['evaluation.created', 'evaluation.deleted'], AuditLog::where('action', 'like', 'evaluation.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 9. Roles

    public function test_the_pr_names_a_patriarch_who_sees_his_tribe_at_once_and_the_administrator_cannot_hand_out_confidential_roles(): void
    {
        $this->as($this->pr);
        $this->postJson("/api/admin/members/{$this->marc->id}/roles", ['role_key' => 'patriarche', 'scope_id' => $this->levi->id])
            ->assertOk()->assertJsonPath('message', 'Rôle attribué.');
        $this->assertSame([$this->marc->id], $this->recipients('role', 'Nouvelle fonction : Patriarche · tribu Lévi'));
        $this->assertContains('Nouvelle fonction : Patriarche · tribu Lévi', $this->bell($this->marc));
        $this->assertSame(1, AuditLog::where('action', 'role.assigned')->count());

        // Marc voit aussitot les membres de Levi (et seulement eux), son rapport mensuel et ses GEMs a composer.
        $this->as($this->marc);
        $listed = array_column($this->getJson('/api/admin/members?per_page=100')->assertOk()->json('members'), 'user_id');
        foreach ([$this->lydie, $this->choirLeader, $this->kitchenLeader] as $levite) {
            $this->assertContains($levite->id, $listed);
        }
        foreach ([$this->anne, $this->claire, $this->patriarch] as $judean) {
            $this->assertNotContains($judean->id, $listed);
        }
        $this->getJson("/api/admin/members/{$this->lydie->id}")->assertOk();
        $this->getJson("/api/admin/members/{$this->anne->id}")->assertForbidden();
        $this->assertSame(['Lévi'], array_column($this->getJson('/api/admin/leader-reports')->assertOk()->json('mine'), 'scope_name'));
        $this->assertSame(['Lévi'], array_column($this->getJson('/api/admin/gems')->assertOk()->json('tribes'), 'name'));
        // L'AP de Levi le voit dans son annuaire.
        $this->as($this->apLevi);
        $this->assertSame(['Marc Lévi'], array_column($this->getJson('/api/admin/leaders')->assertOk()->json('patriarchs'), 'name'));

        // Administrateur (droits de production) : il gere les roles mais ne confie aucun acces confidentiel.
        $this->as($this->admin);
        $can = array_column($this->getJson('/api/admin/roles')->assertOk()->json('roles'), 'can_assign', 'key');
        $this->assertSame([false, false, false, false, false, false, true],
            [$can['super_admin'], $can['pasteur_assistant'], $can['patriarche'], $can['garde'], $can['responsable_departement'], $can['protocole'], $can['communication']]);
        foreach ([['patriarche', $this->levi->id], ['assistant_pasteur', $this->levi->id], ['garde', $this->bethel->id], ['responsable_departement', $this->chorale->id],
            ['protocole', null], ['pasteur_assistant', null], ['super_admin', null]] as [$key, $scope]) {
            $this->postJson("/api/admin/members/{$this->lydie->id}/roles", ['role_key' => $key, 'scope_id' => $scope])->assertForbidden();
        }
        $this->assertSame(['fidele'], User::find($this->lydie->id)->roles->pluck('key')->all());
        // Un role sans acces confidentiel (communication), oui : la personne en est prevenue.
        $this->postJson("/api/admin/members/{$this->lydie->id}/roles", ['role_key' => 'communication'])->assertOk();
        $this->assertSame([$this->lydie->id], $this->recipients('role', "Nouvelle fonction : Communication (toute l'église)"));
        $this->getJson('/api/admin/members')->assertForbidden();
        $this->assertSame(0, UserNotification::where('type', 'role')->whereNotIn('user_id', [$this->marc->id, $this->lydie->id])->count());
        $this->assertSame(0, OutboundMessage::count());
    }

    // ================================================================ 10. Badges des validations

    public function test_each_pending_request_raises_the_validations_badge_of_the_right_leaders_only(): void
    {
        $start = $this->validationCounts();
        $this->assertSame(array_fill_keys(array_keys($this->validators()), 0), $start);

        // --- Inscription a un service : la Chorale (et ceux qui gerent tous les departements).
        $this->as($this->fanny);
        $this->postJson("/api/me/services/{$this->chorale->id}/join")->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(['chorale' => 1, 'pa' => 1, 'pr' => 1, 'admin' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame([$this->choirLeader->id], $this->recipients('service', 'Inscription au service Chorale : Fanny Juda'));
        $this->assertSame([$this->choirLeader->id], $this->mailedTo('service_request'));
        $join = DepartmentJoinRequest::sole();
        $this->as($this->kitchenLeader);
        $this->postJson("/api/admin/validations/services/{$join->id}", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->choirLeader);
        $this->postJson("/api/admin/validations/services/{$join->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame([$this->fanny->id], $this->recipients('service', 'Bienvenue au service Chorale'));
        $this->assertSame($start, $this->validationCounts());
        // Fanny fait desormais partie de l'audience de la Chorale.
        $this->as($this->choirLeader);
        $this->postJson('/api/admin/announcements', ['title' => 'Bienvenue aux nouveaux choristes', 'category' => 'info',
            'scopes' => [['type' => 'department', 'id' => $this->chorale->id]]])->assertOk();
        $this->assertSame($this->ids($this->anne, $this->fanny, $this->lydie), $this->recipients('announcement', 'Bienvenue aux nouveaux choristes'));

        // --- Modification d'une FISS (Anne, Juda) : patriarche et AP de Juda, PA, PR.
        $this->as($this->anne);
        $form = $this->postJson('/api/me/fiss', ['meditation' => 14, 'priere' => 12, 'jeune' => 10, 'sanctification_corps' => 'bien',
            'sanctification_ame' => 'bien', 'sanctification_esprit' => 'moyen', 'situation_financiere' => 12, 'situation_familiale' => 14,
            'addiction' => 'Aucune', 'addiction_victory' => 'Non', 'companions' => 'Oui', 'inner_wound' => 'Aucune',
            'difficult_situations' => 'Aucune', 'discouragement' => 'Non'])->assertCreated()->json('current');
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Erreur sur le jeûne'])->assertCreated();
        $this->assertSame(['patriarche' => 1, 'ap' => 1, 'pa' => 1, 'pr' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame([$this->patriarch->id], $this->recipients('fiss_request', 'Demande de modification de FISS : Anne Béthel'));
        $fissRequest = FissEditRequest::sole();
        $this->as($this->garde);
        $this->postJson("/api/admin/validations/fiss/{$fissRequest->id}", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->apLevi);
        $this->postJson("/api/admin/validations/fiss/{$fissRequest->id}", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->patriarch);
        $this->postJson("/api/admin/validations/fiss/{$fissRequest->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame([$this->anne->id], $this->recipients('fiss_request', 'Modification de votre FISS approuvée'));
        $this->assertSame($start, $this->validationCounts());

        // --- Changement de tribu (Claire, Juda -> Levi) : les deux tribus et les pasteurs.
        $this->as($this->claire);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Je déménage à Lévi.'])->assertCreated();
        $this->assertSame(['patriarche' => 1, 'ap' => 1, 'ap_levi' => 1, 'pa' => 1, 'pr' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame($this->ids($this->patriarch, $this->apLevi), $this->recipients('tribe_change', 'Changement de tribu demandé : Claire Silo'));
        $this->assertSame($this->ids($this->patriarch, $this->apLevi), $this->mailedTo('tribe_change_request'));
        $tribeRequest = TribeChangeRequest::sole();
        $this->as($this->patriarch);
        $this->postJson("/api/admin/validations/tribe/{$tribeRequest->id}", ['decision' => 'approved'])->assertOk();
        // Cote Juda valide : il ne reste que Levi (et les pasteurs, qui peuvent valider pour les deux).
        $this->assertSame(['ap_levi' => 1, 'pa' => 1, 'pr' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame([$this->claire->id], $this->recipients('tribe_change', 'Changement de tribu : une étape validée'));
        $this->as($this->apLevi);
        $this->postJson("/api/admin/validations/tribe/{$tribeRequest->id}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', 'Changement de tribu effectué.');
        $profile = Profile::where('user_id', $this->claire->id)->first();
        $this->assertSame([$this->levi->id, null], [(int) $profile->tribe_id, $profile->gem_id]);
        $this->assertSame([$this->claire->id], $this->recipients('tribe_change', 'Bienvenue dans la tribu Lévi !'));
        $this->assertSame([$this->claire->id], $this->mailedTo('tribe_change_approved'));
        $this->assertSame($start, $this->validationCounts());
        // Claire suit desormais Levi : le patriarche de Juda ne voit plus sa fiche, l'AP de Levi oui.
        $this->as($this->patriarch);
        $this->getJson("/api/admin/members/{$this->claire->id}")->assertForbidden();
        $this->as($this->apLevi);
        $this->getJson("/api/admin/members/{$this->claire->id}")->assertOk();

        // --- Demande de suppression (responsable de la Chorale, pour Lydie) : le PR seul.
        $this->as($this->kitchenLeader);
        $this->postJson("/api/admin/members/{$this->lydie->id}/deletion-requests", ['reason' => 'Ne vient plus depuis un an.'])->assertForbidden();
        $this->as($this->choirLeader);
        $this->postJson("/api/admin/members/{$this->lydie->id}/deletion-requests", ['reason' => 'Ne vient plus depuis un an.'])->assertCreated();
        $this->assertSame(['pr' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame([$this->pr->id], $this->recipients('member', 'Demande de suppression : Lydie Lévi'));
        $this->assertSame([$this->pr->id], $this->mailedTo('deletion_request'));
        $deletion = MemberDeletionRequest::sole();
        $this->as($this->pa);
        $this->postJson("/api/admin/validations/deletions/{$deletion->id}", ['decision' => 'rejected', 'comment' => 'On la rappelle.'])->assertForbidden();
        $this->as($this->pr);
        $this->postJson("/api/admin/validations/deletions/{$deletion->id}", ['decision' => 'rejected', 'comment' => 'On la rappelle.'])->assertOk();
        $this->assertSame([$this->choirLeader->id], $this->recipients('member', 'Suppression refusée : Lydie Lévi'));
        $this->assertNotNull(User::find($this->lydie->id));
        $this->assertSame($start, $this->validationCounts());

        // --- Nouvelle inscription (vrai parcours : code par SMS en mode journal, puis profil) : le PR seul.
        config(['app.url' => 'http://localhost', 'app.expose_otp' => true]);
        $phone = '+14185593001';
        $code = $this->postJson('/api/auth/request-otp', ['phone' => $phone])->assertOk()->json('dev_code');
        $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'code' => $code])->assertOk()->assertJsonPath('membership.status', 'pending');
        $newcomer = User::where('phone', $phone)->sole();
        $this->as($newcomer);
        $this->putJson('/api/me/email', ['email' => 'degaulle@eglise.test'])->assertOk();
        $this->postJson('/api/profile', ['first_name' => 'Degaulle', 'last_name' => 'Kaswende', 'gender' => 'homme', 'tribe_id' => $this->juda->id,
            'birth_day' => 15, 'birth_month' => 5])->assertOk()->assertJsonPath('membership.submitted', true);
        $this->assertSame(['pr' => 1], $this->delta($start, $this->validationCounts()));
        $this->assertSame([$this->pr->id], $this->recipients('member', 'Nouvelle inscription : Degaulle Kaswende'));
        $this->assertSame([$this->pr->id], $this->mailedTo('membership_request'));
        // Tant qu'il n'est pas valide, il n'est dans aucune liste du patriarche.
        $this->as($this->patriarch);
        $this->assertNotContains($newcomer->id, array_column($this->getJson('/api/admin/members?per_page=100')->json('members'), 'user_id'));
        $this->as($this->pa);
        $this->postJson("/api/admin/validations/memberships/{$newcomer->id}", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->pr);
        $this->postJson("/api/admin/validations/memberships/{$newcomer->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame($start, $this->validationCounts());
        // Valide : il est prevenu (courriel), et les responsables de sa tribu decouvrent le nouveau membre.
        $this->assertSame([$newcomer->id], $this->recipients('system', 'Votre compte est validé'));
        $this->assertSame([$newcomer->id], $this->mailedTo('membership_approved'));
        $welcomed = $this->recipients('member', 'Nouveau membre : Degaulle Kaswende');
        foreach ([$this->patriarch, $this->ap, $this->pa] as $leader) {
            $this->assertContains($leader->id, $welcomed);
        }
        foreach ([$this->pr, $this->apLevi, $this->garde, $this->choirLeader, $this->admin] as $other) {
            $this->assertNotContains($other->id, $welcomed);
        }
        $this->as($this->patriarch);
        $this->assertContains($newcomer->id, array_column($this->getJson('/api/admin/members?per_page=100')->json('members'), 'user_id'));
    }
}
