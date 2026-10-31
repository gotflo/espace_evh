<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PulseController;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Gem;
use App\Models\MonthlyResolution;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VertumetreForm;
use App\Services\MemberMessages;
use App\Services\Messenger;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TribesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Charge pour 5 000 membres : pouls, suivis sans plafond, files d'envoi, reprises apres coupure. */
class ServerLoadFixesTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $tribe;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 11:00:00'));
        $this->seed([RolesAndPermissionsSeeder::class, TribesSeeder::class]);
        $this->tribe = Tribe::first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(array $profile = [], string $role = 'fidele'): User
    {
        $user = User::create(['phone' => '+1418555'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT), 'activity_status' => 'active', 'last_seen_at' => now()]);
        Profile::create(array_merge(['user_id' => $user->id, 'first_name' => 'Membre', 'last_name' => 'N'.str_pad((string) $this->phone, 4, '0', STR_PAD_LEFT),
            'is_completed' => true, 'tribe_id' => $this->tribe->id], $profile));
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user->fresh();
    }

    /** @return array<string, string> signatures vues par ce membre (cache de 10 s ecoule) */
    private function versions(User $user): array
    {
        Carbon::setTestNow(now()->addSeconds(11));
        Sanctum::actingAs($user);

        return $this->getJson('/api/me/pulse')->assertOk()->json('versions');
    }

    /** Une requete executee depuis enableQueryLog repond-elle a ce test ? (sans guillemets : SQLite comme MySQL) */
    private function queried(callable $test): bool
    {
        return collect(DB::getQueryLog())->contains(fn ($q) => $test(str_replace(['`', '"'], '', $q['query'])));
    }

    private function subscribe(User $user): PushSubscription
    {
        $device = WebPush::newKeyPair();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/u'.$user->id;

        return PushSubscription::create(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16))]);
    }

    // ---------------------------------------------------------------- Pouls

    public function test_a_members_action_changes_only_his_own_signature_and_the_leaders_ones(): void
    {
        $marie = $this->member();
        $jean = $this->member();
        $pastor = $this->member([], 'super_admin');
        $event = $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => now()->addDays(2)]);

        $marieBefore = $this->versions($marie);
        $jeanBefore = $this->versions($jean);
        $pastorBefore = $this->versions($pastor);

        // Marie remplit sa fiche et repond a l'evenement.
        SpiritualHealthForm::create(['user_id' => $marie->id, 'period' => '2026-10', 'meditation' => 12]);
        EventParticipation::create(['event_id' => $event->id, 'user_id' => $marie->id, 'occurs_on' => now()->addDays(2)->toDateString(), 'response' => 'present']);
        PulseController::forgetCounts();
        $marieAfter = $this->versions($marie);
        $jeanAfter = $this->versions($jean);
        $pastorAfter = $this->versions($pastor);

        $this->assertNotSame($marieBefore['mine'], $marieAfter['mine'], 'sa propre fiche se voit sur ses ecrans');
        $this->assertSame($jeanBefore['mine'], $jeanAfter['mine'], "l'action de Marie ne recharge pas les ecrans de Jean");
        $this->assertSame($jeanBefore['events'], $jeanAfter['events'], 'les reponses des autres ne rechargent pas le calendrier des membres');
        $this->assertNotSame($jeanBefore['fiss'], $jeanAfter['fiss'], 'la signature commune (responsables) suit toujours les fiches');
        $this->assertNotSame($pastorBefore['events'], $pastorAfter['events'], 'les responsables voient les reponses en direct');
        $this->assertArrayNotHasKey('event_responses', $jeanAfter);
    }

    public function test_a_department_leader_sees_the_responses_and_a_personal_event_stays_personal(): void
    {
        $leader = $this->member();
        $department = Department::create(['name' => 'Chorale', 'slug' => 'chorale']);
        $leader->ledDepartments()->attach($department->id);
        $member = $this->member();
        $other = $this->member();
        $event = $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => now()->addDays(2)]);
        $leaderBefore = $this->versions($leader);
        $memberBefore = $this->versions($member);
        $otherBefore = $this->versions($other);

        EventParticipation::create(['event_id' => $event->id, 'user_id' => $member->id, 'occurs_on' => now()->addDays(2)->toDateString(), 'response' => 'present']);
        PulseController::forgetCounts();
        $this->assertNotSame($leaderBefore['events'], $this->versions($leader)['events']);

        // Agenda personnel : seul son auteur recharge ses ecrans.
        Event::create(['title' => 'Rendez-vous perso', 'category' => 'autre', 'starts_at' => now()->addDay(), 'is_personal' => true, 'created_by' => $member->id]);
        $this->assertSame($otherBefore['events'], $this->versions($other)['events']);
        $this->assertSame($otherBefore['mine'], $this->versions($other)['mine']);
        $this->assertNotSame($memberBefore['mine'], $this->versions($member)['mine']);
    }

    public function test_a_burst_of_changes_does_not_recompute_for_everyone_but_the_author_sees_his_change(): void
    {
        $author = $this->member();
        $other = $this->member();
        Sanctum::actingAs($other);
        $before = $this->getJson('/api/me/pulse')->assertOk()->json('versions');

        $this->makeExercise(['title' => 'Nouvelle vidéo', 'content' => '', 'type' => 'lecture', 'is_active' => true]);
        PulseController::markChanged($author->id);

        // Un autre membre, juste apres : le calcul de moins de 3 s reste bon, aucune relecture des signatures.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $same = $this->getJson('/api/me/pulse')->assertOk()->json('versions');
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'max(id)')));
        DB::disableQueryLog();
        $this->assertSame($before['exercises'], $same['exercises']);

        // L'auteur voit son changement tout de suite.
        Sanctum::actingAs($author);
        $this->assertNotSame($before['exercises'], $this->getJson('/api/me/pulse')->assertOk()->json('versions.exercises'));
    }

    public function test_while_a_computation_runs_the_other_calls_get_the_last_result_without_stacking_up(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member);
        $first = $this->getJson('/api/me/pulse')->assertOk()->json('versions');

        // Signatures perimees et un calcul en cours ailleurs : reponse immediate, sans attendre ni recalculer.
        Cache::forget(PulseController::CACHE_KEY);
        $this->makeExercise(['title' => 'Autre', 'content' => '', 'type' => 'lecture', 'is_active' => true]);
        $lock = Cache::lock('pulse:compute', 10);
        $this->assertTrue($lock->get());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        $stale = $this->getJson('/api/me/pulse')->assertOk()->json('versions');
        $this->assertLessThan(1.5, microtime(true) - $started);
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'max(id)')));
        DB::disableQueryLog();
        $this->assertSame($first['exercises'], $stale['exercises']);
        $lock->release();

        $this->assertNotSame($first['exercises'], $this->getJson('/api/me/pulse')->assertOk()->json('versions.exercises'));
    }

    public function test_a_removed_presence_is_seen_without_a_full_count_on_each_pulse(): void
    {
        $pastor = $this->member([], 'super_admin');
        $member = $this->member();
        Sanctum::actingAs($pastor);
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-10-25', 'event' => 'Culte', 'kind' => 'culte', 'present_user_ids' => [$member->id]])->assertOk();
        $before = $this->versions($pastor);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->versions($pastor);
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'count(*) as aggregate from attendances')), "la grosse table n'est pas recomptee a chaque calcul");
        $this->assertTrue($this->queried(fn ($q) => str_contains($q, 'max(id)')), 'le calcul a bien eu lieu');
        DB::disableQueryLog();

        // Seule la suppression change quelque chose : rien de plus recent, un seul compte en moins.
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-10-25', 'event' => 'Culte', 'kind' => 'culte', 'present_user_ids' => []])->assertOk();
        $this->assertSame(0, Attendance::count());
        $this->assertNotSame($before['attendance'], $this->versions($pastor)['attendance']);
    }

    // ---------------------------------------------------------------- Calendrier

    public function test_calendar_counts_come_from_the_database_and_each_member_keeps_his_answer(): void
    {
        $marie = $this->member();
        $jean = $this->member();
        $paul = $this->member();
        $date = now()->addDays(2)->toDateString();
        $event = $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => now()->addDays(2)]);
        EventParticipation::create(['event_id' => $event->id, 'user_id' => $marie->id, 'occurs_on' => $date, 'response' => 'present', 'volunteer' => true]);
        EventParticipation::create(['event_id' => $event->id, 'user_id' => $jean->id, 'occurs_on' => $date, 'response' => 'present']);
        EventParticipation::create(['event_id' => $event->id, 'user_id' => $paul->id, 'occurs_on' => $date, 'response' => 'absent']);

        Sanctum::actingAs($marie);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $mine = $this->getJson("/api/calendar?from={$date}&to={$date}&only=events")->assertOk()->json('events.0');
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'from event_participations') && ! str_contains($q, 'group by') && ! str_contains($q, 'user_id = ?')),
            'aucune lecture de toutes les reponses');
        DB::disableQueryLog();
        $this->assertSame(2, $mine['going_count']);
        $this->assertSame('present', $mine['my_response']);
        $this->assertTrue($mine['my_volunteer']);

        Sanctum::actingAs($paul);
        $his = $this->getJson("/api/calendar?from={$date}&to={$date}&only=events")->assertOk()->json('events.0');
        $this->assertSame(2, $his['going_count']);
        $this->assertSame('absent', $his['my_response']);
        $this->assertFalse($his['my_volunteer']);
    }

    // ---------------------------------------------------------------- Suivis sans plafond

    /** Plus de membres qu'une page : les totaux et les manquants restent justes, la liste arrive par pages. */
    public function test_monthly_tracking_totals_are_exact_and_lists_are_paged(): void
    {
        $pastor = $this->member([], 'super_admin');
        $now = now();
        $rows = [];
        for ($i = 0; $i < 130; $i++) {
            $rows[] = $this->member()->id;
        }
        $taken = array_slice($rows, 0, 7);
        foreach ($taken as $id) {
            MonthlyResolution::create(['user_id' => $id, 'period' => '2026-10', 'meditation' => 'Méditer']);
            VertumetreForm::create(['user_id' => $id, 'period' => '2026-10', 'answers' => [], 'submitted_at' => $now]);
        }
        Sanctum::actingAs($pastor);

        $rm = $this->getJson('/api/admin/resolutions?period=2026-10')->assertOk()->json();
        $this->assertSame(131, $rm['total']);
        $this->assertSame(7, $rm['taken']);
        $this->assertCount(100, $rm['members']);
        $second = $this->getJson('/api/admin/resolutions?period=2026-10&page=2')->assertOk()->json('members');
        $this->assertCount(31, $second);
        $this->assertSame([], array_intersect(array_column($rm['members'], 'user_id'), array_column($second, 'user_id')));

        $taken2 = $this->getJson('/api/admin/resolutions?period=2026-10&filter=taken')->assertOk()->json();
        $this->assertEqualsCanonicalizing($taken, array_column($taken2['members'], 'user_id'));
        $this->assertSame(7, $taken2['taken']);
        $this->assertCount(100, $this->getJson('/api/admin/resolutions?period=2026-10&filter=missing')->assertOk()->json('members'));
        $this->assertCount(24, $this->getJson('/api/admin/resolutions?period=2026-10&filter=missing&page=2')->assertOk()->json('members'));

        $vt = $this->getJson('/api/admin/vertumetre?period=2026-10')->assertOk()->json();
        $this->assertSame(131, $vt['total']);
        $this->assertSame(7, $vt['filled']);
        $this->assertCount(100, $vt['members']);
        $this->assertSame(7, count(array_filter($this->getJson('/api/admin/vertumetre?period=2026-10&filter=filled')->assertOk()->json('members'), fn ($m) => $m['filled'])));
        $this->assertCount(24, $this->getJson('/api/admin/vertumetre?period=2026-10&filter=missing&page=2')->assertOk()->json('members'));
    }

    public function test_assignment_tracking_totals_are_exact_and_lists_are_paged(): void
    {
        $pastor = $this->member([], 'super_admin');
        $assignment = Assignment::create(['title' => 'Devoir', 'media_type' => 'video', 'media_url' => 'https://exemple.test/v', 'published_at' => now()]);
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $ids[] = $this->member()->id;
        }
        foreach (array_slice($ids, 0, 3) as $i => $id) {
            AssignmentResponse::create(['assignment_id' => $assignment->id, 'user_id' => $id, 'summary' => 'Résumé', 'actions' => 'Actions', 'submitted_at' => now()->subMinutes($i)]);
        }
        Sanctum::actingAs($pastor);

        $first = $this->getJson("/api/admin/assignments/{$assignment->id}/responses")->assertOk()->json();
        $this->assertSame(3, $first['responses_total']);
        $this->assertSame(103, $first['missing_total']);
        $this->assertCount(3, $first['responses']);
        $this->assertSame($ids[0], $first['responses'][0]['user_id'], "le plus recent d'abord");
        $this->assertSame('Résumé', $first['responses'][0]['summary']);
        $this->assertCount(100, $first['missing']);

        $more = $this->getJson("/api/admin/assignments/{$assignment->id}/responses?view=missing&page=2")->assertOk()->json();
        $this->assertCount(3, $more['missing']);
        $this->assertSame([], $more['responses']);
        $this->assertSame(103, $more['missing_total']);

        $list = collect($this->getJson('/api/admin/assignments')->assertOk()->json('assignments'))->firstWhere('id', $assignment->id);
        $this->assertSame(3, $list['responses_count']);
        $this->assertSame(106, $list['members_count']);
    }

    public function test_roll_call_keeps_rows_accepts_a_big_church_and_never_overwrites_another_sheet(): void
    {
        $pastor = $this->member([], 'super_admin');
        $marie = $this->member();
        $jean = $this->member();
        Sanctum::actingAs($pastor);
        $sheet = ['attended_on' => '2026-10-25', 'event' => 'Culte', 'kind' => 'culte'];

        // Plus de 3 000 identifiants : refus leve (les identifiants hors de l'appel sont ignores).
        $this->postJson('/api/admin/attendance', $sheet + ['present_user_ids' => array_merge([$marie->id, $jean->id], range(100000, 103500))])->assertOk();
        $this->assertSame(2, Attendance::count());
        $kept = Attendance::where('member_user_id', $marie->id)->value('id');

        // Nouvel enregistrement : ligne conservee, l'absent retire.
        $this->postJson('/api/admin/attendance', $sheet + ['present_user_ids' => [$marie->id]])->assertOk();
        $this->assertSame([$kept], Attendance::pluck('id')->all());
        $this->assertSame('present', Attendance::first()->status);

        // Repetition : le statut est mis a jour sur la meme ligne.
        $rep = ['attended_on' => '2026-10-24', 'event' => 'Répétition', 'kind' => 'repetition'];
        $this->postJson('/api/admin/attendance', $rep + ['statuses' => [$marie->id => 'present', $jean->id => 'absent']])->assertOk();
        $line = Attendance::where('event', 'Répétition')->where('member_user_id', $jean->id)->first();
        $this->postJson('/api/admin/attendance', $rep + ['statuses' => [$marie->id => 'present', $jean->id => 'retard']])->assertOk();
        $this->assertSame('retard', $line->fresh()->status);

        // Meme jour et meme nom, mais une feuille d'un autre type : refus, rien n'est ecrase.
        $this->postJson('/api/admin/attendance', ['attended_on' => '2026-10-25', 'event' => 'Culte', 'kind' => 'repetition', 'statuses' => [$marie->id => 'retard']])->assertStatus(422);
        $this->assertSame('culte', Attendance::find($kept)->kind);
        $this->assertSame('present', Attendance::find($kept)->status);
    }

    // ---------------------------------------------------------------- Courriels : file, priorite, limite

    public function test_mass_queueing_writes_by_batches_and_can_be_replayed_without_duplicates(): void
    {
        $assignment = Assignment::create(['title' => 'Devoir', 'media_type' => 'video', 'media_url' => 'https://exemple.test/v', 'published_at' => now()]);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->member(['email' => "m{$i}@exemple.test"])->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(30, MemberMessages::assignmentPublished($assignment, $ids));
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()), 'et non deux requetes par membre');
        DB::disableQueryLog();
        $this->assertSame(30, OutboundMessage::where('kind', 'assignment')->count());

        // Diffusion interrompue puis relancee : rien en double.
        $this->assertSame(0, MemberMessages::assignmentPublished($assignment, $ids));
        $this->assertSame(30, OutboundMessage::where('kind', 'assignment')->count());
        $message = OutboundMessage::where('kind', 'assignment')->first();
        $this->assertSame('Membre', $message->payload['firstName']);
        $this->assertSame(Messenger::PRIORITY_NORMAL, (int) $message->priority);
    }

    public function test_urgent_mails_go_first_and_the_limit_per_minute_holds_when_automations_send_too(): void
    {
        Mail::fake();
        config(['mail.per_minute' => 2]);
        foreach (range(1, 4) as $i) {
            Messenger::queueEmail("diffusion{$i}@exemple.test", null, 'assignment', 'Devoir', 'assignment', [], "d{$i}");
        }
        Carbon::setTestNow(now()->addSecond());
        $urgent = Messenger::queueEmail('pasteur@exemple.test', null, 'deletion_request', 'Suppression', 'deletion-request', [], 'u1');
        $this->assertSame(Messenger::PRIORITY_URGENT, (int) $urgent->fresh()->priority);

        $this->assertSame(['sent' => 2, 'failed' => 0], Messenger::flush());
        $this->assertSame('sent', $urgent->fresh()->status, 'passe devant la diffusion');
        $this->assertSame(1, OutboundMessage::where('kind', 'assignment')->where('status', 'sent')->count());

        // Meme minute : les automatismes passent aussi, la limite est deja atteinte.
        $this->assertSame(0, Messenger::flush()['sent']);
        $this->artisan('app:tick', ['--only' => 'send-outbox'])->assertSuccessful();
        $this->assertSame(2, OutboundMessage::where('status', 'sent')->count());

        // Minute suivante : la limite repart.
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(2, Messenger::flush()['sent']);
        $this->assertSame(4, OutboundMessage::where('status', 'sent')->count());
    }

    public function test_a_flush_already_running_is_not_doubled(): void
    {
        Mail::fake();
        Messenger::queueEmail('a@exemple.test', null, 'assignment', 'Devoir', 'assignment', [], 'a');
        $lock = Cache::lock('outbox:flush', 60);
        $this->assertTrue($lock->get());
        $this->assertSame(['sent' => 0, 'failed' => 0], Messenger::flush());
        $this->assertSame('pending', OutboundMessage::first()->status);
        $lock->release();
        $this->assertSame(1, Messenger::flush()['sent']);
    }

    // ---------------------------------------------------------------- Rappels : cle de la cloche, cle des courriels

    public function test_a_mail_failure_never_resends_the_bell_and_mails_resume_until_done(): void
    {
        $marie = $this->member(['email' => 'marie@exemple.test']);
        $jean = $this->member(['email' => 'jean@exemple.test']);

        // La mise en file echoue apres la cloche.
        $failing = true;
        DB::listen(function ($q) use (&$failing) {
            if ($failing && str_contains($q->sql, 'outbound_messages')) {
                throw new \RuntimeException('Base indisponible');
            }
        });
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $marie->id)->where('type', 'fiss')->count());
        $this->assertNull(DB::table('notification_dispatches')->where('key', 'mail:fiss:2026-10:last')->first());

        // Passage suivant : les courriels reprennent, la cloche ne repart pas.
        $failing = false;
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $marie->id)->where('type', 'fiss')->count());
        $this->assertEqualsCanonicalizing(['marie@exemple.test', 'jean@exemple.test'], OutboundMessage::where('kind', 'fiss_reminder')->pluck('recipient')->all());
        $this->assertNotNull(DB::table('notification_dispatches')->where('key', 'mail:fiss:2026-10:last')->first());

        // Termine : plus rien, meme si un nouveau membre arrive entre-temps.
        $this->member(['email' => 'paul@exemple.test']);
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(2, OutboundMessage::where('kind', 'fiss_reminder')->count());
        $this->assertSame(1, UserNotification::where('user_id', $jean->id)->where('type', 'fiss')->count());
    }

    public function test_a_failing_bell_leaves_no_key_and_no_partial_notification(): void
    {
        $marie = $this->member(['email' => 'marie@exemple.test']);
        $failing = true;
        DB::listen(function ($q) use (&$failing) {
            if ($failing && str_contains($q->sql, 'push_outbox')) {
                throw new \RuntimeException('Coupure');
            }
        });
        config(['services.webpush.enabled' => true]);
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('user_id', $marie->id)->where('type', 'fiss')->count(), 'notification annulee avec la cle');
        $this->assertNull(DB::table('notification_dispatches')->where('key', 'fiss:2026-10:last')->first());

        $failing = false;
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $marie->id)->where('type', 'fiss')->count());
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $marie->id)->where('type', 'fiss')->count());
    }

    public function test_a_settled_step_is_recalculated_only_the_next_hour_and_a_new_case_is_not_lost(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 11:00:00'));
        $this->member(['birth_day' => 28, 'birth_month' => 10]);
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('type', 'birthday')->count());

        $late = $this->member(['birth_day' => 28, 'birth_month' => 10]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'birth_month')), 'etape deja faite cette heure-ci');
        DB::disableQueryLog();

        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('type', 'birthday')->where('user_id', $late->id)->count());
        $this->assertSame(2, UserNotification::where('type', 'birthday')->count(), 'le premier membre ne recoit rien de plus');
    }

    // ---------------------------------------------------------------- Push : boite d'envoi, lots, curseur

    public function test_automations_only_write_to_the_outbox_and_one_flush_sends_everything(): void
    {
        Http::swap(new Factory);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        config(['services.webpush.enabled' => true]);
        $members = [$this->member(['email' => 'a@exemple.test']), $this->member(['email' => 'b@exemple.test'])];
        foreach ($members as $m) {
            $this->subscribe($m);
        }

        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        Http::assertNothingSent();
        $row = DB::table('push_outbox')->sole();
        $this->assertNull($row->sent_at);
        $this->assertNotNull($row->available_at, "part au prochain passage, sans la minute laissee a l'envoi immediat");

        // Pas besoin d'attendre : le passage du cron envoie tout, une seule fois.
        $this->artisan('app:push-outbox')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertNotNull(DB::table('push_outbox')->where('id', $row->id)->value('sent_at'));
        $this->artisan('app:push-outbox')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_many_small_sends_share_one_pass_and_urgent_ones_go_first(): void
    {
        Http::swap(new Factory);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $ids = [];
        foreach (range(1, 3) as $i) {
            $m = $this->member();
            $this->subscribe($m);
            $ids[] = $m->id;
        }
        foreach ($ids as $i => $id) {
            DB::table('push_outbox')->insert(['user_ids' => json_encode([$id]), 'urgency' => $i === 2 ? 'high' : 'normal',
                'payload' => json_encode(['title' => 'Message '.$i, 'body' => '', 'url' => '/']), 'created_at' => now(), 'available_at' => now()]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(3, Notifier::flushOutbox());
        $subscriptionReads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(str_replace(['`', '"'], '', $q['query']), 'from push_subscriptions'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $subscriptionReads, 'un seul lot');
        Http::assertSentCount(3);
        $this->assertSame('https://fcm.googleapis.com/fcm/send/u'.$ids[2], Http::recorded()[0][0]->url(), "les urgents d'abord");
        $this->assertSame(3, DB::table('push_outbox')->whereNotNull('sent_at')->count());
    }

    public function test_a_long_broadcast_keeps_its_cursor_and_its_reservation_and_resumes_without_resending(): void
    {
        Http::swap(new Factory);
        $ids = [];
        $subs = [];
        for ($i = 0; $i < 230; $i++) {
            // Peu de membres avec un appareil : le curseur compte les destinataires, pas les appareils.
            $ids[] = 1000 + $i;
        }
        foreach ([0, 150, 229] as $at) {
            $user = $this->member();
            $ids[$at] = $user->id;
            $subs[$at] = $this->subscribe($user)->endpoint;
        }
        $id = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode($ids), 'urgency' => 'normal',
            'payload' => json_encode(['title' => 'Diffusion', 'body' => '', 'url' => '/']), 'created_at' => now()->subMinutes(2)]);

        // Pendant le deuxieme lot (destinataires 100 a 199), le premier est deja enregistre et la reservation vient d'etre refraichie.
        $seen = [];
        Http::fake(function (HttpRequest $request) use ($id, &$seen, $subs) {
            $row = DB::table('push_outbox')->where('id', $id)->first();
            $seen[] = ['url' => $request->url(), 'cursor' => (int) $row->cursor, 'claimed_at' => $row->claimed_at];
            if ($request->url() === $subs[150]) {
                Carbon::setTestNow(now()->addMinutes(6)); // le processus ralentit : la reservation doit tenir
            }

            return Http::response('', 201);
        });
        $this->assertSame(1, Notifier::flushOutbox());
        $this->assertSame([0, 100, 200], array_column($seen, 'cursor'));
        $row = DB::table('push_outbox')->where('id', $id)->first();
        $this->assertSame(230, (int) $row->cursor);
        $this->assertNotNull($row->sent_at);
        $this->assertSame(['sent' => 3, 'expired' => 0, 'failed' => 0], json_decode($row->result, true));
        // Apres le lot ralenti, la reservation a ete reecrite : un autre passage ne reprend pas l'envoi.
        $this->assertGreaterThan($seen[1]['claimed_at'], $seen[2]['claimed_at']);

        // Processus coupe apres le premier lot : la reprise repart au curseur, sans renvoyer le premier appareil.
        $killed = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode($ids), 'urgency' => 'normal',
            'payload' => json_encode(['title' => 'Reprise', 'body' => '', 'url' => '/']), 'created_at' => now()->subMinutes(10),
            'cursor' => 100, 'attempts' => 1, 'claimed_at' => now()->subMinutes(6), 'result' => json_encode(['sent' => 1, 'expired' => 0, 'failed' => 0])]);
        $seen = [];
        $this->assertSame(1, Notifier::flushOutbox());
        $this->assertSame([$subs[150], $subs[229]], array_column($seen, 'url'));
        $this->assertSame(['sent' => 3, 'expired' => 0, 'failed' => 0], json_decode(DB::table('push_outbox')->where('id', $killed)->value('result'), true));
    }

    public function test_when_the_time_budget_runs_out_the_rest_leaves_on_the_next_pass_without_counting_as_a_failure(): void
    {
        Http::swap(new Factory);
        $ids = [];
        foreach (range(1, 2) as $i) {
            $user = $this->member();
            $this->subscribe($user);
            $ids[] = $user->id;
        }
        // Premier appareil dans le premier lot, second dans le deuxieme.
        $list = array_merge([$ids[0]], range(1000, 1098), [$ids[1]]);
        $id = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode($list), 'urgency' => 'normal',
            'payload' => json_encode(['title' => 'Diffusion', 'body' => '', 'url' => '/']), 'created_at' => now(), 'available_at' => now()]);
        Http::fake(function () {
            usleep(1100000); // le lot depasse le temps donne

            return Http::response('', 201);
        });

        $this->assertSame(0, Notifier::flushOutbox(1));
        $row = DB::table('push_outbox')->where('id', $id)->first();
        $this->assertNull($row->sent_at);
        $this->assertSame(100, (int) $row->cursor);
        $this->assertNull($row->claimed_at);
        $this->assertSame(0, (int) $row->attempts);
        Http::assertSentCount(1);

        $this->assertSame(1, Notifier::flushOutbox(10));
        Http::assertSentCount(2);
        $this->assertSame(['sent' => 2, 'expired' => 0, 'failed' => 0], json_decode(DB::table('push_outbox')->where('id', $id)->value('result'), true));
    }

    // ---------------------------------------------------------------- Flux iCal, GEM, annonces, nouveaux inscrits

    public function test_the_birthdays_of_the_ical_feed_are_read_once_a_day(): void
    {
        $viewer = $this->member();
        $viewer->forceFill(['calendar_token' => str_repeat('a', 48), 'approval_status' => User::APPROVED])->save();
        $this->member(['first_name' => 'Marie', 'birth_day' => 15, 'birth_month' => 11]);

        $first = $this->get('/api/calendar/feed/'.str_repeat('a', 48).'.ics')->assertOk()->getContent();
        $this->assertStringContainsString('SUMMARY:Anniversaire de Marie', $first);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $first);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $this->get('/api/calendar/feed/'.str_repeat('a', 48).'.ics')->assertOk()->getContent();
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'birth_month')));
        DB::disableQueryLog();
        $this->assertSame($first, $second);
    }

    public function test_gem_candidates_are_read_in_one_query_whatever_their_number(): void
    {
        $pastor = $this->member([], 'super_admin');
        $garde = $this->member();
        Gem::create(['name' => 'GEM A', 'tribe_id' => $this->tribe->id, 'leader_user_id' => $garde->id]);
        for ($i = 0; $i < 12; $i++) {
            $this->member();
        }
        Sanctum::actingAs($pastor);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $members = $this->getJson('/api/admin/gems/candidates?tribe_id='.$this->tribe->id)->assertOk()->json('members');
        $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'leader_user_id'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $reads);
        $this->assertSame(['GEM A'], collect($members)->firstWhere('user_id', $garde->id)['leads']);
        $this->assertSame([], collect($members)->firstWhere('user_id', $pastor->id)['leads']);
    }

    public function test_announcements_keep_their_recipients_count_without_counting_rows_again(): void
    {
        $pastor = $this->member([], 'super_admin');
        $members = [$this->member(), $this->member()];
        Sanctum::actingAs($pastor);
        $this->postJson('/api/admin/announcements', ['title' => 'Annonce', 'body' => 'Texte', 'category' => 'info', 'scope_type' => 'church'])->assertSuccessful();
        $announcement = Announcement::sole();
        $this->assertSame($announcement->recipients()->count(), (int) $announcement->recipients_count);
        $this->assertGreaterThan(0, (int) $announcement->recipients_count);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $listed = $this->getJson('/api/admin/announcements')->assertOk()->json('announcements.0.recipients_count');
        $this->assertFalse($this->queried(fn ($q) => str_contains($q, 'announcement_user')));
        DB::disableQueryLog();
        $this->assertSame((int) $announcement->recipients_count, $listed);

        // Annonce sans le nombre enregistre : compte a la demande.
        $old = Announcement::create(['title' => 'Ancienne', 'category' => 'info', 'created_by' => $pastor->id]);
        $old->syncScopes([['type' => 'church', 'id' => null]]);
        $old->recipients()->attach([$members[0]->id]);
        $this->assertSame(1, collect($this->getJson('/api/admin/announcements')->assertOk()->json('announcements'))->firstWhere('id', $old->id)['recipients_count']);
    }

    public function test_new_members_are_found_from_their_two_dates(): void
    {
        $pastor = $this->member([], 'super_admin');
        Profile::where('user_id', $pastor->id)->update(['created_at' => now()->subYear()]);
        $recent = $this->member();
        $validated = $this->member();
        Profile::where('user_id', $validated->id)->update(['created_at' => now()->subYear()]);
        $validated->forceFill(['approval_status' => User::APPROVED, 'approval_decided_at' => now()->subDays(3)])->save();
        // Inscrit recemment, mais valide il y a longtemps : l'arrivee est la validation.
        $old = $this->member();
        $old->forceFill(['approval_status' => User::APPROVED, 'approval_decided_at' => now()->subYear()])->save();
        Sanctum::actingAs($pastor);

        $listed = collect($this->getJson('/api/admin/new-members')->assertOk()->json('members'))->pluck('user_id');
        $this->assertTrue($listed->contains($recent->id));
        $this->assertTrue($listed->contains($validated->id));
        $this->assertFalse($listed->contains($old->id));
    }

    public function test_the_pulse_ignores_the_old_cache_format_right_after_an_update(): void
    {
        Cache::put(PulseController::CACHE_KEY, ['fiss' => 'ancien', 'events' => 'ancien'], 10);
        $versions = $this->versions($this->member());

        $this->assertNotSame('ancien', $versions['fiss']);
    }

    public function test_the_update_does_not_send_again_the_mails_of_reminders_already_sent(): void
    {
        DB::table('notification_dispatches')->insert([
            ['key' => 'fiss:2026-10:last', 'sent_at' => now()],
            ['key' => 'leader-report:2026-09:tribe:1:7:first', 'sent_at' => now()],
            ['key' => 'absences:2026-10-30', 'sent_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_10_31_200003_mark_sent_reminders_mails_as_queued.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Notifier::alreadyDone('mail:fiss:2026-10:last'));
        $this->assertTrue(Notifier::alreadyDone('mail:leader-report:2026-09:tribe:1:7:first'));
        $this->assertFalse(Notifier::alreadyDone('mail:absences:2026-10-30'));
    }
}
