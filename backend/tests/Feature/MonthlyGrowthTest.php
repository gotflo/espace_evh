<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\FamilyLink;
use App\Models\MonthlyResolution;
use App\Models\Profile;
use App\Models\ResolutionResource;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ProfileCompletion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Grande mise a jour d'octobre 2026 : horaires des cultes modifiables, date de naissance des
 * enfants, tribu obligatoire a l'inscription, FISS « Vie interieure », Mes RM, Mon devoir (DS).
 */
class MonthlyGrowthTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pastor;

    private User $patriarch;

    private User $otherPatriarch;

    private User $garde;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 11:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pastor = $this->give($this->member('+14185580001', null, 'Pasteur'), 'super_admin');
        $this->patriarch = $this->give($this->member('+14185580002', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $this->otherPatriarch = $this->give($this->member('+14185580003', $this->levi, 'Autre'), 'patriarche', 'tribe', $this->levi->id);
        $this->garde = $this->give($this->member('+14185580004', $this->juda, 'Garde'), 'garde', 'gem', 999);
        $this->marie = $this->member('+14185580005', $this->juda, 'Marie');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first, bool $completed = true): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme',
            'is_completed' => $completed, 'tribe_id' => $tribe?->id, 'matricule' => $completed ? 'VHC'.substr($phone, -4) : null]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    // ---------------------------------------------------------------- 1. Horaires des cultes

    public function test_sunday_starts_at_8_45_and_wednesday_has_no_end_time(): void
    {
        $sunday = Event::where('title', "Temps de prière & d'intercession")->firstOrFail();
        $this->assertSame('08:45', $sunday->starts_at->format('H:i'));
        $this->assertSame('09:30', $sunday->ends_at->format('H:i'));
        $this->assertNull(Event::where('title', "Mercredi de l'intercession")->firstOrFail()->ends_at);
    }

    public function test_the_pastor_edits_the_service_schedule_and_members_cannot(): void
    {
        $this->as($this->marie);
        $this->getJson('/api/admin/service-schedule')->assertForbidden();

        $this->as($this->pastor);
        $services = collect($this->getJson('/api/admin/service-schedule')->assertOk()->json('services'));
        $this->assertCount(6, $services);
        $this->assertSame([3, 6, 7, 7, 7, 7], $services->pluck('day')->all()); // mercredi, samedi, dimanche
        $wednesday = $services->firstWhere('day', 3);
        $this->assertNull($wednesday['end']);

        // Mercredi deplace au jeudi 19 h 00, sans fin ; l'eglise est prevenue.
        $event = Event::find($wednesday['id']);
        $originalWeek = $event->starts_at->copy()->startOfWeek();
        $this->putJson('/api/admin/service-schedule/'.$wednesday['id'], [
            'title' => "Jeudi de l'intercession", 'day' => 4, 'start' => '19:00', 'end' => null, 'notify' => true,
        ])->assertOk()->assertJsonPath('service.day', 4)->assertJsonPath('service.start', '19:00')->assertJsonPath('service.end', null);
        $event->refresh();
        $this->assertTrue($event->starts_at->copy()->startOfWeek()->equalTo($originalWeek)); // historique conserve
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('title', "Horaire modifié : Jeudi de l'intercession")->count());
        $this->assertSame(1, AuditLog::where('action', 'service.updated')->count());

        // Fin avant le debut : refuse.
        $this->putJson('/api/admin/service-schedule/'.$wednesday['id'], ['title' => 'Culte', 'day' => 4, 'start' => '19:00', 'end' => '18:00'])
            ->assertStatus(422)->assertJsonValidationErrors('end');

        // Ajout puis retrait d'un rendez-vous.
        $id = $this->postJson('/api/admin/service-schedule', ['title' => 'Prière du vendredi', 'day' => 5, 'start' => '06:00', 'end' => '07:00'])
            ->assertCreated()->json('service.id');
        $created = Event::with('scopes')->find($id);
        $this->assertTrue($created->remind_all && $created->isForWholeChurch() && $created->recurrence === 'weekly');
        $this->assertSame(5, $created->starts_at->dayOfWeekIso);
        $this->deleteJson('/api/admin/service-schedule/'.$id)->assertOk();
        $this->assertNull(Event::find($id));

        // Un evenement ordinaire n'est pas un horaire de culte.
        $other = $this->makeEvent(['title' => 'Sortie', 'category' => 'sortie', 'starts_at' => now()->addDay(), 'recurrence' => 'none']);
        $this->putJson('/api/admin/service-schedule/'.$other->id, ['title' => 'Culte', 'day' => 1, 'start' => '10:00'])->assertNotFound();
    }

    // ---------------------------------------------------------------- 2. Enfants : date de naissance complete

    public function test_children_are_saved_with_their_full_birth_date(): void
    {
        $this->as($this->marie);
        $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [
            ['name' => 'Paul', 'birth_date' => '2014-05-03'],
            ['name' => 'Léa', 'birth_date' => '2030-01-01'],
        ]])->assertStatus(422)->assertJsonValidationErrors('children.1.birth_date');

        $res = $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [
            ['name' => 'Paul', 'birth_date' => '2014-05-03'],
            ['name' => 'Léa', 'birth_date' => '2019-12-24'],
        ]])->assertOk();
        $this->assertSame(['2014-05-03', '2019-12-24'], collect($res->json('family.children'))->pluck('birth_date')->all());
        $this->assertSame(2014, FamilyLink::where('relative_name', 'Paul')->value('birth_year'));

        // Ancienne version de l'application (annee seule) : la date connue n'est pas perdue.
        $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [
            ['name' => 'Paul', 'birth_year' => 2014], ['name' => 'Léa', 'birth_year' => 2019],
        ]])->assertOk();
        $this->assertSame('2014-05-03', FamilyLink::where('relative_name', 'Paul')->first()->birth_date->toDateString());

        // Le responsable voit la date complete.
        $this->as($this->patriarch);
        $children = $this->getJson('/api/admin/members/'.$this->marie->id)->assertOk()->json('family.children');
        $this->assertSame('2019-12-24', $children[1]['birth_date']);
    }

    public function test_a_child_with_only_a_birth_year_keeps_the_profile_incomplete(): void
    {
        $profile = $this->marie->profile;
        $profile->forceFill(['has_children' => true, 'children_count' => 1, 'marital_status' => 'celibataire', 'birth_day' => 2, 'birth_month' => 3])->save();
        FamilyLink::create(['user_id' => $this->marie->id, 'relation' => 'child', 'relative_name' => 'Paul', 'birth_year' => 2014, 'status' => 'confirmed']);
        $missing = collect(ProfileCompletion::for($profile->fresh())['missing'])->pluck('label');
        $this->assertContains('Nom et date de naissance des enfants', $missing);

        FamilyLink::where('user_id', $this->marie->id)->update(['birth_date' => '2014-05-03']);
        $this->assertSame(100, ProfileCompletion::for($profile->fresh())['percent']);
        // Calcul groupe (tache quotidienne) : meme resultat.
        ProfileCompletion::refreshMany(Profile::whereKey($profile->id)->get());
        $this->assertSame(100, $profile->fresh()->completion);
    }

    // ---------------------------------------------------------------- 3. Tribu obligatoire a l'inscription

    public function test_the_tribe_is_required_to_finish_registration(): void
    {
        $newcomer = User::create(['phone' => '+14185580099']);
        Profile::create(['user_id' => $newcomer->id]);
        $newcomer->roles()->attach(Role::where('key', 'fidele')->value('id'));
        $this->as($newcomer);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('profile_completed', false)
            ->assertJsonPath('registration.tribe_required', true)->assertJsonPath('registration.reopened', false);
        $this->postJson('/api/profile', ['first_name' => 'Jean', 'last_name' => 'Neuf', 'gender' => 'homme'])
            ->assertStatus(422)->assertJsonValidationErrors('tribe_id');
        $this->assertFalse((bool) $newcomer->profile->fresh()->is_completed);

        // Inscription inachevee : invisible pour les responsables (liste, fiche, statistiques).
        $this->as($this->pastor);
        $this->assertNotContains($newcomer->id, collect($this->getJson('/api/admin/members')->json('members'))->pluck('id')->all());
        $this->getJson('/api/admin/members/'.$newcomer->id)->assertNotFound();
        $total = $this->getJson('/api/admin/stats')->json('total');

        $this->as($newcomer);
        $this->postJson('/api/profile', ['first_name' => 'Jean', 'last_name' => 'Neuf', 'gender' => 'homme', 'tribe_id' => $this->juda->id])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('profile_completed', true);
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Nouveau membre : Jean Neuf')->count());

        $this->as($this->pastor);
        $this->assertSame($total + 1, $this->getJson('/api/admin/stats')->json('total'));
    }

    public function test_pastors_may_stay_without_a_tribe(): void
    {
        $pa = $this->give($this->member('+14185580098', null, 'PA', false), 'pasteur_assistant');
        $this->as($pa);
        $this->getJson('/api/me')->assertJsonPath('registration.tribe_required', false);
        $this->postJson('/api/profile', ['first_name' => 'Paul', 'last_name' => 'Pasteur', 'gender' => 'homme'])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('profile_completed', true);
    }

    public function test_members_registered_without_a_tribe_are_asked_to_choose_one(): void
    {
        $old = $this->member('+14185580097', null, 'Ancien');
        $pa = $this->give($this->member('+14185580096', null, 'PA'), 'pasteur_assistant');

        $migration = require database_path('migrations/2026_10_07_100003_require_tribe_at_registration.php');
        $migration->up();

        $this->assertFalse((bool) $old->profile->fresh()->is_completed);
        $this->assertTrue((bool) $pa->profile->fresh()->is_completed);
        $this->assertTrue((bool) $this->marie->profile->fresh()->is_completed);

        $this->as($old);
        $this->getJson('/api/me')->assertJsonPath('profile_completed', false)->assertJsonPath('registration.reopened', true);
        $this->postJson('/api/profile', ['first_name' => 'Ancien', 'last_name' => 'Test', 'gender' => 'femme', 'tribe_id' => $this->juda->id])->assertOk();
        // Deja inscrit : pas de « Nouveau membre », mais la tribu est prevenue.
        $this->assertSame(0, UserNotification::where('title', 'like', 'Nouveau membre%')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Ancien Test a rejoint la tribu Juda')->count());

        // Retour arriere : les comptes rouverts (sans tribu) redeviennent inscrits.
        $other = $this->member('+14185580095', null, 'Autre');
        $migration->up();
        $this->assertFalse((bool) $other->profile->fresh()->is_completed);
        $migration->down();
        $this->assertTrue((bool) $other->profile->fresh()->is_completed);
    }

    // ---------------------------------------------------------------- 11. FISS : vie interieure

    public function test_fiss_inner_life_answers_are_saved_and_confidential(): void
    {
        $this->as($this->marie);
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('inner_life.companions.reference', '1 Corinthiens 15:33');
        $this->postJson('/api/me/fiss', [
            'meditation' => 12, 'priere' => 14, 'jeune' => 10, 'sanctification_corps' => 'bien', 'sanctification_ame' => 'moyen',
            'sanctification_esprit' => 'bien', 'situation_financiere' => 12, 'situation_familiale' => 15,
            'addiction' => 'Les écrans le soir', 'addiction_victory' => '', 'discouragement' => 'Parfois',
        ])->assertStatus(422)->assertJsonValidationErrors(['addiction_victory', 'companions', 'inner_wound', 'difficult_situations']);
        // La vie interieure fait partie du remplissage : chaque question demande une reponse.
        $this->postJson('/api/me/fiss', [
            'meditation' => 12, 'priere' => 14, 'jeune' => 10, 'sanctification_corps' => 'bien', 'sanctification_ame' => 'moyen',
            'sanctification_esprit' => 'bien', 'situation_financiere' => 12, 'situation_familiale' => 15,
            'addiction' => ' Les écrans le soir ', 'addiction_victory' => 'Non', 'companions' => 'Oui, de bons amis',
            'inner_wound' => 'Aucune', 'difficult_situations' => 'Aucune', 'discouragement' => 'Parfois',
        ])->assertCreated()->assertJsonPath('current.addiction', 'Les écrans le soir')->assertJsonPath('current.addiction_victory', 'Non');

        $this->as($this->patriarch);
        $this->getJson('/api/admin/members/'.$this->marie->id.'/fiss')->assertOk()->assertJsonPath('forms.0.discouragement', 'Parfois');
        $this->as($this->garde);
        $this->getJson('/api/admin/members/'.$this->marie->id.'/fiss')->assertForbidden();
        $this->as($this->otherPatriarch);
        $this->getJson('/api/admin/members/'.$this->marie->id.'/fiss')->assertForbidden();
    }

    // ---------------------------------------------------------------- 5-8. Mes RM

    public function test_member_takes_monthly_resolutions_renews_and_reviews(): void
    {
        MonthlyResolution::create(['user_id' => $this->marie->id, 'period' => '2026-09', 'meditation' => 'Un psaume chaque matin', 'jeune' => 'Le mercredi']);
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'meditation' => 8, 'priere' => 16,
            'sanctification_corps' => 'bien', 'sanctification_ame' => 'mal', 'submitted_at' => now(), 'locked_at' => now()]);
        ResolutionResource::create(['theme' => 'priere', 'title' => 'Prier sans cesse', 'author' => 'Pst Abraham', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ']);

        $this->as($this->marie);
        $res = $this->getJson('/api/me/resolutions')->assertOk()
            ->assertJsonPath('verse.reference', 'Job 22:28')
            ->assertJsonPath('current', null)
            ->assertJsonPath('review_needed', true)
            ->assertJsonPath('hints.items.meditation.level', 'faible')
            ->assertJsonPath('hints.items.priere.level', 'bon')
            ->assertJsonPath('hints.items.sanctification.value', 'Mal');
        $this->assertSame('Pst Abraham', collect($res->json('resources'))->firstWhere('theme', 'priere')['items'][0]['author']);

        // Rien d'ecrit : refuse.
        $this->putJson('/api/me/resolutions', [])->assertStatus(422);

        // « Je renouvelle » ecrit en toutes lettres reprend la resolution du mois dernier.
        $this->putJson('/api/me/resolutions', ['meditation' => 'je renouvelle', 'jeune' => 'Le mercredi', 'priere' => 'Prier à 6 h'])
            ->assertOk()->assertJsonPath('current.meditation', 'Un psaume chaque matin')
            ->assertJsonPath('current.renewed', ['meditation', 'jeune']);

        // Bilan du mois dernier (seulement les rubriques renseignees).
        $this->postJson('/api/me/resolutions/review', ['outcome' => ['meditation' => 'atteinte', 'jeune' => 'en_partie', 'priere' => 'non']])
            ->assertOk()->assertJsonPath('previous.outcome', ['meditation' => 'atteinte', 'jeune' => 'en_partie'])->assertJsonPath('previous.achieved', 1);
        $this->postJson('/api/me/resolutions/review', ['outcome' => ['meditation' => 'peut-etre']])->assertStatus(422);
        $this->getJson('/api/me/resolutions')->assertJsonPath('review_needed', false)->assertJsonPath('current.count', 3);

        // Modification pendant le mois : une seule fiche.
        $this->putJson('/api/me/resolutions', ['meditation' => 'Deux psaumes'])->assertOk();
        $this->assertSame(1, MonthlyResolution::where('user_id', $this->marie->id)->where('period', '2026-10')->count());
    }

    public function test_resolutions_are_visible_to_pastors_and_tribe_leaders_only(): void
    {
        MonthlyResolution::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'priere' => 'Prier chaque soir']);

        foreach ([$this->pastor, $this->patriarch] as $leader) {
            $this->as($leader);
            $this->getJson('/api/admin/members/'.$this->marie->id.'/resolutions')->assertOk()->assertJsonPath('resolutions.0.priere', 'Prier chaque soir');
            $list = collect($this->getJson('/api/admin/resolutions')->assertOk()->json('members'));
            $this->assertTrue($list->firstWhere('user_id', $this->marie->id)['taken']);
        }
        // Le patriarche de Juda ne voit pas Levi dans son suivi.
        $this->as($this->patriarch);
        $this->assertNull(collect($this->getJson('/api/admin/resolutions')->json('members'))->firstWhere('user_id', $this->otherPatriarch->id));

        foreach ([$this->garde, $this->otherPatriarch, $this->marie] as $other) {
            $this->as($other);
            $this->getJson('/api/admin/members/'.$this->marie->id.'/resolutions')->assertForbidden();
        }
        $this->as($this->garde);
        $this->getJson('/api/admin/resolutions')->assertForbidden();
    }

    public function test_the_pastor_manages_resolution_resources(): void
    {
        $this->as($this->marie);
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'jeune', 'title' => 'X', 'author' => 'Y', 'url' => 'https://exemple.org'])->assertForbidden();

        $this->as($this->pastor);
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'jeune', 'title' => 'Le jeûne biblique', 'url' => 'https://exemple.org'])
            ->assertStatus(422)->assertJsonValidationErrors('author');
        $id = $this->postJson('/api/admin/resolution-resources', ['theme' => 'jeune', 'title' => 'Le jeûne biblique', 'author' => 'Pst Abraham', 'url' => 'youtu.be/dQw4w9WgXcQ'])
            ->assertCreated()->assertJsonPath('resource.youtube_id', 'dQw4w9WgXcQ')->json('resource.id');
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'culte', 'title' => 'Lien', 'author' => 'A', 'url' => 'javascript:alert(1)'])
            ->assertStatus(422);
        $this->putJson('/api/admin/resolution-resources/'.$id, ['theme' => 'culte', 'title' => 'Adorer en esprit', 'author' => 'Pst Abraham', 'url' => 'https://exemple.org/culte'])
            ->assertOk()->assertJsonPath('resource.youtube_id', null);
        $this->deleteJson('/api/admin/resolution-resources/'.$id)->assertOk();
        $this->assertSame(0, ResolutionResource::count());
    }

    // ---------------------------------------------------------------- 9-10. Mon devoir (DS)

    public function test_the_pastor_publishes_a_devoir_and_members_answer(): void
    {
        Storage::fake('public');
        $this->as($this->marie);
        $this->postJson('/api/admin/assignments', ['title' => 'X', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertForbidden();

        $this->as($this->pastor);
        $this->postJson('/api/admin/assignments', ['title' => 'La foi', 'media_type' => 'video'])->assertStatus(422)->assertJsonValidationErrors('url');
        $id = $this->postJson('/api/admin/assignments', [
            'title' => 'La foi qui agit', 'speaker' => 'Pst Abraham', 'media_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertCreated()->assertJsonPath('assignment.media.youtube_id', 'dQw4w9WgXcQ')->json('assignment.id');
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('title', 'Nouveau devoir : La foi qui agit')->count());

        // Audio : fichier depose, puis remplace par un autre.
        $audioId = $this->post('/api/admin/assignments', [
            'title' => 'Prière du matin', 'media_type' => 'audio', 'notify' => '0',
            'audio' => UploadedFile::fake()->create('priere.mp3', 800, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('assignment.id');
        $first = Assignment::find($audioId)->audio_path;
        Storage::disk('public')->assertExists($first);
        $this->post('/api/admin/assignments/'.$audioId, [
            'title' => 'Prière du matin (2)', 'media_type' => 'audio',
            'audio' => UploadedFile::fake()->create('priere2.mp3', 500, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($first);
        $this->post('/api/admin/assignments', ['title' => 'Mauvais fichier', 'media_type' => 'audio',
            'audio' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('audio');

        // Le membre voit le devoir et repond (resume + actions et resolutions).
        $this->as($this->marie);
        $list = $this->getJson('/api/me/assignments')->assertOk()->assertJsonPath('pending', 2);
        $this->assertStringContainsString('source de force', $list->json('prayer_reminder'));
        $this->putJson("/api/me/assignments/{$id}/response", ['summary' => 'Court', 'actions' => ''])->assertStatus(422)
            ->assertJsonValidationErrors(['summary', 'actions']);
        $this->putJson("/api/me/assignments/{$id}/response", [
            'summary' => 'La foi se voit dans les œuvres, comme Abraham.', 'actions' => 'Aider ma voisine cette semaine.',
        ])->assertOk();
        $this->getJson('/api/me/assignments')->assertJsonPath('pending', 2); // resume envoye mais video non regardee : pas encore fait
        $this->getJson('/api/me/month')->assertOk()->assertJsonPath('steps.assignment.id', $audioId)->assertJsonPath('steps.assignment.done', false);

        // Lecture : pasteur et patriarche de la tribu ; pas le Garde ni une autre tribu.
        foreach ([$this->pastor, $this->patriarch] as $leader) {
            $this->as($leader);
            $res = $this->getJson("/api/admin/assignments/{$id}/responses")->assertOk();
            $this->assertSame('Aider ma voisine cette semaine.', collect($res->json('responses'))->firstWhere('user_id', $this->marie->id)['actions']);
            $this->getJson('/api/admin/members/'.$this->marie->id.'/assignments')->assertOk()->assertJsonPath('responses.0.title', 'La foi qui agit');
        }
        $this->as($this->otherPatriarch);
        $this->assertNull(collect($this->getJson("/api/admin/assignments/{$id}/responses")->json('responses'))->firstWhere('user_id', $this->marie->id));
        $this->getJson('/api/admin/members/'.$this->marie->id.'/assignments')->assertForbidden();
        $this->as($this->garde);
        $this->getJson('/api/admin/assignments')->assertForbidden();

        // Retire : plus visible des membres, la reponse reste dans l'historique du membre.
        $this->as($this->pastor);
        $this->deleteJson("/api/admin/assignments/{$id}")->assertOk();
        $this->assertSame(1, AssignmentResponse::count());
        $this->as($this->marie);
        $this->assertNotContains($id, collect($this->getJson('/api/me/assignments')->json('assignments'))->pluck('id')->all());
        $this->as($this->patriarch);
        $this->getJson('/api/admin/members/'.$this->marie->id.'/assignments')->assertJsonPath('responses.0.withdrawn', true);
    }

    // ---------------------------------------------------------------- Automatismes et « Mon mois »

    public function test_reminders_for_resolutions_and_devoirs_are_sent_once(): void
    {
        $this->as($this->pastor);
        $this->postJson('/api/admin/assignments', ['title' => 'Devoir', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'notify' => false])->assertCreated();
        AssignmentResponse::create(['assignment_id' => Assignment::value('id'), 'user_id' => $this->patriarch->id, 'summary' => 'Résumé assez long', 'actions' => 'Agir', 'submitted_at' => now()]);
        AssignmentView::create(['assignment_id' => Assignment::value('id'), 'user_id' => $this->patriarch->id, 'segments' => [], 'completed_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 11:00:00'));
        $this->artisan('app:tick', ['--only' => 'assignments'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'assignments'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('title', 'Votre devoir : Devoir')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Votre devoir : Devoir')->count());

        MonthlyResolution::create(['user_id' => $this->patriarch->id, 'period' => '2026-11', 'priere' => 'Prier']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:30:00'));
        $this->artisan('app:tick', ['--only' => 'resolutions'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('type', 'resolution')->count()); // avant 9 h
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:05:00'));
        $this->artisan('app:tick', ['--only' => 'resolutions'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'resolutions'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('type', 'resolution')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->patriarch->id)->where('type', 'resolution')->count());

        $this->as($this->marie);
        $this->getJson('/api/me/month')->assertOk()->assertJsonPath('steps.resolutions.done', false)
            ->assertJsonPath('steps.fiss.done', false)->assertJsonPath('total', 4)->assertJsonPath('done', 0);
    }

    // ---------------------------------------------------------------- Suivi de lecture des devoirs (comme les exercices)

    /** Signal du lecteur, $after secondes apres le precedent. */
    private function beatDevoir(int $id, array $payload, int $after = 30)
    {
        Carbon::setTestNow(now()->addSeconds($after));
        $this->as($this->marie);

        return $this->postJson("/api/me/assignments/{$id}/progress", $payload + ['duration' => 120, 'rate' => 1]);
    }

    private function publishDevoir(array $data = []): int
    {
        $this->as($this->pastor);

        return $this->postJson('/api/admin/assignments', $data + [
            'title' => 'La foi qui agit', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'notify' => false,
        ])->assertCreated()->json('assignment.id');
    }

    public function test_a_devoir_is_done_only_when_watched_to_the_end_and_summarised(): void
    {
        $id = $this->publishDevoir();
        $this->as($this->marie);
        $this->putJson("/api/me/assignments/{$id}/response", ['summary' => 'La foi se voit dans les œuvres.', 'actions' => 'Aider ma voisine.'])
            ->assertOk()->assertJsonPath('done', false)->assertJsonPath('status', 'in_progress');
        $this->getJson('/api/me/month')->assertJsonPath('steps.assignment.done', false);
        $this->getJson('/api/me/assignments')->assertJsonPath('pending', 1)->assertJsonPath('assignments.0.percent', 0);

        // Lecture continue par tranches de 30 s : comptee ; la duree est fixee par le premier lecteur.
        $this->beatDevoir($id, ['segments' => [[0, 20]], 'position' => 20], 15)->assertOk()->assertJson(['percent' => 16]);
        $this->assertSame(120, Assignment::find($id)->media_duration);
        $this->beatDevoir($id, ['segments' => [[20, 60]], 'position' => 60])->assertOk();
        // Reprise la ou le membre s'etait arrete.
        $this->getJson('/api/me/assignments')->assertJsonPath('assignments.0.resume_at', 60)->assertJsonPath('assignments.0.status', 'in_progress');
        // Plus de lecture que le temps ecoule : ignoree.
        $this->beatDevoir($id, ['segments' => [[60, 120]], 'position' => 120], 5)->assertOk()->assertJson(['percent' => 50]);
        $this->beatDevoir($id, ['segments' => [[60, 90]], 'position' => 90])->assertOk();
        $this->beatDevoir($id, ['segments' => [[90, 120]], 'position' => 120])->assertOk()->assertJson(['video_completed' => true, 'status' => 'done']);

        $this->getJson('/api/me/month')->assertJsonPath('steps.assignment.done', true);
        $this->getJson('/api/me/assignments')->assertJsonPath('pending', 0)->assertJsonPath('assignments.0.done', true);
        $this->as($this->pastor);
        $this->assertSame(1, collect($this->getJson('/api/admin/assignments')->json('assignments'))->firstWhere('id', $id)['done_count']);
    }

    public function test_skipping_ahead_is_detected_and_does_not_count(): void
    {
        $id = $this->publishDevoir();
        $this->beatDevoir($id, ['segments' => [[0, 20]], 'position' => 20], 15)->assertOk();
        // Le membre avance jusqu'a la fin : rien n'est valide, l'avance rapide est signalee.
        $this->beatDevoir($id, ['segments' => [[110, 120]], 'position' => 120, 'seeks' => 1, 'skipped' => 90], 20)
            ->assertOk()->assertJson(['video_completed' => false]);
        $this->putJson("/api/me/assignments/{$id}/response", ['summary' => 'Résumé du message.', 'actions' => 'Prier.'])->assertJsonPath('done', false);

        $this->as($this->patriarch);
        $row = collect($this->getJson("/api/admin/assignments/{$id}/responses")->assertOk()->json('responses'))->firstWhere('user_id', $this->marie->id);
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame(1, $row['seek_count']);
        $this->assertGreaterThanOrEqual(90, $row['skipped_seconds']);
        $this->assertLessThan(100, $row['percent']);
        $this->assertFalse($this->getJson('/api/admin/members/'.$this->marie->id.'/assignments')->json('responses.0.done'));

        // Relance : le membre qui n'a pas fini est relance, meme s'il a ecrit son resume.
        Carbon::setTestNow(now()->addDays(3)->setTime(11, 0));
        $this->artisan('app:tick', ['--only' => 'assignments'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('type', 'assignment')->count());
    }

    public function test_an_external_link_is_not_measured_and_a_new_media_resets_the_reading(): void
    {
        // Lien externe : rien a mesurer, le resume suffit.
        $linkId = $this->publishDevoir(['title' => 'Message en ligne', 'url' => 'https://exemple.org/message']);
        $this->as($this->marie);
        $this->postJson("/api/me/assignments/{$linkId}/progress", ['segments' => [[0, 10]]])->assertNotFound();
        $this->putJson("/api/me/assignments/{$linkId}/response", ['summary' => 'Résumé du message.', 'actions' => 'Prier.'])
            ->assertJsonPath('done', true)->assertJsonPath('percent', null);

        // Nouvelle video : la lecture deja faite ne compte plus.
        $id = $this->publishDevoir();
        $this->beatDevoir($id, ['segments' => [[0, 20]], 'position' => 20], 15)->assertOk();
        $this->as($this->pastor);
        $this->post("/api/admin/assignments/{$id}", ['title' => 'La foi qui agit', 'media_type' => 'video', 'url' => 'https://youtu.be/KSvbDy7Yv7k'],
            ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, AssignmentView::where('assignment_id', $id)->count());
        $this->assertNull(Assignment::find($id)->media_duration);
        // Simple correction du titre : la lecture est gardee.
        $this->beatDevoir($id, ['segments' => [[0, 20]], 'position' => 20], 15)->assertOk();
        $this->as($this->pastor);
        $this->post("/api/admin/assignments/{$id}", ['title' => 'La foi qui agit (2)', 'media_type' => 'video', 'url' => 'https://youtu.be/KSvbDy7Yv7k'],
            ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, AssignmentView::where('assignment_id', $id)->count());
    }

    public function test_welcome_back_lists_new_devoirs_not_done(): void
    {
        $since = now()->subDays(6)->toIso8601String();
        $id = $this->publishDevoir();
        $this->as($this->marie);
        $this->getJson('/api/me/welcome-back?since='.urlencode($since))->assertOk()
            ->assertJsonPath('assignments.count', 1)->assertJsonPath('assignments.items.0.id', $id);
    }
}
