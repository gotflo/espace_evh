<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\FamilyLink;
use App\Models\FissEditRequest;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ProfileCompletion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformRulesTest extends TestCase
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

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre', string $last = 'T', array $extra = []): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now()]);
        Profile::create($extra + ['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'homme',
            'is_completed' => true, 'tribe_id' => $tribe?->id]);

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    private function fiss(): array
    {
        return ['meditation' => 14, 'priere' => 12, 'jeune' => 10, 'sanctification_corps' => 'bien', 'situation_financiere' => 12];
    }

    // ------------------------------------------------------------ FISS : verrouillage, demandes, limite, audit

    public function test_fiss_is_locked_and_edits_go_through_the_patriarch_twice_at_most(): void
    {
        $patriarch = $this->give($this->member('+14185550500', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $otherPatriarch = $this->give($this->member('+14185550501', $this->levi, 'Autre'), 'patriarche', 'tribe', $this->levi->id);
        $member = $this->member('+14185550502', $this->juda, 'Marie');

        Sanctum::actingAs($member);
        $form = $this->postJson('/api/me/fiss', $this->fiss())->assertCreated()->json('current');
        $this->assertTrue($form['locked']);
        $this->postJson('/api/me/fiss', $this->fiss())->assertStatus(409); // une seule fiche par mois
        $this->putJson("/api/me/fiss/{$form['id']}", ['meditation' => 20])->assertStatus(423); // verrouillee

        // Demande n°1 -> patriarche prevenu ; un patriarche d'une autre tribu ne peut pas decider.
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Erreur de saisie'])->assertCreated();
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Encore'])->assertStatus(409);
        $this->assertSame(1, UserNotification::where('user_id', $patriarch->id)->where('type', 'fiss_request')->count());
        $this->assertSame(0, UserNotification::where('user_id', $otherPatriarch->id)->count());
        $req1 = FissEditRequest::first();

        Sanctum::actingAs($otherPatriarch);
        $this->postJson("/api/admin/validations/fiss/{$req1->id}", ['decision' => 'approved'])->assertForbidden();
        Sanctum::actingAs($member);
        $this->postJson("/api/admin/validations/fiss/{$req1->id}", ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs($patriarch);
        $this->assertCount(1, $this->getJson('/api/admin/validations')->assertOk()->json('fiss'));
        $this->postJson("/api/admin/validations/fiss/{$req1->id}", ['decision' => 'approved'])->assertOk();
        $this->postJson("/api/admin/validations/fiss/{$req1->id}", ['decision' => 'approved'])->assertStatus(409);

        // Modification unique, puis reverrouillage.
        Sanctum::actingAs($member);
        $this->putJson("/api/me/fiss/{$form['id']}", array_merge($this->fiss(), ['meditation' => 18]))->assertOk()->assertJsonPath('form.locked', true);
        $this->putJson("/api/me/fiss/{$form['id']}", array_merge($this->fiss(), ['meditation' => 20]))->assertStatus(423);
        $this->assertSame(18, SpiritualHealthForm::find($form['id'])->meditation);

        // Demande n°2 refusee, demande n°3 impossible (limite serveur).
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Autre correction'])->assertCreated();
        $req2 = FissEditRequest::latest('id')->first();
        Sanctum::actingAs($patriarch);
        $this->postJson("/api/admin/validations/fiss/{$req2->id}", ['decision' => 'rejected'])->assertStatus(422); // motif du refus obligatoire
        $this->postJson("/api/admin/validations/fiss/{$req2->id}", ['decision' => 'rejected', 'comment' => 'Pas nécessaire'])->assertOk();
        Sanctum::actingAs($member);
        $this->postJson("/api/me/fiss/{$form['id']}/edit-requests", ['reason' => 'Troisième essai'])->assertStatus(422);

        // Tracabilite complete, avec anciennes et nouvelles valeurs.
        $actions = AuditLog::orderBy('id')->pluck('action')->all();
        foreach (['fiss.created', 'fiss.locked', 'fiss.edit_requested', 'fiss.edit_approved', 'fiss.unlocked', 'fiss.modified', 'fiss.edit_rejected'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $modified = AuditLog::where('action', 'fiss.modified')->first();
        $this->assertSame(['meditation' => 14], $modified->old_values);
        $this->assertSame(['meditation' => 18], $modified->new_values);
        $this->assertSame($member->id, $modified->user_id);

        Sanctum::actingAs($patriarch);
        $history = $this->getJson("/api/admin/members/{$member->id}/fiss-history")->assertOk()->json('history');
        $this->assertGreaterThanOrEqual(7, count($history));
        Sanctum::actingAs($otherPatriarch);
        $this->getJson("/api/admin/members/{$member->id}/fiss-history")->assertForbidden();
    }

    public function test_unused_unlock_expires_and_the_form_is_locked_again(): void
    {
        $patriarch = $this->give($this->member('+14185550510', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $member = $this->member('+14185550511', $this->juda);
        Sanctum::actingAs($member);
        $id = $this->postJson('/api/me/fiss', $this->fiss())->json('current.id');
        $this->postJson("/api/me/fiss/{$id}/edit-requests", ['reason' => 'Correction'])->assertCreated();
        Sanctum::actingAs($patriarch);
        $this->postJson('/api/admin/validations/fiss/'.FissEditRequest::first()->id, ['decision' => 'approved'])->assertOk();
        $this->assertNull(SpiritualHealthForm::find($id)->locked_at);

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertNotNull(SpiritualHealthForm::find($id)->locked_at);
        $this->assertSame('expired', FissEditRequest::first()->status);
    }

    public function test_patriarch_is_told_which_members_missed_last_months_fiss(): void
    {
        $patriarch = $this->give($this->member('+14185550520', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $done = $this->member('+14185550521', $this->juda, 'Fait');
        $missing = $this->member('+14185550522', $this->juda, 'Oublie');
        Profile::query()->update(['created_at' => now()->subMonths(3)]);
        SpiritualHealthForm::create(['user_id' => $done->id, 'period' => '2026-09', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-02 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful(); // idempotent

        $notes = UserNotification::where('user_id', $patriarch->id)->where('title', 'like', '%sans FISS%')->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Oublie', $notes[0]->body);
        $this->assertStringNotContainsString('Fait', $notes[0]->body);
    }

    // ------------------------------------------------------------ Changement de tribu

    public function test_tribe_change_needs_both_tribes_approval_and_is_fully_traced(): void
    {
        $from = $this->give($this->member('+14185550530', $this->juda, 'PJuda'), 'patriarche', 'tribe', $this->juda->id);
        $to = $this->give($this->member('+14185550531', $this->levi, 'PLevi'), 'patriarche', 'tribe', $this->levi->id);
        $member = $this->member('+14185550532', $this->juda, 'Paul');

        Sanctum::actingAs($member);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->juda->id, 'reason' => 'Même tribu'])->assertStatus(422);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Je déménage'])->assertCreated();
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Doublon'])->assertStatus(409);
        $req = TribeChangeRequest::first();
        $this->assertSame(1, UserNotification::where('user_id', $from->id)->where('type', 'tribe_change')->count());
        $this->assertSame(1, UserNotification::where('user_id', $to->id)->where('type', 'tribe_change')->count());

        Sanctum::actingAs($from);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame($this->juda->id, Profile::where('user_id', $member->id)->value('tribe_id'), 'Pas encore : la nouvelle tribu doit valider');
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertForbidden(); // deja prononce

        Sanctum::actingAs($to);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('message', 'Changement de tribu effectué.');
        $this->assertSame($this->levi->id, Profile::where('user_id', $member->id)->value('tribe_id'));
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame(2, $req->approvals()->count());
        $this->assertSame(1, AuditLog::where('action', 'tribe.changed')->where('member_user_id', $member->id)->count());

        Sanctum::actingAs($member);
        $history = $this->getJson('/api/me/tribe-change')->assertOk()->json('requests.0');
        $this->assertSame('approved', $history['status']);
        $this->assertCount(2, $history['approvals']);
    }

    public function test_tribe_change_refused_by_one_side_is_closed(): void
    {
        $from = $this->give($this->member('+14185550540', $this->juda, 'PJuda'), 'patriarche', 'tribe', $this->juda->id);
        $member = $this->member('+14185550541', $this->juda);
        Sanctum::actingAs($member);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Raison'])->assertCreated();
        $req = TribeChangeRequest::first();

        Sanctum::actingAs($from);
        $this->postJson("/api/admin/validations/tribe/{$req->id}", ['decision' => 'rejected', 'comment' => 'Parlons-en d\'abord'])->assertOk();
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame($this->juda->id, Profile::where('user_id', $member->id)->value('tribe_id'));
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('title', 'Changement de tribu refusé')->count());
    }

    public function test_pastoral_authority_can_approve_both_sides_at_once(): void
    {
        $pastor = $this->give($this->member('+14185550550', null, 'Pasteur'), 'pasteur_assistant');
        $member = $this->member('+14185550551', $this->juda);
        Sanctum::actingAs($member);
        $this->postJson('/api/me/tribe-change', ['to_tribe_id' => $this->levi->id, 'reason' => 'Raison'])->assertCreated();

        Sanctum::actingAs($pastor);
        $this->postJson('/api/admin/validations/tribe/'.TribeChangeRequest::first()->id, ['decision' => 'approved'])->assertOk();
        $this->assertSame($this->levi->id, Profile::where('user_id', $member->id)->value('tribe_id'));
    }

    // ------------------------------------------------------------ Famille

    public function test_spouse_link_is_confirmed_by_the_other_person_and_is_bidirectional(): void
    {
        $a = $this->member('+14185550560', $this->juda, 'Jean', 'Dupont', ['marital_status' => 'marie', 'wedding_day' => 12, 'wedding_month' => 6]);
        $b = $this->member('+14185550561', $this->juda, 'Marie', 'Dupont');
        $homonym = $this->member('+14185550562', $this->levi, 'Marie', 'Dupont');

        Sanctum::actingAs($a);
        $results = collect($this->getJson('/api/me/family/search?q=marie dupont')->assertOk()->json('results'));
        $this->assertEqualsCanonicalizing([$b->id, $homonym->id], $results->pluck('user_id')->all(), 'Les homonymes sont proposés, jamais choisis automatiquement');
        $this->assertArrayNotHasKey('phone', $results[0]);

        $this->putJson('/api/me/family/spouse', ['user_id' => $b->id])->assertOk()->assertJsonPath('family.spouse.status', 'pending');
        $this->assertSame(0, FamilyLink::where('user_id', $b->id)->count(), 'Aucun lien créé chez B avant sa confirmation');

        Sanctum::actingAs($b);
        $incoming = $this->getJson('/api/me/family')->json('incoming.0');
        $this->assertSame($a->id, $incoming['from_user_id']);
        $this->postJson("/api/me/family/links/{$incoming['id']}/confirm")->assertOk();

        $this->assertSame('confirmed', FamilyLink::where('user_id', $a->id)->where('relation', 'spouse')->value('status'));
        $this->assertSame($a->id, (int) FamilyLink::where('user_id', $b->id)->where('relation', 'spouse')->value('relative_user_id'));
        $pb = Profile::where('user_id', $b->id)->first();
        $this->assertSame('marie', $pb->marital_status);
        $this->assertSame(12, $pb->wedding_day, 'Date de mariage partagée');

        // Le homonyme ne peut plus designer quelqu'un deja lie.
        Sanctum::actingAs($homonym);
        $this->putJson('/api/me/family/spouse', ['user_id' => $a->id])->assertStatus(422);

        // Changement de situation : lien retire des deux cotes.
        Sanctum::actingAs($a);
        $this->putJson('/api/profile', ['first_name' => 'Jean', 'last_name' => 'Dupont', 'gender' => 'homme', 'tribe_id' => $this->juda->id, 'marital_status' => 'celibataire'])->assertOk();
        $this->assertSame(0, FamilyLink::where('relation', 'spouse')->count());
        $this->assertNull(Profile::where('user_id', $a->id)->value('wedding_day'));
    }

    public function test_declined_spouse_link_and_unregistered_spouse_name(): void
    {
        $a = $this->member('+14185550570', $this->juda, 'Luc', 'Martin', ['marital_status' => 'marie']);
        $b = $this->member('+14185550571', $this->juda, 'Anne', 'Martin');
        Sanctum::actingAs($a);
        $this->putJson('/api/me/family/spouse', ['user_id' => $b->id])->assertOk();
        Sanctum::actingAs($b);
        $this->postJson('/api/me/family/links/'.FamilyLink::first()->id.'/decline')->assertOk();
        $this->assertSame('declined', FamilyLink::first()->status);
        $this->assertSame(0, FamilyLink::where('user_id', $b->id)->count());

        // Conjoint non inscrit : nom seulement, puis suggestion (sans lien automatique).
        Sanctum::actingAs($a);
        $this->putJson('/api/me/family/spouse', ['name' => 'Anne Martin'])->assertOk();
        $this->assertSame(0, FamilyLink::where('user_id', $a->id)->where('relation', 'spouse')->count());
        $this->assertSame([$b->id], collect($this->getJson('/api/me/family')->json('suggestions'))->pluck('user_id')->all());
    }

    public function test_children_are_recorded_and_counted(): void
    {
        $parent = $this->member('+14185550580', $this->juda, 'Parent');
        $child = $this->member('+14185550581', $this->juda, 'Grand', 'Enfant');
        Sanctum::actingAs($parent);
        $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [
            ['name' => 'Petit Enfant', 'birth_year' => 2018],
            ['name' => 'Grand Enfant', 'birth_year' => 2004, 'user_id' => $child->id],
        ]])->assertOk();
        $this->putJson('/api/me/family/children', ['has_children' => true, 'children' => [['name' => 'X', 'birth_year' => 2100]]])->assertStatus(422);

        $p = Profile::where('user_id', $parent->id)->first();
        $this->assertTrue($p->has_children);
        $this->assertSame(2, $p->children_count);
        $this->assertSame('pending', FamilyLink::where('relative_user_id', $child->id)->value('status'));
        $this->assertSame(1, UserNotification::where('user_id', $child->id)->where('type', 'family')->count());
    }

    // ------------------------------------------------------------ Completion du profil

    public function test_profile_completion_lists_missing_information_dynamically(): void
    {
        $user = $this->member('+14185550590', $this->juda);
        Sanctum::actingAs($user);
        $missing = collect($this->getJson('/api/profile')->json('completion.missing'))->pluck('key')->all();
        $this->assertContains('birthday', $missing);
        $this->assertContains('marital_status', $missing);
        $this->assertNotContains('spouse', $missing);

        $res = $this->putJson('/api/profile', ['first_name' => 'Membre', 'last_name' => 'T', 'gender' => 'homme', 'tribe_id' => $this->juda->id,
            'birth_day' => 31, 'birth_month' => 4])->assertStatus(422); // 31 avril invalide
        $res = $this->putJson('/api/profile', ['first_name' => 'Membre', 'last_name' => 'T', 'gender' => 'homme', 'tribe_id' => $this->juda->id,
            'birth_day' => 29, 'birth_month' => 2, 'marital_status' => 'marie'])->assertOk();
        $keys = collect($res->json('completion.missing'))->pluck('key')->all();
        $this->assertContains('spouse', $keys, 'Marié(e) : le conjoint devient nécessaire');
        $this->assertContains('wedding_date', $keys);
        $this->assertLessThan(100, Profile::where('user_id', $user->id)->value('completion'));
    }

    public function test_daily_automation_recomputes_stored_completion_of_existing_profiles(): void
    {
        $user = $this->member('+14185550591', $this->juda);
        // Profil anterieur a la colonne : taux stocke a 0.
        Profile::where('user_id', $user->id)->update(['completion' => 0]);

        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $stored = (int) Profile::where('user_id', $user->id)->value('completion');
        $this->assertGreaterThan(0, $stored);
        $this->assertSame(ProfileCompletion::for(Profile::where('user_id', $user->id)->first())['percent'], $stored);

        // Une seule fois par jour : un second passage ne recalcule pas.
        Profile::where('user_id', $user->id)->update(['completion' => 0]);
        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->assertSame(0, (int) Profile::where('user_id', $user->id)->value('completion'));
    }

    // ------------------------------------------------------------ Anniversaires

    public function test_birthday_message_with_verse_is_sent_once_and_leader_is_told(): void
    {
        $patriarch = $this->give($this->member('+14185550600', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $member = $this->member('+14185550601', $this->juda, 'Adams', 'K', ['birth_day' => 25, 'birth_month' => 9]);
        $leap = $this->member('+14185550602', $this->juda, 'Bissextile', 'L', ['birth_day' => 29, 'birth_month' => 2]);

        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();

        $wish = UserNotification::where('user_id', $member->id)->where('type', 'birthday')->get();
        $this->assertCount(1, $wish);
        $this->assertStringContainsString('Adams', $wish[0]->title);
        $this->assertMatchesRegularExpression('/« .+ » \(.+\d+\.\d+/u', $wish[0]->body, 'Le message contient un verset');
        $this->assertSame(1, UserNotification::where('user_id', $patriarch->id)->where('title', 'like', "Anniversaire aujourd'hui%Adams%")->count());

        // 29 fevrier : fete le 28 les annees non bissextiles (2027).
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $leap->id)->where('type', 'birthday')->count());
    }

    public function test_wedding_anniversary_appears_in_calendar_once_per_couple_and_is_wished(): void
    {
        $a = $this->member('+14185550610', $this->juda, 'Jean', 'D', ['marital_status' => 'marie', 'wedding_day' => 26, 'wedding_month' => 9]);
        $b = $this->member('+14185550611', $this->juda, 'Marie', 'D', ['marital_status' => 'marie', 'wedding_day' => 26, 'wedding_month' => 9]);
        FamilyLink::create(['user_id' => $a->id, 'relation' => 'spouse', 'relative_user_id' => $b->id, 'status' => 'confirmed', 'confirmed_at' => now()]);
        FamilyLink::create(['user_id' => $b->id, 'relation' => 'spouse', 'relative_user_id' => $a->id, 'status' => 'confirmed', 'confirmed_at' => now()]);

        Sanctum::actingAs($a);
        $weddings = $this->getJson('/api/calendar?from=2026-09-20&to=2026-09-30')->assertOk()->json('weddings');
        $this->assertCount(1, $weddings);
        $this->assertSame('2026-09-26', $weddings[0]['date']);

        Carbon::setTestNow(Carbon::parse('2026-09-26 09:00:00'));
        $this->artisan('app:tick', ['--only' => 'weddings'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'weddings'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $a->id)->where('type', 'wedding')->count());
        $this->assertSame(1, UserNotification::where('user_id', $b->id)->where('type', 'wedding')->count());
    }

    // ------------------------------------------------------------ Rapports

    public function test_report_computes_tribe_scores_without_counting_missing_forms_as_zero(): void
    {
        $patriarch = $this->give($this->member('+14185550620', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $a = $this->member('+14185550621', $this->juda, 'A');
        $b = $this->member('+14185550622', $this->juda, 'B');
        $this->member('+14185550623', $this->juda, 'SansFiche');
        $this->member('+14185550624', $this->levi, 'Autre');
        Profile::query()->update(['created_at' => now()->subMonths(2)]);
        // A : meditation 20, priere 20 => 100 ; B : meditation 10 => 50. Moyenne 75 (et non 50 avec des zeros).
        SpiritualHealthForm::create(['user_id' => $a->id, 'period' => '2026-09', 'meditation' => 20, 'priere' => 20, 'submitted_at' => now(), 'locked_at' => now()]);
        SpiritualHealthForm::create(['user_id' => $b->id, 'period' => '2026-09', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);

        Sanctum::actingAs($patriarch);
        $report = $this->getJson('/api/admin/reports?scope=tribe:'.$this->juda->id.'&months=3')->assertOk();
        $this->assertSame(4, $report->json('kpis.members'));
        $this->assertEquals(75.0, $report->json('kpis.spiritual_score'));
        $this->assertEquals(50.0, $report->json('kpis.fiss_rate'));
        $this->assertSame(2, $report->json('kpis.fiss_missing'));
        $this->assertCount(3, $report->json('monthly'));
        $this->assertContains('SansFiche T', collect($report->json('missing_fiss'))->pluck('name')->all());
        $this->assertNotContains('Autre T', collect($report->json('missing_fiss'))->pluck('name')->all());

        $rows = collect($this->getJson('/api/admin/reports/members?scope=tribe:'.$this->juda->id.'&filter=fiss_missing')->assertOk()->json('members'));
        $this->assertEqualsCanonicalizing(['Patri T', 'SansFiche T'], $rows->pluck('name')->all());

        $this->getJson('/api/admin/reports?scope=tribe:'.$this->levi->id)->assertForbidden();
        $this->getJson('/api/admin/reports?scope=church')->assertForbidden();

        // Vue eglise pour le pasteur, avec comparaison par tribu.
        $pastor = $this->give($this->member('+14185550625', null, 'Pasteur'), 'pasteur_assistant');
        Sanctum::actingAs($pastor);
        $church = $this->getJson('/api/admin/reports?scope=church&months=6')->assertOk();
        $juda = collect($church->json('tribes'))->firstWhere('name', 'Juda');
        $this->assertEquals(75.0, $juda['spiritual_score']);
        $this->assertSame(2, $juda['fiss_count']);

        // Un simple membre n'a pas acces aux rapports.
        Sanctum::actingAs($a);
        $this->getJson('/api/admin/reports?scope=tribe:'.$this->juda->id)->assertForbidden();
    }

    // ------------------------------------------------------------ Audit, departements

    public function test_audit_log_is_readable_only_with_permission_and_never_writable(): void
    {
        $pastor = $this->give($this->member('+14185550630', null, 'Pasteur'), 'pasteur_assistant');
        $patriarch = $this->give($this->member('+14185550631', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $member = $this->member('+14185550632', $this->juda);
        Sanctum::actingAs($member);
        $this->postJson('/api/me/fiss', $this->fiss())->assertCreated();

        Sanctum::actingAs($pastor);
        $this->assertNotEmpty($this->getJson('/api/admin/audit')->assertOk()->json('logs'));
        $this->getJson('/api/admin/audit?action=fiss.created')->assertOk()->assertJsonPath('logs.0.action', 'fiss.created');
        Sanctum::actingAs($patriarch);
        $this->getJson('/api/admin/audit')->assertForbidden();
        $this->assertContains($this->deleteJson('/api/admin/audit/1')->status(), [404, 405]); // aucune route d'ecriture
    }

    public function test_department_leader_gets_the_former_responsable_rights_on_his_department_only(): void
    {
        $leader = $this->member('+14185550640', $this->juda, 'Chef');
        $dept = Department::create(['name' => 'Chorale', 'slug' => 'chorale', 'is_active' => true, 'tracks_rehearsal' => true]);
        $other = Department::create(['name' => 'Accueil', 'slug' => 'accueil', 'is_active' => true]);
        $singer = $this->member('+14185550641', $this->levi, 'Chanteur');
        $outsider = $this->member('+14185550642', $this->levi, 'Hors');
        Profile::where('user_id', $leader->id)->first()->departments()->attach($dept->id);
        Profile::where('user_id', $singer->id)->first()->departments()->attach($dept->id);
        Profile::where('user_id', $outsider->id)->first()->departments()->attach($other->id);

        $admin = $this->give($this->member('+14185550643', null, 'Admin'), 'super_admin');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/departments/{$dept->id}/leaders", ['user_ids' => [$outsider->id]])->assertStatus(422); // doit etre membre
        $this->putJson("/api/admin/departments/{$dept->id}/leaders", ['user_ids' => [$leader->id]])->assertOk();

        Sanctum::actingAs(User::find($leader->id));
        $ids = collect($this->getJson('/api/admin/attendance?kind=repetition')->assertOk()->json('members'))->pluck('user_id');
        $this->assertContains($singer->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
        $this->getJson('/api/me')->assertJsonFragment(['key' => 'department_leader', 'scope_name' => 'Chorale']);
        $this->postJson('/api/admin/events', ['title' => 'Répétition', 'category' => 'reunion', 'starts_at' => '2026-10-01 19:00',
            'scopes' => [['type' => 'department', 'id' => $dept->id]]])->assertCreated();
        $this->postJson('/api/admin/events', ['title' => 'Ailleurs', 'category' => 'reunion', 'starts_at' => '2026-10-01 19:00',
            'scopes' => [['type' => 'department', 'id' => $other->id]]])->assertForbidden();
    }

    public function test_member_only_sees_his_own_data(): void
    {
        $a = $this->member('+14185550650', $this->juda);
        $b = $this->member('+14185550651', $this->juda);
        Sanctum::actingAs($b);
        $formA = SpiritualHealthForm::create(['user_id' => $a->id, 'period' => '2026-09', 'meditation' => 10, 'submitted_at' => now(), 'locked_at' => now()]);
        $this->putJson("/api/me/fiss/{$formA->id}", ['meditation' => 1])->assertNotFound();
        $this->postJson("/api/me/fiss/{$formA->id}/edit-requests", ['reason' => 'Pas à moi'])->assertNotFound();
        $this->getJson('/api/admin/members')->assertForbidden();
        $this->getJson("/api/admin/members/{$a->id}")->assertForbidden();
        $this->getJson('/api/admin/validations')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/admin/reports/options')->assertForbidden();
    }

    public function test_daily_activity_rule_inactivates_then_login_reactivates_with_audit(): void
    {
        $user = $this->member('+14185550660', $this->juda);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()->subMonths(4), 'created_at' => now()->subYear()]);
        $this->artisan('app:tick', ['--only' => 'activity'])->assertSuccessful();
        $this->assertSame('inactive', User::find($user->id)->activity_status);

        // Toute requete authentifiee (reprise d'activite) le reactive.
        Sanctum::actingAs(User::find($user->id));
        $this->getJson('/api/me')->assertOk();
        $this->assertSame('active', User::find($user->id)->activity_status);
        $this->assertSame(['member.inactivated', 'member.reactivated'], AuditLog::where('member_user_id', $user->id)->orderBy('id')->pluck('action')->all());
    }
}
