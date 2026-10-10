<?php

namespace Tests\Feature;

use App\Models\BlockedPhone;
use App\Models\Department;
use App\Models\Exercise;
use App\Models\MemberDeletionRequest;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VertumetreForm;
use App\Services\Monitoring\UserAdmin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deuxieme lot d'octobre 2026 : DS reserves au PR, titres en texte simple, suppression d'un membre
 * (PR, demandes des responsables) et numero bloque, Vertumetre mensuel verrouille (meme logique que la FISS).
 */
class MembershipAndVertumetreTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pr;

    private User $pa;

    private User $patriarch;

    private User $otherPatriarch;

    private User $deptLeader;

    private User $garde;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 11:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pr = $this->give($this->member('+14185590001', $this->juda, 'Pasteur'), 'super_admin');
        $this->pa = $this->give($this->member('+14185590002', null, 'Assistant'), 'pasteur_assistant');
        $this->patriarch = $this->give($this->member('+14185590003', $this->juda, 'Patriarche'), 'patriarche', 'tribe', $this->juda->id);
        $this->otherPatriarch = $this->give($this->member('+14185590004', $this->levi, 'Autre'), 'patriarche', 'tribe', $this->levi->id);
        $this->garde = $this->give($this->member('+14185590005', $this->juda, 'Garde'), 'garde', 'gem', 999);
        $this->deptLeader = $this->member('+14185590006', $this->levi, 'Chantre');
        $this->marie = $this->member('+14185590007', $this->juda, 'Marie');

        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $chorale->members()->attach($this->marie->profile->id);
        $chorale->leaders()->attach($this->deptLeader->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'matricule' => 'VHC'.substr($phone, -4)]);
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

    // ---------------------------------------------------------------- DS : le PR seul publie

    public function test_only_the_resident_pastor_publishes_devoirs_and_resources(): void
    {
        $this->as($this->pa);
        $this->postJson('/api/admin/assignments', ['title' => 'Devoir', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertForbidden();
        $this->getJson('/api/admin/resolution-resources')->assertForbidden();
        $this->getJson('/api/admin/assignments')->assertOk()->assertJsonPath('can_manage', false); // le PA suit, il ne publie pas

        $this->as($this->pr);
        $this->getJson('/api/admin/assignments')->assertOk()->assertJsonPath('can_manage', true);
        $this->postJson('/api/admin/assignments', ['title' => '🔴𝗟𝗮 𝗳𝗼𝗶 𝗾𝘂𝗶 𝗮𝗴𝗶𝘁', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'notify' => false])
            ->assertCreated()->assertJsonPath('assignment.title', 'La foi qui agit');
    }

    public function test_decorated_titles_are_shown_as_plain_text(): void
    {
        $exercise = $this->makeExercise(['title' => 'x', 'content' => '', 'type' => 'lecture', 'is_active' => true]);
        \DB::table('exercises')->where('id', $exercise->id)->update(['title' => '🔴𝗧𝘂 𝘃𝗲𝘂𝘅 𝗿𝗲𝘀𝘀𝗲𝗺𝗯𝗹𝗲𝗿 𝗮̀ 𝗖𝗵𝗿𝗶𝘀𝘁?']);
        $this->assertSame('Tu veux ressembler à Christ?', Exercise::find($exercise->id)->title);
    }

    // ---------------------------------------------------------------- Suppression d'un membre

    public function test_the_resident_pastor_deletes_a_member_and_the_number_is_blocked(): void
    {
        $this->as($this->pa);
        $this->deleteJson("/api/admin/members/{$this->marie->id}", ['reason' => 'Doublon de compte'])->assertForbidden();

        $this->as($this->pr);
        $this->getJson("/api/admin/members/{$this->marie->id}/deletion")->assertOk()->assertJsonPath('can_delete', true)
            ->assertJsonPath('impact.deleted.0.label', 'Profil (identité, appartenance)');
        $this->deleteJson("/api/admin/members/{$this->pr->id}", ['reason' => 'Essai sur soi'])->assertForbidden();
        $this->deleteJson("/api/admin/members/{$this->marie->id}", [])->assertStatus(422);
        $this->deleteJson("/api/admin/members/{$this->marie->id}", ['reason' => 'Doublon de compte'])->assertOk();
        $this->assertNull(User::find($this->marie->id));
        $this->assertTrue(BlockedPhone::where('phone', '+14185590007')->exists());

        // Le numero ne peut plus servir a une nouvelle inscription (aucun SMS envoye).
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/auth/request-otp', ['phone' => '+14185590007'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Ce numéro ne peut plus être utilisé pour s\'inscrire. Contactez l\'église.');

        // Le PR peut le debloquer.
        $this->as($this->pr);
        $id = $this->getJson('/api/admin/blocked-phones')->assertOk()->json('phones.0.id');
        $this->as($this->pa);
        $this->deleteJson("/api/admin/blocked-phones/{$id}")->assertForbidden();
        $this->as($this->pr);
        $this->deleteJson("/api/admin/blocked-phones/{$id}")->assertOk();
        $this->assertFalse(BlockedPhone::exists());
    }

    public function test_a_deletion_from_the_supervision_console_also_blocks_the_number(): void
    {
        UserAdmin::delete($this->marie);
        $this->assertTrue(BlockedPhone::where('phone', '+14185590007')->exists());
    }

    public function test_tribe_and_department_leaders_ask_the_resident_pastor_to_delete_a_member(): void
    {
        foreach ([$this->garde, $this->otherPatriarch, $this->pa] as $who) {
            $this->as($who);
            $this->postJson("/api/admin/members/{$this->marie->id}/deletion-requests", ['reason' => 'Ne fréquente plus'])->assertForbidden();
        }
        $this->as($this->deptLeader);
        $this->getJson("/api/admin/members/{$this->marie->id}/deletion")->assertOk()->assertJsonPath('can_request', true)->assertJsonPath('impact', null);
        $this->postJson("/api/admin/members/{$this->marie->id}/deletion-requests", ['reason' => 'A quitté la ville'])->assertCreated();

        $this->as($this->patriarch);
        $this->postJson("/api/admin/members/{$this->marie->id}/deletion-requests", ['reason' => 'Doublon'])->assertStatus(409);
        $this->assertSame(1, UserNotification::where('user_id', $this->pr->id)->where('title', 'Demande de suppression : Marie Test')->count());

        // Refus motive, puis nouvelle demande approuvee : le compte est supprime.
        $this->as($this->pr);
        $req = $this->getJson('/api/admin/validations')->assertOk()->json('deletions.0');
        $this->assertSame('A quitté la ville', $req['reason']);
        $this->postJson("/api/admin/validations/deletions/{$req['id']}", ['decision' => 'rejected'])->assertStatus(422);
        $this->postJson("/api/admin/validations/deletions/{$req['id']}", ['decision' => 'rejected', 'comment' => 'Elle revient en janvier'])->assertOk();
        $this->assertSame('Elle revient en janvier', UserNotification::where('user_id', $this->deptLeader->id)->latest('id')->value('body'));

        $this->as($this->patriarch);
        $this->postJson("/api/admin/members/{$this->marie->id}/deletion-requests", ['reason' => 'Doublon de compte'])->assertCreated();
        $this->as($this->pr);
        $id = MemberDeletionRequest::where('status', 'pending')->value('id');
        $this->postJson("/api/admin/validations/deletions/{$id}", ['decision' => 'approved'])->assertOk();
        $this->assertNull(User::find($this->marie->id));
        $this->assertSame('approved', MemberDeletionRequest::find($id)->status);
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Suppression effectuée : Marie Test')->count());
    }

    // ---------------------------------------------------------------- Vertumetre mensuel

    /** @return array<string, mixed> */
    private function answers(array $overrides = []): array
    {
        return $overrides + [
            'priere_frequence' => '2x', 'priere_duree' => '15-30', 'jeune_fois' => '1x',
            'priere_sujets' => ['famille', 'sante'], 'soutien_priere' => 'Mon travail',
            'meditation_frequence' => '1x', 'meditation_duree' => '0-15', 'lecture_volume' => 'chapitre',
            'plan_lecture' => 'annee', 'plan_lecture_precision' => 'La Bible en un an', 'enseignements' => 'Série sur la foi',
            'temoignage_sujets' => ['famille'], 'temoignage_texte' => 'Réconciliation avec mon frère',
            'priorites' => 'en_partie', 'parole_tenue' => 'pleinement', 'priere_avant' => 'pas_du_tout',
            'pardon' => 'en_partie', 'irritation' => 'parfois', 'dieu_dabord' => 'na',
        ];
    }

    public function test_the_vertumetre_is_filled_once_a_month_and_locked(): void
    {
        $this->as($this->marie);
        $data = $this->getJson('/api/me/vertumetre')->assertOk();
        $this->assertSame(['2026-10'], collect($data->json('periods'))->pluck('period')->all());
        $this->assertSame('Pas du tout ce mois-ci', $data->json('sections.0.questions.0.options.0.label'));

        // Reponses manquantes ou invalides : refusees, question par question.
        $this->postJson('/api/me/vertumetre', ['period' => '2026-10', 'answers' => ['priere_frequence' => 'beaucoup']])
            ->assertStatus(422)->assertJsonValidationErrors(['answers.priere_frequence', 'answers.irritation']);
        $this->postJson('/api/me/vertumetre', ['period' => '2026-10', 'answers' => $this->answers(['priere_sujets' => ['autre']])])
            ->assertStatus(422)->assertJsonValidationErrors('answers.priere_sujets_autre');
        // Mois passe : ne se rattrape pas (le mois en cours seulement).
        $this->postJson('/api/me/vertumetre', ['period' => '2026-09', 'answers' => $this->answers()])->assertStatus(422);

        $id = $this->postJson('/api/me/vertumetre', ['period' => '2026-10', 'answers' => $this->answers()])->assertCreated()
            ->assertJsonPath('form.locked', true)->assertJsonPath('message', 'Vertumètre d’octobre 2026 enregistré. Merci !')->json('form.id');
        $this->postJson('/api/me/vertumetre', ['period' => '2026-10', 'answers' => $this->answers()])->assertStatus(409);
        $this->putJson("/api/me/vertumetre/{$id}", ['answers' => $this->answers(['priere_frequence' => '3x'])])->assertStatus(423);
        $this->getJson('/api/me/month')->assertJsonPath('steps.vertumetre.done', true);

        // Demande de modification -> patriarche -> une modification -> reverrouille (meme logique que la FISS).
        $this->postJson("/api/me/vertumetre/{$id}/edit-requests", ['reason' => 'Je me suis trompé de durée'])->assertCreated();
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Demande de modification du Vertumètre : Marie Test')->count());
        $this->as($this->otherPatriarch);
        $this->assertSame([], $this->getJson('/api/admin/validations')->json('vertumetre'));
        $this->as($this->patriarch);
        $req = $this->getJson('/api/admin/validations')->json('vertumetre.0');
        $this->postJson("/api/admin/validations/vertumetre/{$req['id']}", ['decision' => 'approved'])->assertOk();
        $this->as($this->marie);
        $this->putJson("/api/me/vertumetre/{$id}", ['answers' => $this->answers(['priere_duree' => '30-60'])])->assertOk()->assertJsonPath('form.locked', true);
        $this->assertSame('30-60', VertumetreForm::find($id)->answers['priere_duree']);
        $this->putJson("/api/me/vertumetre/{$id}", ['answers' => $this->answers()])->assertStatus(423);
        $this->postJson("/api/me/vertumetre/{$id}/edit-requests", ['reason' => 'Encore une correction'])->assertCreated();
        $this->as($this->patriarch);
        $req = $this->getJson('/api/admin/validations')->json('vertumetre.0');
        $this->postJson("/api/admin/validations/vertumetre/{$req['id']}", ['decision' => 'rejected', 'comment' => 'Pas nécessaire'])->assertOk();
        $this->as($this->marie);
        $this->postJson("/api/me/vertumetre/{$id}/edit-requests", ['reason' => 'Troisième essai'])->assertStatus(422);
    }

    public function test_the_vertumetre_is_read_by_pastors_and_tribe_leaders_only(): void
    {
        VertumetreForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'answers' => $this->answers(), 'submitted_at' => now(), 'locked_at' => now()]);
        foreach ([$this->pr, $this->pa, $this->patriarch] as $leader) {
            $this->as($leader);
            $this->getJson("/api/admin/members/{$this->marie->id}/vertumetre")->assertOk()->assertJsonPath('forms.0.answers.soutien_priere', 'Mon travail');
            $this->assertTrue(collect($this->getJson('/api/admin/vertumetre')->assertOk()->json('members'))->firstWhere('user_id', $this->marie->id)['filled']);
        }
        foreach ([$this->garde, $this->otherPatriarch, $this->deptLeader] as $other) {
            $this->as($other);
            $this->getJson("/api/admin/members/{$this->marie->id}/vertumetre")->assertForbidden();
        }
    }

    public function test_vertumetre_reminders_reach_only_those_who_have_not_filled_it(): void
    {
        VertumetreForm::create(['user_id' => $this->marie->id, 'period' => '2026-10', 'answers' => $this->answers(), 'submitted_at' => now(), 'locked_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00'));
        $this->artisan('app:tick', ['--only' => 'vertumetre'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'vertumetre'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('user_id', $this->marie->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->garde->id)->where('title', 'Rappel : votre Vertumètre d’octobre 2026')->count());
    }
}
