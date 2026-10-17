<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Gem;
use App\Models\MeditationSheet;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\Monitoring\UserAdmin;
use App\Support\GemRules;
use App\Support\PermissionCatalog;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Meditation : fiches personnelles du membre (cinq rubriques), datees par le serveur. Lues par le
 * membre, le patriarche de SA tribu, le Pasteur Resident et les Pasteurs Assistants ; refusees a tous
 * les autres (AP, Garde, responsable de departement, accompagnateur, administrateur, compte de gestion...).
 * Le membre observe (Marie) est de la tribu Juda, du GEM Bethel et de la Chorale.
 */
class MeditationSheetsTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGER_PHONE = '+22893471703';

    private const ALL = [
        'membre', 'garde', 'respo_departement', 'accompagnateur', 'patriarche_juda', 'patriarche_levi',
        'ap_juda', 'ap_levi', 'pasteur_assistant', 'pasteur_resident', 'administrateur', 'communication',
    ];

    private const READERS = ['patriarche_juda', 'pasteur_assistant', 'pasteur_resident'];

    /** @var array<string, User> */
    private array $actors = [];

    private User $marie;

    private MeditationSheet $older;

    private MeditationSheet $newer;

    private MeditationSheet $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 08:30:00'));
        Http::preventStrayRequests();
        config(['platform.manager.phone' => self::MANAGER_PHONE]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $bethel = Gem::create(['name' => 'GEM Béthel', 'tribe_id' => $juda->id]);

        $this->marie = $this->member('+14185580000', $juda, 'Marie');
        Profile::where('user_id', $this->marie->id)->update(['gem_id' => $bethel->id]);
        $chorale->members()->attach($this->marie->profile->id);

        $this->actors = [
            'membre' => $this->member('+14185580001', $juda, 'Membre'),
            'garde' => $this->member('+14185580002', $juda, 'Garde'),
            'respo_departement' => $this->member('+14185580003', $levi, 'Respo'),
            'accompagnateur' => $this->give($this->member('+14185580004', $levi, 'Accompagnateur'), 'accompagnateur', 'member', $this->marie->id),
            'patriarche_juda' => $this->give($this->member('+14185580005', $juda, 'Patriarche'), 'patriarche', 'tribe', $juda->id),
            'patriarche_levi' => $this->give($this->member('+14185580006', $levi, 'Patriarche'), 'patriarche', 'tribe', $levi->id),
            'ap_juda' => $this->give($this->member('+14185580007', null, 'AP'), 'assistant_pasteur', 'tribe', $juda->id),
            'ap_levi' => $this->give($this->member('+14185580008', null, 'AP'), 'assistant_pasteur', 'tribe', $levi->id),
            'pasteur_assistant' => $this->give($this->member('+14185580009', null, 'PA'), 'pasteur_assistant'),
            'pasteur_resident' => $this->give($this->member('+14185580010', null, 'PR'), 'super_admin'),
            'administrateur' => $this->give($this->member('+14185580011', $levi, 'Admin'), 'administrateur'),
            'communication' => $this->give($this->member('+14185580012', $levi, 'Com'), 'communication'),
        ];
        GemRules::appointLeader($bethel, $this->actors['garde']->id, $this->actors['pasteur_resident']->id);
        $chorale->leaders()->attach($this->actors['respo_departement']->id);

        $this->older = $this->sheet($this->marie, '2026-10-01 07:00:00', 'Psaume 23');
        $this->newer = $this->sheet($this->marie, '2026-10-14 06:45:00', 'Jean 15');
        $this->foreign = $this->sheet($this->actors['membre'], '2026-10-15 06:00:00', 'Romains 8');
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

    private function sheet(User $user, string $at, string $verse): MeditationSheet
    {
        return MeditationSheet::create(['user_id' => $user->id, 'verse' => $verse, 'observation' => 'Observation', 'rhema' => 'Rhéma',
            'action' => 'Action', 'prayer' => 'Prière', 'submitted_at' => Carbon::parse($at)]);
    }

    /** @return array<string, string> */
    private function filled(): array
    {
        return [
            'verse' => "Josué 1.8 : Que ce livre de la loi ne s'éloigne point de ta bouche ;\nmédite-le jour et nuit.",
            'observation' => 'La méditation est quotidienne.',
            'rhema' => 'Méditer jour et nuit.',
            'action' => 'Lire Josué 1 ce soir.',
            'prayer' => 'Seigneur, aide-moi à méditer ta Parole.',
        ];
    }

    public function test_member_saves_a_sheet_attached_to_himself_and_dated_by_the_server(): void
    {
        Sanctum::actingAs($this->marie->fresh());

        // Un identifiant ou une date envoyes par le client sont ignores.
        $response = $this->postJson('/api/me/meditations', $this->filled() + [
            'user_id' => $this->actors['membre']->id, 'submitted_at' => '2020-01-01 00:00:00',
        ])->assertCreated()->assertJsonPath('message', 'Fiche de méditation enregistrée.');

        $sheet = MeditationSheet::findOrFail($response->json('sheet.id'));
        $this->assertSame($this->marie->id, (int) $sheet->user_id);
        $this->assertSame('2026-10-16 08:30:00', $sheet->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->filled()['verse'], $sheet->verse);
        $this->assertSame('Seigneur, aide-moi à méditer ta Parole.', $sheet->prayer);
        $response->assertJsonPath('sheet.submitted_at', Carbon::parse('2026-10-16 08:30:00')->toIso8601String())
            ->assertJsonPath('sheet.rhema', 'Méditer jour et nuit.');

        // La fiche arrive en tete de l'historique, avec sa date.
        $this->getJson('/api/me/meditations')->assertOk()
            ->assertJsonPath('sheets.0.id', $sheet->id)
            ->assertJsonPath('sheets.0.submitted_at', Carbon::parse('2026-10-16 08:30:00')->toIso8601String());
        $this->assertSame(0, MeditationSheet::where('user_id', $this->actors['membre']->id)->where('id', '!=', $this->foreign->id)->count());
    }

    public function test_member_sees_only_his_own_sheets_newest_first(): void
    {
        Sanctum::actingAs($this->marie->fresh());

        $list = $this->getJson('/api/me/meditations')->assertOk()->assertJsonCount(2, 'sheets');
        $this->assertSame([$this->newer->id, $this->older->id], array_column($list->json('sheets'), 'id'));
        // L'historique ne donne que les dates : le contenu se lit en ouvrant la fiche.
        $list->assertJsonMissingPath('sheets.0.verse');

        $this->getJson("/api/me/meditations/{$this->newer->id}")->assertOk()
            ->assertJsonPath('sheet.verse', 'Jean 15')
            ->assertJsonPath('sheet.observation', 'Observation')
            ->assertJsonPath('sheet.rhema', 'Rhéma')
            ->assertJsonPath('sheet.action', 'Action')
            ->assertJsonPath('sheet.prayer', 'Prière');
        // La fiche d'un autre membre : introuvable, sans rien de son contenu.
        $other = $this->getJson("/api/me/meditations/{$this->foreign->id}")->assertNotFound();
        $this->assertStringNotContainsString('Romains', $other->getContent());

        Sanctum::actingAs($this->actors['communication']->fresh());
        $this->getJson('/api/me/meditations')->assertOk()->assertJsonCount(0, 'sheets');
    }

    public function test_every_field_is_required_and_bounded(): void
    {
        Sanctum::actingAs($this->marie->fresh());
        $before = MeditationSheet::count();

        $this->postJson('/api/me/meditations', [])->assertStatus(422)
            ->assertJsonValidationErrors(['verse', 'observation', 'rhema', 'action', 'prayer'])
            ->assertJsonPath('errors.rhema.0', 'Remplissez la rubrique « Rhéma reçu » pour valider votre fiche.');
        $this->postJson('/api/me/meditations', ['prayer' => "   \n  "] + $this->filled())->assertStatus(422)
            ->assertJsonValidationErrors(['prayer']);
        $this->postJson('/api/me/meditations', ['verse' => str_repeat('a', 5001)] + $this->filled())->assertStatus(422)
            ->assertJsonPath('errors.verse.0', 'La rubrique « Verset à méditer » ne peut pas dépasser 5000 caractères.');
        $this->postJson('/api/me/meditations', ['action' => ['liste']] + $this->filled())->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
        $this->assertSame($before, MeditationSheet::count());

        // Limite comprise : enregistree.
        $this->postJson('/api/me/meditations', ['verse' => str_repeat('é', 5000)] + $this->filled())->assertCreated();
    }

    public function test_only_the_tribe_patriarch_and_the_pastors_read_a_members_sheets(): void
    {
        foreach (self::ALL as $role) {
            Sanctum::actingAs($this->actors[$role]->fresh());
            $allowed = in_array($role, self::READERS, true);

            $list = $this->getJson("/api/admin/members/{$this->marie->id}/meditations");
            $this->assertSame($allowed ? 200 : 403, $list->getStatusCode(), "Liste : le rôle « {$role} » a reçu {$list->getStatusCode()}.");
            $one = $this->getJson("/api/admin/members/{$this->marie->id}/meditations/{$this->newer->id}");
            $this->assertSame($allowed ? 200 : 403, $one->getStatusCode(), "Fiche : le rôle « {$role} » a reçu {$one->getStatusCode()}.");
            if ($allowed) {
                $this->assertSame([$this->newer->id, $this->older->id], array_column($list->json('sheets'), 'id'));
                $one->assertJsonPath('sheet.verse', 'Jean 15')->assertJsonPath('sheet.prayer', 'Prière');
            } else {
                $this->assertStringNotContainsString('Jean 15', $one->getContent());
            }

            // Fiche du membre (gestion) : le bloc des meditations n'est annonce qu'aux lecteurs prevus.
            $detail = $this->getJson("/api/admin/members/{$this->marie->id}");
            if ($detail->getStatusCode() === 200) {
                $this->assertSame($allowed, $detail->json('can_view_meditations'), "Fiche du membre : can_view_meditations pour « {$role} ».");
            } else {
                $this->assertFalse($allowed);
            }
        }
    }

    public function test_a_patriarch_reads_only_the_members_of_his_tribe(): void
    {
        $levi = Tribe::where('slug', 'levi')->firstOrFail();
        $leviMember = $this->member('+14185580020', $levi, 'Ruben');
        $leviSheet = $this->sheet($leviMember, '2026-10-10 07:00:00', 'Lévitique 19');

        Sanctum::actingAs($this->actors['patriarche_juda']->fresh());
        $this->getJson("/api/admin/members/{$leviMember->id}/meditations")->assertForbidden();
        $this->getJson("/api/admin/members/{$leviMember->id}/meditations/{$leviSheet->id}")->assertForbidden();

        Sanctum::actingAs($this->actors['patriarche_levi']->fresh());
        $this->getJson("/api/admin/members/{$leviMember->id}/meditations")->assertOk()->assertJsonPath('sheets.0.id', $leviSheet->id);

        // Marie change de tribu : son ancien patriarche ne la lit plus, le nouveau oui.
        Profile::where('user_id', $this->marie->id)->update(['tribe_id' => $levi->id, 'gem_id' => null]);
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertOk()->assertJsonCount(2, 'sheets');
        Sanctum::actingAs($this->actors['patriarche_juda']->fresh());
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertForbidden();
    }

    public function test_a_sheet_is_only_read_under_its_own_member(): void
    {
        Sanctum::actingAs($this->actors['pasteur_resident']->fresh());
        // Fiche d'un autre membre demandee sous la fiche de Marie : introuvable.
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations/{$this->foreign->id}")->assertNotFound();
        $this->getJson("/api/admin/members/{$this->actors['membre']->id}/meditations/{$this->foreign->id}")->assertOk()
            ->assertJsonPath('sheet.verse', 'Romains 8');
    }

    public function test_the_management_account_and_guests_have_no_access(): void
    {
        $this->assertTrue(PermissionCatalog::isConfidential('meditations.view'));
        $this->assertFalse(PlatformManager::allows('meditations.view'));

        $this->getJson('/api/me/meditations')->assertUnauthorized();
        $this->postJson('/api/me/meditations', $this->filled())->assertUnauthorized();
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertUnauthorized();

        [$manager] = PlatformManager::activate(self::MANAGER_PHONE);
        Sanctum::actingAs($manager->fresh());
        $this->getJson('/api/me/meditations')->assertForbidden();
        $this->postJson('/api/me/meditations', $this->filled())->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/meditations/{$this->newer->id}")->assertForbidden();
        $this->assertSame(0, MeditationSheet::where('user_id', $manager->id)->count());
    }

    public function test_sheets_disappear_with_the_account(): void
    {
        $impact = collect(UserAdmin::impact($this->marie)['deleted'])->firstWhere('label', 'Fiches de méditation');
        $this->assertSame(2, $impact['count'] ?? null);

        $this->marie->delete();
        $this->assertSame(0, MeditationSheet::where('user_id', $this->marie->id)->count());
        $this->assertTrue(MeditationSheet::whereKey($this->foreign->id)->exists());
    }
}
