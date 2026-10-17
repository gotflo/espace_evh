<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MyRequestController;
use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Event;
use App\Models\ExerciseVideoView;
use App\Models\FissEditRequest;
use App\Models\Gem;
use App\Models\MemberDeletionRequest;
use App\Models\MemberRequest;
use App\Models\MonthlyResolution;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\TribeChangeRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VertumetreEditRequest;
use App\Models\VertumetreForm;
use App\Services\ActivityService;
use App\Services\CalendarService;
use App\Services\FissService;
use App\Services\MemberDeletionService;
use App\Services\Monitoring\UserAdmin;
use App\Services\TribeChangeService;
use App\Services\VertumetreService;
use App\Services\WelcomeBackService;
use App\Support\ImageOptimizer;
use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use App\Support\ProfileCompletion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Corrections de l'audit (serveur) : cache des rapports par responsable, modification d'evenement,
 * flux iCal (fuseau, fin de mois), rappels de nuit, activite effective, assiduite, droits, decisions
 * atomiques, durees des videos, adresses courriel...
 */
class AuditFixesBackendTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'mail.test_to' => null]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pr = $this->give($this->member('+14185581000', null, 'Pasteur', 'Résident'), 'super_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe = null, string $first = 'Membre', string $last = 'Test', array $profile = []): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'last_seen_at' => now(), 'activity_status' => 'active']);
        Profile::create($profile + ['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    // ---------------------------------------------------------------- 1. Rapport « Mes tribus »

    public function test_the_mine_report_is_cached_per_leader_and_never_shows_another_ap_tribes(): void
    {
        $dan = Tribe::create(['name' => 'Dan', 'slug' => 'dan']);
        $gad = Tribe::create(['name' => 'Gad', 'slug' => 'gad']);
        $ap1 = $this->member('+14185581001', $this->juda, 'Ap1');
        $this->give($ap1, 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->give($ap1, 'assistant_pasteur', 'tribe', $this->levi->id);
        $ap2 = $this->member('+14185581002', $dan, 'Ap2');
        $this->give($ap2, 'assistant_pasteur', 'tribe', $dan->id);
        $this->give($ap2, 'assistant_pasteur', 'tribe', $gad->id);

        $this->as($ap1);
        $this->assertSame(['Juda', 'Lévi'], collect($this->getJson('/api/admin/reports?scope=mine&months=2')->assertOk()->json('tribes'))->pluck('name')->all());
        // Meme instant, meme portee « mine » : le second AP recoit SES tribus, et non le rapport en cache du premier.
        $this->as($ap2);
        $this->assertSame(['Dan', 'Gad'], collect($this->getJson('/api/admin/reports?scope=mine&months=2')->assertOk()->json('tribes'))->pluck('name')->all());
        $this->as($ap1);
        $this->assertSame(['Juda', 'Lévi'], collect($this->getJson('/api/admin/reports?scope=mine&months=2')->json('tribes'))->pluck('name')->all());
    }

    // ---------------------------------------------------------------- 2. Modification d'un evenement

    public function test_updating_an_event_keeps_its_reminders_and_clears_the_fields_sent_empty(): void
    {
        $this->as($this->pr);
        $id = $this->postJson('/api/admin/events', [
            'title' => 'Culte du dimanche', 'category' => 'culte', 'starts_at' => '2026-10-18 10:00', 'ends_at' => '2026-10-18 12:00',
            'recurrence' => 'weekly', 'recurrence_until' => '2026-12-31', 'remind_all' => true, 'location' => 'Temple', 'description' => 'Louange',
            'scopes' => [['type' => 'church', 'id' => null]],
        ])->assertCreated()->json('event.id');
        $this->assertTrue((bool) Event::find($id)->remind_all);

        // Formulaire d'administration : remind_all n'est pas envoye ; les champs vides sont envoyes vides.
        $this->putJson("/api/admin/events/{$id}", [
            'title' => 'Culte', 'category' => 'culte', 'starts_at' => '2026-10-18 10:00', 'recurrence' => 'weekly',
            'description' => '', 'location' => '', 'ends_at' => '', 'recurrence_until' => '',
        ])->assertOk();
        $event = Event::find($id);
        $this->assertTrue((bool) $event->remind_all, 'le culte garde ses rappels');
        $this->assertSame([null, null, null, null], [$event->description, $event->location, $event->ends_at, $event->recurrence_until]);
        $this->assertSame('Culte', $event->title);

        // Seuls les champs envoyes changent.
        $this->putJson("/api/admin/events/{$id}", ['title' => 'Culte de célébration'])->assertOk();
        $event = Event::find($id);
        $this->assertSame(['Culte de célébration', 'weekly', '2026-10-18 10:00', true], [$event->title, $event->recurrence, $event->starts_at->format('Y-m-d H:i'), (bool) $event->remind_all]);

        // Envoye explicitement : pris en compte.
        $this->putJson("/api/admin/events/{$id}", ['title' => 'Culte', 'remind_all' => false])->assertOk();
        $this->assertFalse((bool) Event::find($id)->remind_all);
    }

    public function test_a_new_event_image_replaces_the_old_one_only_once_stored(): void
    {
        Storage::fake('public');
        $this->as($this->pr);
        $id = $this->post('/api/admin/events', [
            'title' => 'Concert', 'category' => 'sortie', 'starts_at' => '2026-11-07 19:00',
            'scopes' => json_encode([['type' => 'church', 'id' => null]]), 'image' => UploadedFile::fake()->image('a.jpg', 400, 300),
        ], ['Accept' => 'application/json'])->assertCreated()->json('event.id');
        $old = Event::find($id)->image_path;
        Storage::disk('public')->assertExists($old);

        $fields = ['title' => 'Concert', 'category' => 'sortie', 'starts_at' => '2026-11-07 19:00'];
        if (ImageOptimizer::available()) {
            // Image illisible (refusee a l'enregistrement) : l'ancienne image est toujours la (fichier et fiche).
            $bad = UploadedFile::fake()->createWithContent('abime.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 300));
            $this->put("/api/admin/events/{$id}", $fields + ['image' => $bad], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('image');
            Storage::disk('public')->assertExists($old);
            $this->assertSame($old, Event::find($id)->image_path);
        }

        $this->put("/api/admin/events/{$id}", $fields + ['image' => UploadedFile::fake()->image('b.jpg', 400, 300)], ['Accept' => 'application/json'])->assertOk();
        $new = Event::find($id)->image_path;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    // ---------------------------------------------------------------- 3. Flux iCal

    public function test_ical_feed_uses_local_times_with_a_timezone_and_last_day_of_month_rules(): void
    {
        $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => '2026-10-18 10:00:00', 'ends_at' => '2026-10-18 12:00:00', 'recurrence' => 'weekly']);
        $this->makeEvent(['title' => 'Fin de mois', 'category' => 'priere', 'starts_at' => '2026-01-31 19:00:00', 'recurrence' => 'monthly']);
        $this->makeEvent(['title' => 'Le 30', 'category' => 'priere', 'starts_at' => '2026-01-30 19:00:00', 'recurrence' => 'monthly']);
        $this->makeEvent(['title' => 'Noël', 'category' => 'culte', 'starts_at' => '2026-12-06 10:00:00']);

        $this->as($this->pr);
        $path = parse_url($this->getJson('/api/calendar/feed-url')->assertOk()->json('url'), PHP_URL_PATH);
        $ics = $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:America/Toronto", $ics);
        $this->assertStringContainsString('RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU', $ics);
        // Heure locale (et non UTC) : 10 h reste 10 h apres le changement d'heure du 1er novembre.
        $this->assertStringContainsString('DTSTART;TZID=America/Toronto:20261018T100000', $ics);
        $this->assertStringContainsString('DTEND;TZID=America/Toronto:20261018T120000', $ics);
        $this->assertStringContainsString('DTSTART;TZID=America/Toronto:20261206T100000', $ics);
        $this->assertDoesNotMatchRegularExpression('/^DTSTART:\d{8}T\d{6}Z/m', $ics);
        // Le 31 (ou le 30) : ramene au dernier jour des mois plus courts, comme dans l'application.
        $this->assertStringContainsString('RRULE:FREQ=MONTHLY;BYMONTHDAY=-1', $ics);
        $this->assertStringContainsString('RRULE:FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1', $ics);

        // Compte bloque ou en attente de validation : comme un jeton inconnu.
        User::whereKey($this->pr->id)->update(['approval_status' => User::PENDING]);
        $this->get($path)->assertNotFound();
        User::whereKey($this->pr->id)->update(['approval_status' => User::APPROVED, 'blocked_at' => now()]);
        $this->get($path)->assertNotFound();

        // Le blocage annule le lien d'abonnement.
        $member = $this->member('+14185581003', $this->juda);
        $member->forceFill(['calendar_token' => str_repeat('a', 48)])->save();
        UserAdmin::block($member, 'Test');
        $this->assertNull($member->fresh()->calendar_token);
    }

    // ---------------------------------------------------------------- 4. Rappel « veille » jamais la nuit

    public function test_the_day_before_reminder_waits_for_the_evening(): void
    {
        $member = $this->member('+14185581004', $this->juda);
        $event = $this->makeEvent(['title' => 'Journée de jeûne', 'category' => 'priere', 'starts_at' => '2026-10-17 00:00:00', 'all_day' => true]);
        Event::whereKey($event->id)->update(['created_at' => now()->subDays(3)]);
        $count = fn () => UserNotification::where('user_id', $member->id)->where('title', 'Demain : Journée de jeûne')->count();

        foreach (['2026-10-16 00:02:00', '2026-10-16 12:00:00'] as $at) {
            Carbon::setTestNow(Carbon::parse($at));
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
            $this->assertSame(0, $count(), "rien a {$at}");
        }
        Carbon::setTestNow(Carbon::parse('2026-10-16 18:05:00'));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame(1, $count());
    }

    // ---------------------------------------------------------------- 5. Activite effective

    public function test_reminders_follow_the_status_forced_by_a_pastor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 11:00:00'));
        $forcedActive = $this->member('+14185581005', $this->juda, 'Revenue');
        $forcedActive->forceFill(['activity_status' => 'inactive', 'activity_override' => 'active'])->save();
        $forcedInactive = $this->member('+14185581006', $this->juda, 'Partie');
        $forcedInactive->forceFill(['activity_status' => 'active', 'activity_override' => 'inactive'])->save();
        $plain = $this->member('+14185581007', $this->juda, 'Simple');

        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $fiss = fn (User $u) => UserNotification::where('user_id', $u->id)->where('type', 'fiss')->count();
        $this->assertSame(1, $fiss($forcedActive), 'forcee active : rappelee');
        $this->assertSame(0, $fiss($forcedInactive), 'forcee inactive : pas de rappel');
        $this->assertSame(1, $fiss($plain));
    }

    public function test_the_daily_status_refresh_does_not_announce_a_change_hidden_by_a_forced_status(): void
    {
        $member = $this->member('+14185581008', $this->juda);
        User::whereKey($member->id)->update(['activity_override' => 'active', 'last_login_at' => now()->subYear(), 'last_seen_at' => null, 'created_at' => now()->subYear()]);

        $changes = ActivityService::refreshAll();
        $this->assertNotContains($member->id, $changes['inactivated']);
        $this->assertSame('inactive', $member->fresh()->activity_status, 'statut calcule garde a jour');
        $this->assertSame('active', $member->fresh()->activityStatus());
        $this->assertSame(0, AuditLog::where('action', 'member.inactivated')->where('member_user_id', $member->id)->count());
    }

    // ---------------------------------------------------------------- 6. Libelle du mois de la FISS

    public function test_fiss_history_labels_the_right_month_at_the_end_of_a_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00'));
        $member = $this->member('+14185581009', $this->juda);
        SpiritualHealthForm::create(['user_id' => $member->id, 'period' => '2026-09', 'meditation' => 12]);

        $this->as($this->pr);
        $this->getJson("/api/admin/members/{$member->id}/fiss")->assertOk()->assertJsonPath('forms.0.period_label', 'septembre 2026');
    }

    // ---------------------------------------------------------------- 7. Assiduite

    public function test_attendance_rate_counts_the_sessions_called_for_the_group(): void
    {
        $member = $this->member('+14185581010', $this->juda, 'Marie');
        $peer = $this->member('+14185581011', $this->juda, 'Paul');
        User::whereIn('id', [$member->id, $peer->id])->update(['created_at' => '2026-01-01 10:00:00']);
        foreach (['2026-09-20', '2026-09-27', '2026-10-04'] as $day) {
            Attendance::create(['member_user_id' => $peer->id, 'attended_on' => $day, 'event' => 'Culte', 'kind' => 'culte', 'status' => 'present']);
        }
        Attendance::create(['member_user_id' => $member->id, 'attended_on' => '2026-09-20', 'event' => 'Culte', 'kind' => 'culte', 'status' => 'present']);

        $this->as($member);
        // 1 presence sur 3 cultes appeles pour la tribu (et non 1 sur ses propres lignes = 100 %).
        $this->getJson('/api/me/overview')->assertOk()->assertJsonPath('attendance.sessions', 3)->assertJsonPath('attendance.rate', 33);
        $this->getJson('/api/me/resolutions')->assertOk()->assertJsonPath('hints.items.assiduite.value', '1/3 cultes');

        // Sans groupe : pas de taux plutot qu'un chiffre trompeur.
        $alone = $this->member('+14185581012', null, 'Seul');
        Attendance::create(['member_user_id' => $alone->id, 'attended_on' => '2026-10-04', 'event' => 'Culte', 'kind' => 'culte', 'status' => 'present']);
        $this->as($alone);
        $this->getJson('/api/me/overview')->assertOk()->assertJsonPath('attendance.rate', null);
    }

    // ---------------------------------------------------------------- 8. Bilan des RM

    public function test_a_review_is_only_asked_for_last_month_resolutions(): void
    {
        $member = $this->member('+14185581013', $this->juda);
        MonthlyResolution::create(['user_id' => $member->id, 'period' => '2026-08', 'meditation' => 'Lire un psaume']);
        $this->as($member);
        $this->getJson('/api/me/resolutions')->assertOk()->assertJsonPath('review_needed', false);
        $this->postJson('/api/me/resolutions/review', ['outcome' => ['meditation' => 'atteinte']])->assertStatus(422);

        MonthlyResolution::create(['user_id' => $member->id, 'period' => '2026-09', 'meditation' => 'Lire un psaume']);
        $this->getJson('/api/me/resolutions')->assertOk()->assertJsonPath('review_needed', true);
    }

    // ---------------------------------------------------------------- 9. Suppression d'une tribu

    public function test_deleting_a_tribe_removes_its_roles_and_audiences_and_is_traced(): void
    {
        $dan = Tribe::create(['name' => 'Dan', 'slug' => 'dan']);
        $gem = Gem::create(['name' => 'GEM Dan', 'tribe_id' => $dan->id]);
        $patriarch = $this->give($this->member('+14185581014', $dan, 'Patri'), 'patriarche', 'tribe', $dan->id);
        $garde = $this->give($this->member('+14185581015', $dan, 'Garde'), 'garde', 'gem', $gem->id);
        $kept = $this->give($this->member('+14185581016', $this->juda, 'Autre'), 'patriarche', 'tribe', $this->juda->id);
        foreach ([['tribe', $dan->id], ['gem', $gem->id], ['tribe', $this->juda->id]] as $i => [$type, $scopeId]) {
            DB::table('publication_scopes')->insert(['scopable_type' => 'announcement', 'scopable_id' => 900 + $i, 'scope_type' => $type, 'scope_id' => $scopeId]);
        }

        $this->as($this->pr);
        $this->deleteJson("/api/admin/tribes/{$dan->id}")->assertOk();
        $this->assertSame(0, DB::table('role_user')->whereIn('user_id', [$patriarch->id, $garde->id])->whereIn('scope_kind', ['tribe', 'gem'])->count());
        $this->assertSame(1, DB::table('role_user')->where('user_id', $kept->id)->where('scope_kind', 'tribe')->count());
        $this->assertSame(0, DB::table('publication_scopes')->whereIn('scope_type', ['tribe', 'gem'])->whereIn('scope_id', [$dan->id, $gem->id])
            ->where('scope_id', '!=', $this->juda->id)->count());
        $this->assertSame(1, DB::table('publication_scopes')->where('scope_type', 'tribe')->where('scope_id', $this->juda->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'tribe.deleted')->count());
    }

    public function test_organization_counts_only_completed_profiles(): void
    {
        $this->member('+14185581017', $this->juda);
        $incomplete = User::create(['phone' => '+14185581018']);
        Profile::create(['user_id' => $incomplete->id, 'first_name' => 'Pas', 'last_name' => 'Fini', 'is_completed' => false, 'tribe_id' => $this->juda->id]);
        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $chorale->members()->attach([$incomplete->profile->id, Profile::where('first_name', 'Membre')->value('id')]);

        $this->as($this->pr);
        $org = $this->getJson('/api/admin/organization')->assertOk();
        $this->assertSame(1, collect($org->json('tribes'))->firstWhere('name', 'Juda')['members_count']);
        $this->assertSame(1, collect($org->json('departments'))->firstWhere('name', 'Chorale')['members_count']);
    }

    // ---------------------------------------------------------------- 10. Tribu non demandee aux pasteurs

    public function test_profile_completion_does_not_ask_a_tribe_from_exempted_roles(): void
    {
        $pa = $this->give($this->member('+14185581019', null, 'Assistant'), 'pasteur_assistant');
        $this->assertNotContains('tribe', array_column(ProfileCompletion::for($pa->profile)['missing'], 'key'));

        $member = $this->member('+14185581020', null, 'Sans');
        $this->assertContains('tribe', array_column(ProfileCompletion::for($member->profile)['missing'], 'key'));
    }

    // ---------------------------------------------------------------- 11. Compteurs du rapport

    public function test_report_counters_and_lists_use_the_same_population(): void
    {
        $this->member('+14185581021', $this->juda, 'Active');
        $inactive = $this->member('+14185581022', $this->juda, 'Inactive');
        $inactive->forceFill(['activity_status' => 'inactive', 'activity_changed_at' => '2026-09-01 08:00:00'])->save();
        $filled = $this->member('+14185581023', $this->juda, 'Remplie');
        SpiritualHealthForm::create(['user_id' => $filled->id, 'period' => '2026-10', 'meditation' => 15]);

        $this->as($this->pr);
        $report = $this->getJson('/api/admin/reports?scope=church&months=2')->assertOk();
        $listed = $this->getJson('/api/admin/reports/members?scope=church&filter=fiss_missing')->assertOk()->json('total');
        $this->assertSame($report->json('kpis.fiss_missing'), $listed, 'meme population : membres actifs sans fiche');
        $this->assertSame(2, $listed); // la membre active et le pasteur (actifs, sans fiche)
        $this->assertSame('2026-09-01', collect($report->json('inactive_members'))->firstWhere('user_id', $inactive->id)['since']);
    }

    // ---------------------------------------------------------------- 12. Reglages au lieu de valeurs ecrites en dur

    public function test_texts_and_levels_follow_the_settings(): void
    {
        PlatformSettings::update(['absences.after_days' => 10, 'followups.request_after_hours' => 24, 'fiss.reminder_from_day' => 8], null);

        // FISS : rappel « en avance » des le jour regle (et non le 20).
        $member = $this->member('+14185581024', $this->juda, 'Marie');
        $this->as($member);
        $this->getJson('/api/me/fiss')->assertOk()->assertJsonPath('reminder.level', 'advance');

        // Demande sans reponse : le delai regle.
        MemberRequest::create(['user_id' => $member->id, 'category' => 'question', 'subject' => 'Question', 'message' => 'Bonjour', 'status' => 'nouvelle']);
        MemberRequest::query()->update(['created_at' => now()->subHours(30)]);
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $this->pr->id)->where('body', 'Sans réponse depuis plus de 24 h.')->count());

        // Absence : le nombre de jours regle.
        $patriarch = $this->give($this->member('+14185581025', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $member->forceFill(['last_seen_at' => now()->subDays(12), 'last_login_at' => now()->subDays(12)])->saveQuietly();
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $note = UserNotification::where('user_id', $patriarch->id)->where('type', 'activity')->sole();
        $this->assertSame('Marie Test : aucune connexion depuis 10 jours', $note->title);
        $this->assertStringContainsString('plus de 10 jours', $note->body);
    }

    // ---------------------------------------------------------------- 13. Decisions atomiques

    public function test_a_double_decision_on_an_edit_request_is_refused(): void
    {
        $member = $this->member('+14185581026', $this->juda);
        $form = SpiritualHealthForm::create(['user_id' => $member->id, 'period' => '2026-10', 'meditation' => 12]);
        $form->forceFill(['locked_at' => now()])->save();
        $req = FissEditRequest::create(['form_id' => $form->id, 'user_id' => $member->id, 'reason' => 'Erreur', 'status' => 'pending']);
        [$first, $second] = [FissEditRequest::find($req->id), FissEditRequest::find($req->id)];

        FissService::decide($this->pr->fresh(), $first, true, null);
        try {
            FissService::decide($this->pr->fresh(), $second, false, 'Non');
            $this->fail('La seconde decision devait etre refusee.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('type', 'fiss_request')->count());

        $vForm = VertumetreForm::create(['user_id' => $member->id, 'period' => '2026-10', 'answers' => ['patience' => 3], 'submitted_at' => now(), 'locked_at' => now()]);
        $vReq = VertumetreEditRequest::create(['form_id' => $vForm->id, 'user_id' => $member->id, 'reason' => 'Erreur', 'status' => 'pending']);
        [$first, $second] = [VertumetreEditRequest::find($vReq->id), VertumetreEditRequest::find($vReq->id)];
        VertumetreService::decide($this->pr->fresh(), $first, false, 'Non');
        try {
            VertumetreService::decide($this->pr->fresh(), $second, true, null);
            $this->fail('La seconde decision devait etre refusee.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('rejected', $vReq->fresh()->status);
        $this->assertSame(2, UserNotification::where('user_id', $member->id)->where('type', 'fiss_request')->count());
    }

    public function test_deletion_requests_are_decided_once_and_their_authors_always_hear_back(): void
    {
        $patriarch = $this->give($this->member('+14185581027', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $member = $this->member('+14185581028', $this->juda, 'Jean');
        $req = MemberDeletionRequest::create(['user_id' => $member->id, 'member_name' => 'Jean Test', 'requested_by' => $patriarch->id, 'reason' => 'Doublon', 'status' => 'pending']);
        [$first, $second] = [MemberDeletionRequest::find($req->id), MemberDeletionRequest::find($req->id)];

        MemberDeletionService::decide($this->pr->fresh(), $first, false, 'Il reste');
        try {
            MemberDeletionService::decide($this->pr->fresh(), $second, false, 'Il reste');
            $this->fail('Le second refus devait etre refuse.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, UserNotification::where('user_id', $patriarch->id)->where('title', 'Suppression refusée : Jean Test')->count());

        // Suppression directe par le PR alors qu'une demande attend : son auteur est prevenu.
        MemberDeletionRequest::create(['user_id' => $member->id, 'member_name' => 'Jean Test', 'requested_by' => $patriarch->id, 'reason' => 'Parti', 'status' => 'pending']);
        MemberDeletionService::delete($this->pr->fresh(), $member->fresh(), 'Parti');
        $this->assertNull(User::find($member->id));
        $this->assertSame(1, UserNotification::where('user_id', $patriarch->id)->where('title', 'Suppression effectuée : Jean Test')->count());
    }

    // ---------------------------------------------------------------- 14. Textes

    public function test_french_texts_are_written_properly(): void
    {
        $this->assertSame('Traitée', MyRequestController::STATUS['traitee']);

        $member = $this->member('+14185581029', $this->juda);
        $tasks = collect(CalendarService::tasks($member, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->pluck('title');
        $this->assertContains('Fiche FISS d’octobre', $tasks);

        $patriarch = $this->give($this->member('+14185581030', $this->levi, 'PLevi'), 'patriarche', 'tribe', $this->levi->id);
        $from = $this->member('+14185581031', $this->juda, 'Paul');
        $none = $this->member('+14185581032', null, 'Silas');
        foreach ([[$from, $this->juda->id], [$none, null]] as [$who, $fromTribe]) {
            $req = TribeChangeRequest::create(['user_id' => $who->id, 'from_tribe_id' => $fromTribe, 'to_tribe_id' => $this->levi->id, 'reason' => 'Déménagement', 'status' => 'pending']);
            foreach ($fromTribe ? ['from', 'to'] : ['to'] as $side) {
                $req->approvals()->create(['side' => $side, 'approver_id' => $this->pr->id, 'decision' => 'approved', 'decided_at' => now()]);
            }
        }
        TribeChangeService::completeApproved();
        $bodies = UserNotification::where('user_id', $patriarch->id)->where('type', 'member')->pluck('body')->all();
        $this->assertContains('Arrivé(e) de la tribu Juda.', $bodies);
        $this->assertContains('Changement de tribu validé.', $bodies);
    }

    // ---------------------------------------------------------------- 15. Modules desactives

    public function test_disabled_modules_are_not_proposed_to_members(): void
    {
        $member = $this->member('+14185581033', $this->juda);
        $this->makeExercise(['title' => 'Lire Romains 8', 'content' => '...', 'type' => 'lecture', 'is_active' => true, 'closes_at' => '2026-10-20 20:00:00']);
        Assignment::create(['title' => 'Devoir', 'media_type' => 'video', 'youtube_id' => 'KSvbDy7Yv7k', 'media_url' => 'https://youtu.be/KSvbDy7Yv7k', 'published_at' => now()->subDay()]);
        $since = now()->subDays(5);
        $taskKeys = fn () => collect(CalendarService::tasks($member, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->pluck('key')
            ->filter(fn ($k) => str_starts_with($k, 'task-'))->count();

        $before = WelcomeBackService::for($member, $since);
        $this->assertSame([1, 1, 1], [$before['exercises']['count'], $before['assignments']['count'], $taskKeys()]);

        PlatformSettings::update(['module.exercises' => false, 'module.assignments' => false], null);
        $after = WelcomeBackService::for($member, $since);
        $this->assertSame([0, 0, 0], [$after['exercises']['count'], $after['assignments']['count'], $taskKeys()]);
    }

    // ---------------------------------------------------------------- 16. Rendez-vous

    public function test_appointments_until_midnight_and_actions_reserved_to_the_concerned_pastor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00')); // lundi
        $wed = '2026-10-14';
        $marie = $this->member('+14185581034', $this->juda, 'Marie');
        $protocol = $this->give($this->member('+14185581035', $this->juda, 'Protocole'), 'protocole');
        $role = Role::create(['key' => 'pasteur_invite', 'name' => 'Pasteur invité', 'scope_kind' => 'none', 'rank' => 80, 'is_system' => false]);
        $role->permissions()->sync(Permission::where('key', 'appointments.host')->pluck('id'));
        $otherPastor = $this->give($this->member('+14185581036', null, 'Autre'), 'pasteur_invite');

        // Mercredi 23 h - 23 h 30 : prolongee jusqu'a minuit (6 creneaux au moins, sans depasser minuit).
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 15, 'accepting' => true,
            'weekly' => [['weekday' => 3, 'starts_at' => '23:00', 'ends_at' => '23:30']]])->assertOk();

        // Dernier creneau : fin le lendemain a 0 h (et non le jour meme a 0 h, avant le debut).
        $this->as($marie);
        $this->postJson('/api/me/appointments', ['host_id' => $this->pr->id, 'mode' => 'slot', 'starts_at' => $wed.' 23:45'])->assertCreated();
        $appointment = Appointment::where('user_id', $marie->id)->sole();
        $this->assertSame('2026-10-15 00:00', $appointment->ends_at->format('Y-m-d H:i'));
        $this->assertTrue($appointment->ends_at->gt($appointment->starts_at));

        // Un autre pasteur n'agit pas sur les rendez-vous d'un collegue ; le protocole, oui.
        $this->as($otherPastor);
        $this->postJson("/api/admin/appointments/{$appointment->id}/urgency", ['urgency' => 'urgent'])->assertForbidden();
        $this->postJson("/api/admin/appointments/{$appointment->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/appointments/{$appointment->id}/assign", ['starts_at' => $wed.' 23:00'])->assertForbidden();
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->as($protocol);
        $this->postJson("/api/admin/appointments/{$appointment->id}/urgency", ['urgency' => 'urgent'])->assertOk();

        // Blocage jusqu'a minuit (« 00:00 ») accepte, motif de 500 caracteres ; fin avant le debut refusee.
        $this->as($this->pr);
        $this->postJson('/api/admin/appointment-availability/blocks', ['host_id' => $this->pr->id, 'date' => $wed, 'starts_at' => '23:30', 'ends_at' => '22:00'])
            ->assertStatus(422);
        $reason = str_repeat('Réunion. ', 55);
        $this->postJson('/api/admin/appointment-availability/blocks', ['host_id' => $this->pr->id, 'date' => $wed, 'starts_at' => '23:30', 'ends_at' => '00:00', 'reason' => $reason])
            ->assertCreated();
        $this->assertSame(mb_substr(trim($reason), 0, 500), AppointmentBlock::sole()->reason);
        $session = collect($this->getJson('/api/admin/appointments')->assertOk()->json('sessions'))->firstWhere('date', $wed);
        $states = collect($session['slots'])->pluck('state', 'start');
        $this->assertSame('blocked', $states['23:30']);
        $this->assertSame('blocked', $states['23:45'], 'le blocage « 00:00 » couvre la fin de la soiree');
        $this->assertStringEndsWith('minuit', $session['range']);
    }

    // ---------------------------------------------------------------- 17. Bouton « Relancer »

    public function test_the_remind_count_matches_what_the_remind_button_sends(): void
    {
        $todo = $this->member('+14185581037', $this->juda, 'Todo');
        $moved = $this->member('+14185581038', $this->levi, 'Partie');
        $exercise = $this->makeExercise(['title' => 'Vidéo', 'content' => '', 'type' => 'video', 'video_id' => 'KSvbDy7Yv7k', 'video_duration' => 600,
            'requires_response' => false, 'is_active' => true, 'created_by' => $this->pr->id], [['type' => 'tribe', 'id' => $this->juda->id]]);
        // Commencee puis changement de tribu : encore dans le suivi, plus dans l'audience.
        ExerciseVideoView::create(['exercise_id' => $exercise->id, 'user_id' => $moved->id, 'segments' => [[0, 30]], 'watched_seconds' => 30, 'started_at' => now()]);

        $this->as($this->pr);
        $summary = $this->getJson("/api/admin/exercises/{$exercise->id}/tracking")->assertOk()->json('summary');
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['remindable']);
        $listed = collect($this->getJson('/api/admin/exercises')->assertOk()->json('exercises'))->firstWhere('id', $exercise->id);
        $this->assertSame(1, $listed['remindable']);
        $this->postJson("/api/admin/exercises/{$exercise->id}/remind")->assertOk()->assertJsonPath('count', 1);
        $this->assertSame(1, UserNotification::where('user_id', $todo->id)->where('type', 'task_reminder')->count());
    }

    // ---------------------------------------------------------------- 18. Recherche d'un responsable

    public function test_department_leader_search_returns_only_names_of_eligible_members(): void
    {
        $chorale = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $marie = $this->member('+14185581039', $this->juda, 'Marie', 'Tremblay');
        $this->member('+14185581040', $this->juda, 'Marc', 'Bloqué')->forceFill(['blocked_at' => now()])->save();
        $this->member('+14185581041', $this->juda, 'Maude', 'Attente')->forceFill(['approval_status' => User::PENDING])->save();
        $unfinished = User::create(['phone' => '+14185581042']);
        Profile::create(['user_id' => $unfinished->id, 'first_name' => 'Mathieu', 'last_name' => 'Incomplet', 'is_completed' => false]);

        $this->as($this->pr);
        $this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=m")->assertStatus(422);
        $found = $this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=Marie Trem")->assertOk()->json('candidates');
        $this->assertSame([['user_id' => $marie->id, 'name' => 'Marie Tremblay']], $found);
        $names = collect($this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=ma")->json('candidates'))->pluck('name')->all();
        $this->assertSame(['Marie Tremblay'], $names, 'ni bloque, ni en attente, ni profil incomplet');

        for ($i = 0; $i < 10; $i++) {
            $this->member('+1418558110'.$i, $this->levi, 'Maxime', 'N'.$i);
        }
        $this->assertCount(8, $this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=max")->json('candidates'));

        // Le compte de gestion (sans acces aux fiches des membres) peut chercher, et n'apparait jamais.
        config(['platform.manager.phone' => '+22893471703']);
        [$manager] = PlatformManager::activate('+22893471703');
        Profile::updateOrCreate(['user_id' => $manager->id], ['first_name' => 'Marius', 'last_name' => 'Gestion', 'is_completed' => true]);
        $this->as($manager);
        $this->assertSame(['Marie Tremblay'], collect($this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=mar")->assertOk()->json('candidates'))->pluck('name')->all());

        $this->as($marie);
        $this->getJson("/api/admin/departments/{$chorale->id}/candidates?q=ma")->assertForbidden();
    }

    // ---------------------------------------------------------------- 19. Rapport des responsables

    public function test_the_leader_report_form_tells_when_the_server_version_was_saved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00'));
        $patriarch = $this->give($this->member('+14185581043', $this->juda, 'Patri'), 'patriarche', 'tribe', $this->juda->id);
        $this->as($patriarch);
        $url = "/api/admin/leader-reports/form?kind=tribe&scope_id={$this->juda->id}&period=2026-09";
        $this->getJson($url)->assertOk()->assertJsonPath('updated_at', null);

        $this->putJson('/api/admin/leader-reports', ['kind' => 'tribe', 'scope_id' => $this->juda->id, 'period' => '2026-09', 'answers' => ['rencontres_nombre' => 3]])
            ->assertOk();
        $this->assertSame(now()->toIso8601String(), $this->getJson($url)->assertOk()->json('updated_at'));
    }

    // ---------------------------------------------------------------- 21. Duree des videos

    public function test_each_member_completes_a_video_with_the_duration_of_his_own_player(): void
    {
        $exercise = $this->makeExercise(['title' => 'Vidéo', 'content' => '', 'type' => 'video', 'video_id' => 'KSvbDy7Yv7k',
            'requires_response' => false, 'is_active' => true, 'created_by' => $this->pr->id]);
        $beat = function (User $who, array $payload, int $after = 15) use ($exercise) {
            Carbon::setTestNow(now()->addSeconds($after));
            $this->as($who);

            return $this->postJson("/api/me/exercises/{$exercise->id}/progress", $payload + ['rate' => 1])->assertOk();
        };

        // Duree 1 s envoyee : ni « terminee » pour lui, ni duree commune fixee pour les autres.
        $cheat = $this->member('+14185581044', $this->juda, 'Triche');
        $beat($cheat, ['segments' => [[0, 1]], 'position' => 1, 'duration' => 1])->assertJsonPath('video_completed', false);
        $this->assertNull($exercise->fresh()->video_duration);
        // Duree de 12 h : pas plausible non plus.
        $beat($cheat, ['segments' => [[1, 2]], 'position' => 2, 'duration' => 43200]);
        $this->assertNull($exercise->fresh()->video_duration);

        // Un membre honnete : sa propre duree, enregistree comme duree commune (indicative).
        $marie = $this->member('+14185581045', $this->juda, 'Marie');
        $beat($marie, ['segments' => [[0, 2]], 'position' => 2, 'duration' => 60])->assertJsonPath('video_completed', false);
        $this->assertSame(60, $exercise->fresh()->video_duration);
        $beat($marie, ['segments' => [[2, 30]], 'position' => 30, 'duration' => 60], 30);
        $beat($marie, ['segments' => [[30, 58]], 'position' => 58, 'duration' => 60], 30)->assertJsonPath('video_completed', true);

        // Une duree commune deja fixee ne decide pas pour les autres : son lecteur dit 600 s.
        $jean = $this->member('+14185581046', $this->juda, 'Jean');
        $beat($jean, ['segments' => [[0, 25]], 'position' => 25, 'duration' => 600]);
        $beat($jean, ['segments' => [[25, 58]], 'position' => 58, 'duration' => 600], 30)->assertJsonPath('video_completed', false);
        $this->assertSame(60, $exercise->fresh()->video_duration);
    }

    // ---------------------------------------------------------------- 22. Adresses courriel et champs vides

    public function test_unusual_email_addresses_are_refused(): void
    {
        $member = $this->member('+14185581047', $this->juda, 'Marie');
        $this->as($member);
        $base = ['first_name' => 'Marie', 'last_name' => 'Test', 'gender' => 'femme'];
        foreach (["\"a\r\n b\"@example.com", 'x@[127.0.0.1]', '"marie"@exemple.org'] as $email) {
            $this->putJson('/api/profile', $base + ['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email');
            $this->putJson('/api/me/email', ['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email');
        }
        $this->putJson('/api/profile', $base + ['email' => 'marie@exemple.org'])->assertOk();
        $this->putJson('/api/me/email', ['email' => 'marie.test@exemple.org'])->assertOk();
    }

    public function test_profile_fields_sent_empty_are_cleared(): void
    {
        $member = $this->member('+14185581048', $this->juda, 'Marie', 'Test', [
            'email' => 'marie@exemple.org', 'birth_day' => 15, 'birth_month' => 5, 'civility' => 'mme', 'tshirt_size' => 'M',
            'year_verse' => 'Jean 3:16', 'marital_status' => 'marie', 'wedding_day' => 10, 'wedding_month' => 6,
        ]);
        $this->as($member);
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Test', 'gender' => 'femme',
            'email' => '', 'birth_day' => '', 'birth_month' => '', 'civility' => '', 'tshirt_size' => '', 'year_verse' => '',
            'marital_status' => '', 'wedding_day' => '', 'wedding_month' => ''])->assertOk();

        $p = $member->profile()->first();
        $this->assertSame([null, null, null, null, null, null, null, null, null], [
            $p->email, $p->birth_day, $p->birth_month, $p->civility, $p->tshirt_size, $p->year_verse, $p->marital_status, $p->wedding_day, $p->wedding_month,
        ]);
        $this->assertSame('VHCTM0000', $p->matricule, 'le matricule suit la date effacee');

        // Champ absent : garde sa valeur.
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Test', 'gender' => 'femme', 'year_verse' => 'Psaume 23'])->assertOk();
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Test', 'gender' => 'femme'])->assertOk();
        $this->assertSame('Psaume 23', $member->profile()->value('year_verse'));
    }
}
