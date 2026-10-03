<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\LeaderReport;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Support\GemRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matrice inter-roles : chaque acces sensible (FISS, annuaire des responsables, fiche d'un Garde,
 * rapports hebdomadaires et mensuels) est essaye avec CHAQUE role, et doit etre accorde aux seuls
 * roles prevus. Le membre observe (Marie) est de la tribu Juda, du GEM Bethel et de la Chorale.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'membre', 'garde', 'respo_departement', 'accompagnateur', 'patriarche_juda', 'patriarche_levi',
        'ap_juda', 'ap_levi', 'pasteur_assistant', 'pasteur_resident', 'administrateur', 'communication',
    ];

    /** @var array<string, User> */
    private array $actors = [];

    private User $marie;

    private Tribe $juda;

    private Gem $bethel;

    private Department $chorale;

    private LeaderReport $tribeReport;

    private LeaderReport $departmentReport;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00')); // lundi : rapport mensuel de septembre, semaine du 28 septembre
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $this->bethel = Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $this->juda->id]);

        $this->marie = $this->member('+14185570000', $this->juda, 'Marie');
        Profile::where('user_id', $this->marie->id)->update(['gem_id' => $this->bethel->id]);
        $this->chorale->members()->attach($this->marie->profile->id);
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'meditation' => 15, 'priere' => 14, 'jeune' => 10,
            'comment' => 'Mois difficile', 'submitted_at' => now(), 'locked_at' => now()]);

        $this->actors = [
            'membre' => $this->member('+14185570001', $this->juda, 'Membre'),
            'garde' => $this->member('+14185570002', $this->juda, 'Garde'),
            'respo_departement' => $this->member('+14185570003', $levi, 'Respo'),
            'accompagnateur' => $this->give($this->member('+14185570004', $levi, 'Accompagnateur'), 'accompagnateur', 'member', $this->marie->id),
            'patriarche_juda' => $this->give($this->member('+14185570005', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id),
            'patriarche_levi' => $this->give($this->member('+14185570006', $levi, 'Patriarche'), 'patriarche', 'tribe', $levi->id),
            'ap_juda' => $this->give($this->member('+14185570007', null, 'AP'), 'assistant_pasteur', 'tribe', $this->juda->id),
            'ap_levi' => $this->give($this->member('+14185570008', null, 'AP'), 'assistant_pasteur', 'tribe', $levi->id),
            'pasteur_assistant' => $this->give($this->member('+14185570009', null, 'PA'), 'pasteur_assistant'),
            'pasteur_resident' => $this->give($this->member('+14185570010', null, 'PR'), 'super_admin'),
            'administrateur' => $this->give($this->member('+14185570011', $levi, 'Admin'), 'administrateur'),
            'communication' => $this->give($this->member('+14185570012', $levi, 'Com'), 'communication'),
        ];
        GemRules::appointLeader($this->bethel, $this->actors['garde']->id, $this->actors['pasteur_resident']->id);
        $this->chorale->leaders()->attach($this->actors['respo_departement']->id);

        $this->tribeReport = LeaderReport::create(['kind' => 'tribe', 'scope_id' => $this->juda->id, 'scope_name' => 'Juda', 'period' => '2026-09',
            'author_user_id' => $this->actors['patriarche_juda']->id, 'status' => 'submitted', 'answers' => ['projets' => 'Veillée'], 'souls' => [], 'submitted_at' => now()]);
        $this->departmentReport = LeaderReport::create(['kind' => 'department', 'scope_id' => $this->chorale->id, 'scope_name' => 'Chorale', 'period' => '2026-09',
            'author_user_id' => $this->actors['respo_departement']->id, 'status' => 'submitted', 'answers' => ['projets' => 'Concert'], 'souls' => [], 'submitted_at' => now()]);
        GemWeeklyReport::create(['gem_id' => $this->bethel->id, 'week_start' => '2026-09-21', 'author_user_id' => $this->actors['garde']->id, 'meeting_held' => true,
            'attendance' => [['user_id' => $this->marie->id, 'name' => 'Marie T', 'culte' => true, 'rencontre' => true]],
            'members_count' => 1, 'culte_count' => 1, 'meeting_count' => 1, 'submitted_at' => now()->subWeek()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'homme', 'is_completed' => true, 'tribe_id' => $tribe?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function as(string $role): void
    {
        Sanctum::actingAs($this->actors[$role]->fresh());
    }

    /**
     * L'acces est accorde (200) aux roles listes et refuse (403) a TOUS les autres.
     *
     * @param  callable(): TestResponse  $request
     * @param  array<int, string>  $allowed
     */
    private function assertOnly(string $what, callable $request, array $allowed): void
    {
        foreach (self::ALL as $role) {
            $this->as($role);
            $status = $request()->getStatusCode();
            $expected = in_array($role, $allowed, true) ? 200 : 403;
            $this->assertSame($expected, $status, "{$what} : le rôle « {$role} » a reçu {$status}, {$expected} attendu.");
        }
    }

    public function test_fiss_of_a_member_is_only_visible_to_the_leaders_of_the_tribe_and_the_pastors(): void
    {
        $fissReaders = ['patriarche_juda', 'ap_juda', 'pasteur_assistant', 'pasteur_resident'];
        $this->assertOnly("FISS d'un membre", fn () => $this->getJson("/api/admin/members/{$this->marie->id}/fiss"), $fissReaders);
        // Historique des FISS : il faut en plus la validation des FISS ou l'audit.
        $this->assertOnly("Historique des FISS d'un membre", fn () => $this->getJson("/api/admin/members/{$this->marie->id}/fiss-history"), $fissReaders);

        // Le Garde, le responsable de departement et l'accompagnateur voient la fiche du membre, SANS ses FISS.
        $this->assertOnly("Fiche d'un membre", fn () => $this->getJson("/api/admin/members/{$this->marie->id}"),
            ['garde', 'respo_departement', 'accompagnateur', 'patriarche_juda', 'ap_juda', 'pasteur_assistant', 'pasteur_resident', 'administrateur']);
        foreach (['garde', 'respo_departement', 'accompagnateur', 'administrateur'] as $role) {
            $this->as($role);
            $this->assertFalse($this->getJson("/api/admin/members/{$this->marie->id}")->json('can_view_fiss'), $role);
            $row = collect($this->getJson('/api/admin/members')->assertOk()->json('members'))->firstWhere('user_id', $this->marie->id);
            $this->assertNull($row['fiss_current'], "liste des membres : {$role}");
        }
        foreach ($fissReaders as $role) {
            $this->as($role);
            $this->assertTrue($this->getJson("/api/admin/members/{$this->marie->id}")->json('can_view_fiss'), $role);
            $row = collect($this->getJson('/api/admin/members')->assertOk()->json('members'))->firstWhere('user_id', $this->marie->id);
            $this->assertTrue($row['fiss_current'], "liste des membres : {$role}");
        }

        // Tableau de bord et filtre « FISS manquante » : rien pour ceux qui ne voient pas les fiches.
        foreach (['garde', 'respo_departement', 'accompagnateur'] as $role) {
            $this->as($role);
            $stats = $this->getJson('/api/admin/stats')->assertOk()->json();
            $this->assertSame([null, null], [$stats['fiss_filled'], $stats['fiss_rate']], $role);
            $this->assertSame([], $this->getJson('/api/admin/members?fiss_missing=1')->assertOk()->json('members'), $role);
        }
        $this->as('patriarche_juda');
        $this->assertSame(1, $this->getJson('/api/admin/stats')->assertOk()->json('fiss_filled'));
        $missing = array_column($this->getJson('/api/admin/members?fiss_missing=1')->assertOk()->json('members'), 'full_name');
        $this->assertContains('Garde T', $missing);
        $this->assertNotContains('Marie T', $missing);

        // Chaque membre garde l'acces a sa propre fiche.
        Sanctum::actingAs($this->marie->fresh());
        $this->assertSame('Mois difficile', $this->getJson('/api/me/fiss')->assertOk()->json('current.comment'));
    }

    public function test_the_gardes_page_hides_the_fiss_of_the_gem_from_the_garde(): void
    {
        $readers = ['garde', 'patriarche_juda', 'ap_juda', 'pasteur_assistant', 'pasteur_resident', 'administrateur'];
        $this->assertOnly("Fiche d'un Garde", fn () => $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}"), $readers);

        foreach (['garde', 'administrateur'] as $role) {
            $this->as($role);
            $page = $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->json();
            $this->assertSame([null, null], [$page['indicators']['fiss_filled'], $page['indicators']['fiss_previous']], $role);
            $this->assertSame([null], array_values(array_unique(array_column($page['members'], 'fiss_current'))), $role);
        }
        foreach (['patriarche_juda', 'ap_juda', 'pasteur_assistant', 'pasteur_resident'] as $role) {
            $this->as($role);
            $page = $this->getJson("/api/admin/leaders/gems/{$this->bethel->id}")->json();
            $this->assertSame(1, $page['indicators']['fiss_filled'], $role);
            $this->assertTrue(collect($page['members'])->firstWhere('user_id', $this->marie->id)['fiss_current'], $role);
        }
    }

    public function test_leaders_directory_and_weekly_reports_follow_each_role(): void
    {
        $this->assertOnly('Annuaire des responsables', fn () => $this->getJson('/api/admin/leaders'),
            ['patriarche_juda', 'patriarche_levi', 'ap_juda', 'ap_levi', 'pasteur_assistant', 'pasteur_resident', 'administrateur']);
        // Toute l'eglise pour l'autorite ; sa tribu seulement pour un patriarche ou un AP.
        foreach (['pasteur_assistant' => ['church', ['GEM Béthel']], 'patriarche_juda' => ['tribes', ['GEM Béthel']], 'ap_levi' => ['tribes', []], 'patriarche_levi' => ['tribes', []]] as $role => [$scope, $gems]) {
            $this->as($role);
            $page = $this->getJson('/api/admin/leaders')->json();
            $this->assertSame([$scope, $gems], [$page['scope'], array_column($page['gardes'], 'gem')], $role);
        }

        $this->assertOnly('Rapport hebdomadaire (écran du Garde)', fn () => $this->getJson('/api/admin/gem-reports'), ['garde']);
        $body = ['week_start' => '2026-09-28', 'meeting_held' => true, 'attendance' => [['user_id' => $this->marie->id, 'culte' => true, 'rencontre' => true]]];
        $this->assertOnly('Envoi du rapport hebdomadaire', fn () => $this->putJson("/api/admin/gem-reports/{$this->bethel->id}", $body), ['garde']);
        $this->assertSame(2, GemWeeklyReport::count()); // celui de la semaine precedente + celui que le Garde vient d'envoyer
    }

    public function test_monthly_reports_follow_each_role(): void
    {
        $this->assertOnly('Écran des rapports mensuels', fn () => $this->getJson('/api/admin/leader-reports'),
            ['respo_departement', 'patriarche_juda', 'patriarche_levi', 'ap_juda', 'ap_levi', 'pasteur_assistant', 'pasteur_resident']);

        $this->assertOnly('Questionnaire du rapport de la tribu Juda',
            fn () => $this->getJson("/api/admin/leader-reports/form?kind=tribe&scope_id={$this->juda->id}&period=2026-09"), ['patriarche_juda']);
        $this->assertOnly('Questionnaire du rapport de la Chorale',
            fn () => $this->getJson("/api/admin/leader-reports/form?kind=department&scope_id={$this->chorale->id}&period=2026-09"), ['respo_departement']);

        $tribeReaders = ['patriarche_juda', 'ap_juda', 'pasteur_assistant', 'pasteur_resident'];
        $this->assertOnly('Lecture du rapport de la tribu Juda', fn () => $this->getJson("/api/admin/leader-reports/{$this->tribeReport->id}"), $tribeReaders);
        $this->assertOnly('PDF du rapport de la tribu Juda', fn () => $this->postJson("/api/admin/leader-reports/{$this->tribeReport->id}/exported"), $tribeReaders);
        $departmentReaders = ['respo_departement', 'pasteur_assistant', 'pasteur_resident'];
        $this->assertOnly('Lecture du rapport de la Chorale', fn () => $this->getJson("/api/admin/leader-reports/{$this->departmentReport->id}"), $departmentReaders);
        $this->assertOnly('PDF du rapport de la Chorale', fn () => $this->postJson("/api/admin/leader-reports/{$this->departmentReport->id}/exported"), $departmentReaders);

        // Envoi : seul le patriarche de la tribu ; meme un pasteur ne remplit pas a sa place.
        $answers = ['rencontres_nombre' => 0, 'rencontres_motif' => 'Vacances', 'recadrement' => 'non', 'activites' => ['aucune' => 'Mois de repos'],
            'gems_fonctionnement' => 'oui', 'sante_responsable' => 'Bon', 'sante_membres_niveau' => 'bon', 'sante_membres' => 'Stable', 'projets' => 'Reprise'];
        $this->assertOnly('Envoi du rapport de la tribu Juda', fn () => $this->putJson('/api/admin/leader-reports',
            ['kind' => 'tribe', 'scope_id' => $this->juda->id, 'period' => '2026-09', 'answers' => $answers, 'submit' => true]), ['patriarche_juda']);

        // Ce que chacun recoit : l'AP de Levi ne voit pas la tribu Juda, le patriarche ne voit pas les departements.
        foreach ([
            'ap_juda' => ['Juda'], 'ap_levi' => ['Lévi'], 'patriarche_juda' => ['Juda'],
            'pasteur_assistant' => ['Juda', 'Lévi', 'Chorale'], 'respo_departement' => [],
        ] as $role => $expected) {
            $this->as($role);
            $this->assertSame($expected, array_column($this->getJson('/api/admin/leader-reports?period=2026-09')->json('received'), 'scope_name'), $role);
        }
    }
}
