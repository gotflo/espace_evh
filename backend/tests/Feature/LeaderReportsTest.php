<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Gem;
use App\Models\LeaderReport;
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

/** Rapports mensuels du patriarche (tribu) et du responsable de departement. */
class LeaderReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private Department $chorale;

    protected function setUp(): void
    {
        parent::setUp();
        // Debut octobre : le rapport attendu est celui de septembre.
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre', string $last = 'T', ?string $createdAt = null): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        $profile = Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'homme',
            'is_completed' => true, 'tribe_id' => $tribe?->id]);
        if ($createdAt) {
            Profile::whereKey($profile->id)->update(['created_at' => $createdAt]);
        }

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    private function patriarch(string $phone, Tribe $tribe, string $first = 'Patri'): User
    {
        return $this->give($this->member($phone, $tribe, $first, 'Arche', '2026-01-10 09:00:00'), 'patriarche', 'tribe', $tribe->id);
    }

    /** Reponses completes d'un rapport de tribu. */
    private function answers(array $override = []): array
    {
        return $override + [
            'rencontres_nombre' => 3,
            'themes' => 'La persévérance dans la prière',
            'dynamique' => 'bonne',
            'recadrement' => 'non',
            'activites' => ['evangelisation' => 'Deux sorties au centre-ville', 'appels' => 'Tous les membres ont été appelés'],
            'gems_fonctionnement' => 'oui',
            'sante_responsable' => 'Bonne, soutenue par le jeûne du mois',
            'sante_membres_niveau' => 'bon',
            'sante_membres' => 'Membres assidus, deux à accompagner de près',
            'projets' => 'Une veillée de prière le 17',
            'ames_autres' => ['Paul Invité'],
        ];
    }

    private function body(string $kind, int $scopeId, array $answers, bool $submit = false, string $period = '2026-09'): array
    {
        return ['kind' => $kind, 'scope_id' => $scopeId, 'period' => $period, 'answers' => $answers, 'submit' => $submit];
    }

    public function test_a_patriarch_fills_the_guided_report_and_the_souls_of_the_month_are_listed_automatically(): void
    {
        $patriarch = $this->patriarch('+14185551000', $this->juda);
        $ap = $this->give($this->member('+14185551001', null, 'Assistant'), 'assistant_pasteur', 'tribe', $this->juda->id);
        $otherAp = $this->give($this->member('+14185551002', null, 'Autre'), 'assistant_pasteur', 'tribe', $this->levi->id);
        $pastor = $this->give($this->member('+14185551003', null, 'Pasteur'), 'super_admin');
        $welcomed = $this->member('+14185551004', $this->juda, 'Lydie', 'Nouvelle', '2026-09-12 15:00:00');
        Profile::where('user_id', $welcomed->id)->update(['welcomed_at' => '2026-09-14 10:00:00']);
        $this->member('+14185551005', $this->juda, 'Marc', 'Arrivé', '2026-09-28 15:00:00');
        $this->member('+14185551006', $this->juda, 'Ancien', 'Membre', '2026-08-03 15:00:00');
        $this->member('+14185551007', $this->levi, 'Autre', 'Tribu', '2026-09-15 15:00:00');
        Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $this->juda->id]);

        Sanctum::actingAs($patriarch);
        $index = $this->getJson('/api/admin/leader-reports')->assertOk()->json();
        $this->assertSame('2026-09', $index['due_period']);
        $this->assertSame(['kind' => 'tribe', 'scope_id' => $this->juda->id, 'scope_name' => 'Juda'], array_slice($index['mine'][0], 0, 3));
        $this->assertSame('missing', $index['mine'][0]['periods'][0]['status']);

        // Questionnaire : etape GEMs pour une tribu, ames de septembre de SA tribu uniquement.
        $form = $this->getJson("/api/admin/leader-reports/form?kind=tribe&scope_id={$this->juda->id}&period=2026-09")->assertOk()->json();
        $this->assertSame(['rencontres', 'activites', 'gems', 'sante', 'projets', 'ames'], array_column($form['steps'], 'key'));
        $this->assertSame(['Lydie Nouvelle', 'Marc Arrivé'], array_column($form['context']['souls'], 'name'));
        $this->assertSame([true, false], array_column($form['context']['souls'], 'integrated'));
        $this->assertSame('GEM Béthel', $form['context']['gems'][0]['name']);

        // Brouillon : incomplet accepte, personne n'est prevenu.
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, ['rencontres_nombre' => 3, 'inconnu' => 'x']))
            ->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame(['rencontres_nombre' => 3], LeaderReport::sole()->answers);
        $this->assertSame(0, UserNotification::where('type', 'leader_report')->count());

        // Envoi : chaque question posee doit avoir une reponse.
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, ['rencontres_nombre' => 3], true))
            ->assertStatus(422)->assertJsonValidationErrors(['answers.themes', 'answers.activites', 'answers.gems_fonctionnement', 'answers.projets']);
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(['activites' => ['visites' => '']]), true))
            ->assertStatus(422)->assertJsonValidationErrors(['answers.activites']);
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(['recadrement' => 'oui']), true))
            ->assertStatus(422)->assertJsonValidationErrors(['answers.recadrement_detail']);

        $id = $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))
            ->assertOk()->assertJsonPath('status', 'submitted')->json('report_id');

        $report = LeaderReport::findOrFail($id);
        $this->assertSame(['Lydie Nouvelle', 'Marc Arrivé'], array_column($report->souls, 'name'));
        $this->assertSame('Juda', $report->scope_name);
        $this->assertSame(1, AuditLog::where('action', 'leader_report.submitted')->count());

        // L'AP de la tribu et le pasteur sont prevenus ; l'AP d'une autre tribu non.
        foreach ([$ap, $pastor] as $reader) {
            $n = UserNotification::where('user_id', $reader->id)->where('type', 'leader_report')->sole();
            $this->assertSame('Rapport mensuel reçu · Tribu Juda', $n->title);
            $this->assertStringContainsString('3 âme(s) gagnée(s)', $n->body);
            $this->assertSame('/admin/rapports-mensuels?rapport='.$id, $n->url);
        }
        $this->assertSame(0, UserNotification::where('user_id', $otherAp->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $patriarch->id)->where('type', 'leader_report')->count());

        // La liste est figee a l'envoi : un inscrit ajoute apres coup n'y entre pas.
        $this->member('+14185551008', $this->juda, 'Tardif', 'Ajouté', '2026-09-29 15:00:00');
        $shown = $this->getJson("/api/admin/leader-reports/{$id}")->assertOk()->json();
        $this->assertCount(2, $shown['souls']);
        $this->assertTrue($shown['can_edit']);
        $this->assertSame('Une veillée de prière le 17', $shown['answers']['projets']);
    }

    public function test_reports_are_only_visible_within_each_leaders_perimeter(): void
    {
        $patriarch = $this->patriarch('+14185552000', $this->juda);
        $otherPatriarch = $this->patriarch('+14185552001', $this->levi, 'Voisin');
        $ap = $this->give($this->member('+14185552002', null, 'Assistant'), 'assistant_pasteur', 'tribe', $this->juda->id);
        $otherAp = $this->give($this->member('+14185552003', null, 'Autre'), 'assistant_pasteur', 'tribe', $this->levi->id);
        $pa = $this->give($this->member('+14185552004', null, 'Pasteur', 'Assistant'), 'pasteur_assistant');
        $member = $this->member('+14185552005', $this->juda, 'Simple');
        $this->chorale->leaders()->attach($this->member('+14185552006', $this->levi, 'Chef', 'Chœur')->id);

        Sanctum::actingAs($patriarch);
        $id = $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))->assertOk()->json('report_id');
        // Un patriarche ne remplit que le rapport de sa tribu.
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->levi->id, $this->answers(), true))->assertForbidden();
        $this->getJson("/api/admin/leader-reports/form?kind=tribe&scope_id={$this->levi->id}&period=2026-09")->assertForbidden();

        foreach ([$otherPatriarch, $otherAp, $member] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/admin/leader-reports/{$id}")->assertForbidden();
            $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))->assertForbidden();
        }
        Sanctum::actingAs($member);
        $this->getJson('/api/admin/leader-reports')->assertForbidden();

        // L'AP voit sa tribu ; le PA voit toutes les tribus et les departements, envoyes ou non.
        Sanctum::actingAs($ap);
        $this->getJson("/api/admin/leader-reports/{$id}")->assertOk()->assertJsonPath('can_edit', false);
        $received = $this->getJson('/api/admin/leader-reports')->assertOk()->json('received');
        $this->assertSame([['tribe', 'Juda', 'submitted']], array_map(fn ($r) => [$r['kind'], $r['scope_name'], $r['status']], $received));
        $this->assertSame(['Patri Arche'], $received[0]['leaders']);

        Sanctum::actingAs($pa);
        $received = collect($this->getJson('/api/admin/leader-reports')->assertOk()->json('received'));
        $this->assertSame(['Juda' => 'submitted', 'Lévi' => 'missing', 'Chorale' => 'missing'], $received->pluck('status', 'scope_name')->all());
        $this->assertSame([], $this->getJson('/api/admin/leader-reports')->json('mine'));
        $this->assertSame(['Chef Chœur'], $received->firstWhere('scope_name', 'Chorale')['leaders']);
        // Un brouillon n'est pas lisible par les destinataires.
        Sanctum::actingAs($otherPatriarch);
        $draft = $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->levi->id, ['projets' => 'À préciser']))->assertOk()->json('report_id');
        Sanctum::actingAs($pa);
        $this->getJson("/api/admin/leader-reports/{$draft}")->assertForbidden();
    }

    public function test_a_department_leader_reports_for_the_department_without_the_gem_step(): void
    {
        $leader = $this->member('+14185553000', $this->juda, 'Chef', 'Chœur');
        $coLeader = $this->member('+14185553001', $this->levi, 'Adjoint', 'Chœur');
        $this->chorale->leaders()->attach([$leader->id, $coLeader->id]);
        $patriarch = $this->patriarch('+14185553002', $this->juda);
        $pastor = $this->give($this->member('+14185553003', null, 'Pasteur'), 'super_admin');
        $newcomer = $this->member('+14185553004', $this->levi, 'Ruth', 'Soprano');
        $old = $this->member('+14185553005', $this->levi, 'Ancien', 'Ténor');
        $this->chorale->members()->attach([$newcomer->profile->id, $old->profile->id]);
        DB::table('department_profile')->where('profile_id', $newcomer->profile->id)->update(['created_at' => '2026-09-20 12:00:00']);
        DB::table('department_profile')->where('profile_id', $old->profile->id)->update(['created_at' => '2026-06-01 12:00:00']);

        Sanctum::actingAs($leader->fresh());
        $form = $this->getJson("/api/admin/leader-reports/form?kind=department&scope_id={$this->chorale->id}&period=2026-09")->assertOk()->json();
        $this->assertSame(['rencontres', 'activites', 'sante', 'projets', 'ames'], array_column($form['steps'], 'key'));
        $this->assertSame(['Ruth Soprano'], array_column($form['context']['souls'], 'name'));

        // La question GEMs n'existe pas pour un departement : la reponse est ignoree.
        $id = $this->putJson('/api/admin/leader-reports', $this->body('department', $this->chorale->id, $this->answers(), true))->assertOk()->json('report_id');
        $this->assertArrayNotHasKey('gems_fonctionnement', LeaderReport::find($id)->answers);
        $this->assertSame('Rapport mensuel reçu · Département Chorale',
            UserNotification::where('user_id', $pastor->id)->where('type', 'leader_report')->sole()->title);

        // Les responsables du departement partagent le meme rapport ; un patriarche ne le lit pas.
        Sanctum::actingAs($coLeader->fresh());
        $this->getJson("/api/admin/leader-reports/{$id}")->assertOk()->assertJsonPath('can_edit', true);
        $this->assertSame('submitted', $this->getJson('/api/admin/leader-reports')->json('mine.0.periods.0.status'));
        Sanctum::actingAs($patriarch);
        $this->getJson("/api/admin/leader-reports/{$id}")->assertForbidden();
        $this->putJson('/api/admin/leader-reports', $this->body('department', $this->chorale->id, $this->answers(), true))->assertForbidden();
    }

    public function test_a_sent_report_can_be_corrected_until_the_end_of_the_following_month_then_is_frozen(): void
    {
        $patriarch = $this->patriarch('+14185554000', $this->juda);
        $pastor = $this->give($this->member('+14185554001', null, 'Pasteur'), 'super_admin');
        Sanctum::actingAs($patriarch);

        // Le mois en cours ne s'ouvre qu'a partir du 25 ; un mois plus ancien est ferme.
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true, '2026-10'))->assertStatus(423);
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true, '2026-08'))->assertStatus(423);

        $id = $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))->assertOk()->json('report_id');
        $sentAt = LeaderReport::find($id)->submitted_at;

        // Correction : meme rapport, date d'envoi conservee, modification tracee, pas de second avis.
        Carbon::setTestNow(Carbon::parse('2026-10-20 18:00:00'));
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(['projets' => 'Veillée reportée au 24']), true))
            ->assertOk()->assertJsonPath('report_id', $id);
        $this->assertSame(1, LeaderReport::count());
        $this->assertTrue($sentAt->equalTo(LeaderReport::find($id)->submitted_at));
        $this->assertSame(['projets' => 'Veillée reportée au 24'], AuditLog::where('action', 'leader_report.updated')->sole()->new_values);
        $this->assertSame(1, UserNotification::where('user_id', $pastor->id)->where('type', 'leader_report')->count());
        // Un rapport envoye ne redevient pas un brouillon.
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, ['projets' => 'x']))->assertStatus(409);

        // Fin octobre : le rapport d'octobre s'ouvre, celui de septembre reste modifiable jusqu'au 31.
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));
        $this->assertSame(['2026-09', '2026-10'], array_column($this->getJson('/api/admin/leader-reports')->json('mine.0.periods'), 'period'));
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))->assertStatus(423);
        $this->getJson("/api/admin/leader-reports/{$id}")->assertOk()->assertJsonPath('can_edit', false);
    }

    public function test_leaders_are_reminded_at_the_start_of_the_month_until_the_report_is_sent(): void
    {
        $patriarch = $this->patriarch('+14185555000', $this->juda);
        $leader = $this->member('+14185555001', $this->levi, 'Chef', 'Chœur');
        $this->chorale->leaders()->attach($leader->id);
        $reminders = fn (User $u) => UserNotification::where('user_id', $u->id)->where('type', 'leader_report')->orderBy('id')->get();

        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful(); // idempotent
        $first = $reminders($patriarch)->sole();
        $this->assertSame('Rapport de septembre 2026 à remplir', $first->title);
        $this->assertSame("/admin/rapports-mensuels?remplir=tribe:{$this->juda->id}&mois=2026-09", $first->url);
        $this->assertSame("/admin/rapports-mensuels?remplir=department:{$this->chorale->id}&mois=2026-09", $reminders($leader)->sole()->url);

        // Le patriarche envoie son rapport : seule la relance du departement part le 5.
        Sanctum::actingAs($patriarch);
        $this->putJson('/api/admin/leader-reports', $this->body('tribe', $this->juda->id, $this->answers(), true))->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->assertCount(1, $reminders($patriarch));
        $this->assertSame(['Rapport de septembre 2026 à remplir', 'Rappel : rapport de septembre 2026 à remplir'], $reminders($leader)->pluck('title')->all());

        // Apres le 10 : plus de relance.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->assertCount(2, $reminders($leader));
    }
}
