<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Event;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\LeaderReport;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualEntry;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\GemRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Rapport hebdomadaire des Gardes, annuaire des responsables, fiche d'un Garde, Samedi des miracles. */
class GemReportsAndLeadersTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pastor;

    private User $patriarch;

    private User $ap;

    private User $garde;

    private Gem $bethel;

    /** @var array<int, User> membres du GEM Bethel (hors Garde) */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Lundi 5 octobre 2026 : le rapport attendu est celui de la semaine du 28 septembre au 4 octobre.
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);

        $this->pastor = $this->give($this->member('+14185560001', null, 'Pierre', 'Pasteur'), 'super_admin');
        $this->patriarch = $this->give($this->member('+14185560002', $this->juda, 'Abraham', 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $this->ap = $this->give($this->member('+14185560003', null, 'Aaron', 'Assistant'), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->garde = $this->member('+14185560004', $this->juda, 'Gédéon', 'Garde');
        $this->bethel = Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $this->juda->id]);
        GemRules::appointLeader($this->bethel, $this->garde->id, $this->pastor->id);
        foreach (['Anne', 'Boaz', 'Claire'] as $i => $first) {
            $this->members[] = $m = $this->member('+1418556001'.$i, $this->juda, $first, 'Membre');
            Profile::where('user_id', $m->id)->update(['gem_id' => $this->bethel->id]);
        }
        $this->garde = $this->garde->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre', string $last = 'T'): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'homme',
            'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    /** @param array<int, array{0: User, 1: bool, 2: bool}> $rows */
    private function report(array $rows, bool $meeting = true, ?string $comment = null, string $week = '2026-09-28'): array
    {
        return [
            'week_start' => $week,
            'meeting_held' => $meeting,
            'attendance' => array_map(fn ($r) => ['user_id' => $r[0]->id, 'culte' => $r[1], 'rencontre' => $r[2]], $rows),
            'comment' => $comment,
        ];
    }

    public function test_a_garde_sends_the_weekly_report_to_the_patriarch_and_the_assistant_pastor(): void
    {
        [$anne, $boaz, $claire] = $this->members;
        $otherPatriarch = $this->give($this->member('+14185560020', $this->levi, 'Voisin'), 'patriarche', 'tribe', $this->levi->id);
        $outsider = $this->member('+14185560021', $this->levi, 'Hors', 'GEM');
        // Anne a deja ete pointee presente au culte du dimanche 4 octobre : sa case est precochee.
        Attendance::create(['member_user_id' => $anne->id, 'attended_on' => '2026-10-04', 'event' => 'Culte du dimanche', 'kind' => 'culte', 'status' => 'present']);

        Sanctum::actingAs($this->garde);
        $page = $this->getJson('/api/admin/gem-reports')->assertOk()->json();
        $this->assertSame('2026-09-28', $page['due_week']);
        $this->assertSame('du 28 septembre au 4 octobre 2026', $page['due_label']);
        $this->assertSame('2026-10-10', $page['closes_on']);
        $this->assertSame(['Anne Membre', 'Boaz Membre', 'Claire Membre', 'Gédéon Garde'], array_column($page['gems'][0]['members'], 'name'));
        $this->assertSame([$anne->id], $page['gems'][0]['culte_prefill']);
        $this->assertNull($page['gems'][0]['current']);

        // Une semaine fermee est refusee ; une personne hors du GEM est ignoree.
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$anne, true, true]], true, null, '2026-09-21'))->assertStatus(423);
        $sent = $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([
            [$anne, true, true], [$boaz, true, false], [$this->garde, true, true], [$outsider, true, true],
        ], true, 'Claire est malade : une visite est prévue.'))->assertOk()->json('report');
        $this->assertSame([4, 3, 2], [$sent['members_count'], $sent['culte_count'], $sent['meeting_count']]);
        $this->assertSame(['Anne Membre', 'Boaz Membre', 'Claire Membre', 'Gédéon Garde'], array_column($sent['attendance'], 'name'));
        $this->assertFalse(collect($sent['attendance'])->firstWhere('user_id', $claire->id)['culte']);

        // Le patriarche et l'AP de la tribu sont prevenus, et eux seuls.
        foreach ([$this->patriarch, $this->ap] as $reader) {
            $n = UserNotification::where('user_id', $reader->id)->where('type', 'gem_report')->sole();
            $this->assertSame('Rapport de GEM reçu · GEM Béthel', $n->title);
            $this->assertSame('Semaine du 28 septembre au 4 octobre 2026 · culte : 3 sur 4 · rencontre : 2 sur 4', $n->body);
            $this->assertSame('/admin/responsables?gem='.$this->bethel->id, $n->url);
        }
        $this->assertSame(0, UserNotification::whereIn('user_id', [$otherPatriarch->id, $this->pastor->id, $this->garde->id])->where('type', 'gem_report')->count());

        // Correction pendant la semaine : meme rapport, pas de second avis ; sans rencontre, personne n'y est present.
        Carbon::setTestNow(Carbon::parse('2026-10-07 20:00:00'));
        $fixed = $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$anne, true, true], [$claire, true, true]], false))->assertOk()->json('report');
        $this->assertSame([2, 0, false], [$fixed['culte_count'], $fixed['meeting_count'], $fixed['meeting_held']]);
        $this->assertSame(1, GemWeeklyReport::count());
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('type', 'gem_report')->count());
        $this->assertSame(['gem_report.submitted', 'gem_report.updated'], AuditLog::where('action', 'like', 'gem_report.%')->orderBy('id')->pluck('action')->all());

        // Le dimanche suivant, la semaine attendue change : l'ancienne est figee et passe dans l'historique.
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00'));
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$anne, true, true]]))->assertStatus(423);
        $page = $this->getJson('/api/admin/gem-reports')->assertOk()->json();
        $this->assertSame('2026-10-05', $page['due_week']);
        $this->assertNull($page['gems'][0]['current']);
        $this->assertSame(['2026-09-28'], array_column($page['gems'][0]['history'], 'week_start'));

        // Seul le Garde du GEM remplit son rapport.
        foreach ([$this->patriarch, $anne, $this->pastor] as $other) {
            Sanctum::actingAs($other);
            $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$anne, true, true]], true, null, '2026-10-05'))->assertForbidden();
            $this->getJson('/api/admin/gem-reports')->assertForbidden();
        }
    }

    public function test_gardes_are_reminded_from_sunday_evening_until_the_report_is_sent(): void
    {
        [$anne] = $this->members;
        $sion = Gem::create(['name' => 'GEM Sion', 'tribe_id' => $this->juda->id]);
        $second = $this->member('+14185560030', $this->juda, 'Josué', 'Garde');
        GemRules::appointLeader($sion, $second->id, $this->pastor->id);
        Gem::create(['name' => 'GEM sans Garde', 'tribe_id' => $this->juda->id]);
        $reminders = fn (User $u) => UserNotification::where('user_id', $u->id)->where('type', 'gem_report')->orderBy('id')->pluck('title')->all();

        // Dimanche avant 18 h : rien. A partir de 18 h : une invitation par Garde, une seule fois.
        Carbon::setTestNow(Carbon::parse('2026-10-04 17:00:00'));
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->assertSame([], $reminders($this->garde));
        Carbon::setTestNow(Carbon::parse('2026-10-04 18:30:00'));
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->assertSame(['Rapport de la semaine à envoyer'], $reminders($this->garde));
        $this->assertSame(['Rapport de la semaine à envoyer'], $reminders($second));
        $this->assertStringContainsString('GEM Béthel · semaine du 28 septembre au 4 octobre 2026', UserNotification::where('user_id', $this->garde->id)->first()->body);

        // Le premier Garde envoie son rapport : seule la relance du second part le mardi.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        Sanctum::actingAs($this->garde);
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$anne, true, true]]))->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->assertSame(['Rapport de la semaine à envoyer'], $reminders($this->garde));
        $this->assertSame(['Rapport de la semaine à envoyer', 'Rappel : rapport de la semaine à envoyer'], $reminders($second));

        // Jeudi : plus de relance.
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'gem-reports'])->assertSuccessful();
        $this->assertCount(2, $reminders($second));
    }

    public function test_pastors_see_every_leader_with_their_belonging_and_tribe_leaders_only_their_tribe(): void
    {
        $leviPatriarch = $this->give($this->member('+14185560040', $this->levi, 'Lévi', 'Patriarche'), 'patriarche', 'tribe', $this->levi->id);
        $this->give($this->ap, 'assistant_pasteur', 'tribe', $this->levi->id);
        $zion = Gem::create(['name' => 'GEM Sion', 'tribe_id' => $this->levi->id]);
        $leviGarde = $this->member('+14185560041', $this->levi, 'Josué', 'Garde');
        GemRules::appointLeader($zion, $leviGarde->id, $this->pastor->id);
        Gem::create(['name' => 'GEM Hébron', 'tribe_id' => $this->juda->id]);
        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $accueil = Department::create(['name' => 'Accueil test', 'slug' => 'accueil-test']);
        $respo = $this->member('+14185560042', $this->levi, 'Myriam', 'Respo');
        $chorale->leaders()->attach($respo->id);
        $accueil->leaders()->attach($respo->id);
        Sanctum::actingAs($this->garde);
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([[$this->members[0], true, true]]))->assertOk();

        // Pasteur : les quatre listes, avec l'appartenance de chacun.
        Sanctum::actingAs($this->pastor);
        $all = $this->getJson('/api/admin/leaders')->assertOk()->json();
        $this->assertSame('church', $all['scope']);
        $this->assertSame([['Aaron Assistant', ['Juda', 'Lévi']]], array_map(fn ($p) => [$p['name'], $p['tribes']], $all['assistants']));
        $this->assertSame([['Abraham Patriarche', ['Juda']], ['Lévi Patriarche', ['Lévi']]], array_map(fn ($p) => [$p['name'], $p['tribes']], $all['patriarchs']));
        $this->assertSame([], $all['tribes_without_patriarch']);
        $this->assertSame([['Myriam Respo', ['Accueil test', 'Chorale']]], array_map(fn ($p) => [$p['name'], $p['departments']], $all['department_leaders']));
        $this->assertSame('+14185560042', $all['department_leaders'][0]['phone']);
        $this->assertSame([
            ['GEM Béthel', 'Juda', 'Gédéon Garde', 4, 'submitted'],
            ['GEM Hébron', 'Juda', null, 0, null],
            ['GEM Sion', 'Lévi', 'Josué Garde', 1, 'missing'],
        ], array_map(fn ($g) => [$g['gem'], $g['tribe'], $g['garde']['name'] ?? null, $g['members_count'], $g['week_report']], $all['gardes']));

        // Patriarche : les Gardes de SA tribu (ni les AP, ni les responsables de departement, ni les autres tribus).
        Sanctum::actingAs($this->patriarch);
        $mine = $this->getJson('/api/admin/leaders')->assertOk()->json();
        $this->assertSame('tribes', $mine['scope']);
        $this->assertSame(['GEM Béthel', 'GEM Hébron'], array_column($mine['gardes'], 'gem'));
        $this->assertSame(['Abraham Patriarche'], array_column($mine['patriarchs'], 'name'));
        $this->assertSame([[], []], [$mine['assistants'], $mine['department_leaders']]);
        Sanctum::actingAs($leviPatriarch);
        $this->assertSame(['GEM Sion'], array_column($this->getJson('/api/admin/leaders')->assertOk()->json('gardes'), 'gem'));

        // Un Garde, un responsable de departement ou un simple membre n'ont pas l'annuaire.
        foreach ([$this->garde, $respo->fresh(), $this->members[0]] as $other) {
            Sanctum::actingAs($other);
            $this->getJson('/api/admin/leaders')->assertForbidden();
        }
    }

    public function test_a_gardes_page_shows_how_the_gem_is_led(): void
    {
        [$anne, $boaz, $claire] = $this->members;
        $leviPatriarch = $this->give($this->member('+14185560050', $this->levi, 'Lévi', 'Patriarche'), 'patriarche', 'tribe', $this->levi->id);
        SpiritualHealthForm::create(['user_id' => $anne->id, 'period' => '2026-10', 'meditation' => 15, 'priere' => 15, 'jeune' => 10]);
        SpiritualEntry::create(['member_user_id' => $boaz->id, 'author_user_id' => $this->garde->id, 'type' => 'visite', 'entry_date' => '2026-10-03', 'note' => 'Visite à domicile']);
        Attendance::create(['member_user_id' => $anne->id, 'attended_on' => '2026-10-04', 'event' => 'Culte', 'kind' => 'culte', 'status' => 'present', 'recorded_by' => $this->garde->id]);
        // Garde nomme mi-septembre : le rapport de la semaine du 28 septembre est attendu de lui.
        DB::table('role_user')->where('scope_kind', 'gem')->where('scope_id', $this->bethel->id)->update(['created_at' => '2026-09-16 10:00:00']);

        // Avant tout rapport : une semaine attendue, aucune recue.
        Sanctum::actingAs($this->patriarch);
        $before = $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertOk()->json();
        $this->assertSame([1, 0], [$before['indicators']['reports_expected'], $before['indicators']['reports_sent']]);
        $this->assertSame('missing', $before['weeks'][0]['status']);

        Sanctum::actingAs($this->garde);
        $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $this->report([
            [$anne, true, true], [$boaz, true, false], [$claire, false, false], [$this->garde, true, true],
        ], true, 'Claire à visiter.'))->assertOk();

        Sanctum::actingAs($this->patriarch);
        $page = $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertOk()->json();
        $this->assertSame(['id' => $this->bethel->id, 'name' => 'GEM Béthel', 'tribe' => 'Juda'], $page['gem']);
        $this->assertSame('Gédéon Garde', $page['garde']['name']);
        $this->assertSame('2026-09-16', $page['garde']['since']);
        $i = $page['indicators'];
        $this->assertSame([4, 1, 1, 75, 1, 50, 1], [$i['members'], $i['reports_expected'], $i['reports_sent'], $i['culte_rate'], $i['meetings_held'], $i['meeting_rate'], $i['fiss_filled']]);
        $this->assertSame([1, 1], [$i['followups'], $i['attendance_sheets']]);
        $anneRow = collect($page['members'])->firstWhere('user_id', $anne->id);
        $this->assertSame([true, 1, 1, false], [$anneRow['fiss_current'], $anneRow['culte'], $anneRow['rencontre'], $anneRow['is_garde']]);
        $this->assertSame('Claire à visiter.', $page['weeks'][0]['comment']);
        $labels = array_column($page['timeline'], 'label');
        $this->assertContains('Suivi : Visite · Boaz Membre', $labels);
        $this->assertContains('Appel : Culte du 4 octobre · 1 présent(s)', $labels);
        $this->assertContains('Rapport de la semaine du 28 septembre au 4 octobre 2026 envoyé', $labels);
        // Le contenu des notes de suivi n'est pas expose dans la fiche.
        $this->assertStringNotContainsString('domicile', json_encode($page, JSON_UNESCAPED_UNICODE));

        // Perimetre : l'AP de la tribu, le pasteur et le Garde lui-meme ; pas une autre tribu, pas un membre.
        foreach ([$this->ap, $this->pastor, $this->garde] as $allowed) {
            Sanctum::actingAs($allowed);
            $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertOk();
        }
        foreach ([$leviPatriarch, $anne] as $denied) {
            Sanctum::actingAs($denied);
            $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->assertForbidden();
        }
    }

    public function test_samedi_des_miracles_is_part_of_the_weekly_program(): void
    {
        $event = Event::where('title', 'Samedi des miracles')->sole();
        $this->assertSame(['weekly', true, 'culte'], [$event->recurrence, (bool) $event->remind_all, $event->category]);
        $this->assertTrue($event->isForWholeChurch());
        $this->assertSame([6, '18:30', '20:30'], [$event->starts_at->dayOfWeekIso, $event->starts_at->format('H:i'), $event->ends_at->format('H:i')]);

        // Dans le calendrier de chaque membre, chaque samedi.
        Sanctum::actingAs($this->members[0]);
        $saturday = $event->starts_at->copy()->addWeeks(3)->toDateString();
        $titles = collect($this->getJson("/api/calendar?from={$saturday}&to={$saturday}")->assertOk()->json('events'))
            ->map(fn ($e) => substr($e['starts_at'], 11, 5).' '.$e['title'])->all();
        $this->assertSame(['18:30 Samedi des miracles'], $titles);

        // Rappel a tous 30 minutes avant, comme les autres rendez-vous du programme.
        Carbon::setTestNow(Carbon::parse($saturday.' 18:00:00'));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame('Bientôt : Samedi des miracles', UserNotification::where('user_id', $this->members[0]->id)->where('type', 'event_reminder')->sole()->title);
    }

    public function test_exporting_a_monthly_report_to_pdf_is_traced_and_limited_to_its_readers(): void
    {
        $report = LeaderReport::create(['kind' => 'tribe', 'scope_id' => $this->juda->id, 'scope_name' => 'Juda', 'period' => '2026-09',
            'author_user_id' => $this->patriarch->id, 'status' => 'submitted', 'answers' => ['projets' => 'Veillée'], 'souls' => [], 'submitted_at' => now()]);

        Sanctum::actingAs($this->pastor);
        $this->postJson("/api/admin/leader-reports/{$report->id}/exported")->assertOk();
        $log = AuditLog::where('action', 'leader_report.exported')->sole();
        $this->assertSame([$this->pastor->id, 'Juda', '2026-09'], [$log->user_id, $log->context['scope'], $log->context['period']]);

        Sanctum::actingAs($this->members[0]);
        $this->postJson("/api/admin/leader-reports/{$report->id}/exported")->assertForbidden();
    }
}
