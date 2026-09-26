<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\Event;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Push\WebPush;
use App\Support\Holidays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarNotificationsServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00'));
        Http::preventStrayRequests(); // aucun appel reseau reel (push)
        // Ces tests portent sur des evenements precis : on retire le programme hebdomadaire
        // des cultes cree par la migration (teste a part dans ServiceScheduleTest).
        \App\Models\Event::where('remind_all', true)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, array $profile = []): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create($profile + ['user_id' => $user->id, 'first_name' => 'Membre', 'last_name' => substr($phone, -3), 'is_completed' => true]);

        return $user;
    }

    private function admin(): User
    {
        $admin = $this->member('+14185550100', ['first_name' => 'Pasteur', 'last_name' => 'Admin']);
        $role = Role::create(['key' => 'super_admin', 'name' => 'Administrateur', 'rank' => 100]);
        $admin->roles()->attach($role);

        return $admin;
    }

    // ------------------------------------------------------------ Recurrence

    public function test_weekly_event_expands_into_occurrences_until_end_date(): void
    {
        $event = $this->makeEvent([
            'title' => 'Réunion des dirigeants', 'category' => 'reunion', 'starts_at' => '2026-09-01 20:00:00',
            'recurrence' => 'weekly', 'recurrence_until' => '2026-09-22',
        ]);

        $dates = array_map(fn ($d) => $d->format('Y-m-d H:i'),
            $event->occurrencesBetween(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-31')));

        $this->assertSame(['2026-09-01 20:00', '2026-09-08 20:00', '2026-09-15 20:00', '2026-09-22 20:00'], $dates);
    }

    public function test_monthly_event_keeps_day_and_clamps_short_months(): void
    {
        $event = $this->makeEvent([
            'title' => 'Veillée', 'category' => 'priere', 'starts_at' => '2026-01-31 19:00:00',
            'recurrence' => 'monthly',
        ]);

        $dates = array_map(fn ($d) => $d->toDateString(),
            $event->occurrencesBetween(Carbon::parse('2026-02-01'), Carbon::parse('2026-04-30 23:59')));

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], $dates);
    }

    public function test_upcoming_events_cover_next_30_days_and_rsvp_is_per_occurrence(): void
    {
        $user = $this->member('+14185550111');
        Sanctum::actingAs($user);
        $event = $this->makeEvent([
            'title' => 'Culte', 'category' => 'culte', 'starts_at' => '2026-09-06 10:00:00',
            'recurrence' => 'weekly',
        ]);
        $this->makeEvent(['title' => 'Trop loin', 'category' => 'autre', 'starts_at' => '2026-12-01 10:00:00']);

        $res = $this->getJson('/api/me/events?days=30')->assertOk();
        $dates = collect($res->json('events'))->pluck('occurs_on')->all();
        $this->assertSame(['2026-09-27', '2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25'], $dates);

        $this->postJson("/api/me/events/{$event->id}/rsvp", ['response' => 'present', 'date' => '2026-10-04'])
            ->assertOk()->assertJsonPath('going_count', 1);
        $this->postJson("/api/me/events/{$event->id}/rsvp", ['response' => 'present', 'date' => '2026-10-05'])
            ->assertStatus(422); // pas une date de l'evenement

        $events = collect($this->getJson('/api/me/events')->json('events'))->keyBy('occurs_on');
        $this->assertSame('present', $events['2026-10-04']['my_response']);
        $this->assertNull($events['2026-09-27']['my_response']);
    }

    public function test_member_does_not_see_events_of_other_tribes(): void
    {
        $tribe = \App\Models\Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $other = \App\Models\Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $user = $this->member('+14185550112', ['tribe_id' => $tribe->id]);
        Sanctum::actingAs($user);
        $this->makeEvent(['title' => 'Mienne', 'category' => 'culte', 'starts_at' => '2026-09-28 10:00'], [['type' => 'tribe', 'id' => $tribe->id]]);
        $foreign = $this->makeEvent(['title' => 'Autre', 'category' => 'culte', 'starts_at' => '2026-09-28 10:00'], [['type' => 'tribe', 'id' => $other->id]]);

        $titles = collect($this->getJson('/api/me/events')->json('events'))->pluck('title')->all();
        $this->assertSame(['Mienne'], $titles);
        $this->postJson("/api/me/events/{$foreign->id}/rsvp", ['response' => 'present'])->assertForbidden();
    }

    // ------------------------------------------------------------ Calendrier

    public function test_calendar_returns_events_birthdays_holidays_and_tasks(): void
    {
        $user = $this->member('+14185550113', ['first_name' => 'Adams', 'last_name' => 'K', 'birth_day' => 25, 'birth_month' => 9]);
        $this->member('+14185550114', ['first_name' => 'Mark', 'last_name' => 'L', 'birth_day' => 25, 'birth_month' => 9]);
        Sanctum::actingAs($user);
        $this->makeEvent(['title' => 'Camp d\'été', 'category' => 'sortie', 'starts_at' => '2026-10-02 19:00']);
        $this->makeExercise(['title' => 'Lire Jean 3', 'content' => '...', 'type' => 'lecture', 'due_date' => '2026-09-29', 'is_active' => true]);

        $res = $this->getJson('/api/calendar?from=2026-08-31&to=2026-10-11')->assertOk();

        $this->assertSame(["Camp d'été"], collect($res->json('events'))->pluck('title')->all());
        $this->assertSame('Anniversaire de Adams K et Mark L', $res->json('birthdays.0.title'));
        $holidays = collect($res->json('holidays'))->pluck('title', 'date');
        $this->assertSame('Fête du Travail', $holidays['2026-09-07']);
        $this->assertSame('Journée nationale de la vérité et de la réconciliation', $holidays['2026-09-30']);
        $tasks = collect($res->json('tasks'))->pluck('title')->all();
        $this->assertContains('À rendre : Lire Jean 3', $tasks);
        $this->assertContains('Fiche FISS de septembre', $tasks);

        $this->getJson('/api/calendar?from=2026-01-01&to=2026-12-31')->assertStatus(422);
    }

    public function test_easter_dates_are_correct(): void
    {
        $this->assertSame('2026-04-05', Holidays::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', Holidays::easter(2027)->toDateString());
        $this->assertSame('2025-04-20', Holidays::easter(2025)->toDateString());
    }

    public function test_ics_feed_is_available_with_personal_token_only(): void
    {
        $user = $this->member('+14185550115');
        Sanctum::actingAs($user);
        $this->makeEvent(['title' => 'Réunion des dirigeants', 'category' => 'reunion', 'starts_at' => '2026-09-01 20:00',
            'recurrence' => 'biweekly', 'location' => 'Salle, étage 2']);

        $url = $this->getJson('/api/calendar/feed-url')->assertOk()->json('url');
        $path = parse_url($url, PHP_URL_PATH);

        $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;INTERVAL=2', $ics);
        $this->assertStringContainsString('LOCATION:Salle\, étage 2', $ics);

        $this->get('/api/calendar/feed/'.str_repeat('x', 48).'.ics')->assertNotFound();
    }

    // ------------------------------------------------------------ Notifications

    public function test_announcement_creates_notifications_that_can_be_read(): void
    {
        $admin = $this->admin();
        $member = $this->member('+14185550116');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/announcements', [
            'title' => 'Jeûne collectif', 'body' => 'Du lundi au mercredi.', 'category' => 'important', 'scopes' => [['type' => 'church']],
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->getJson('/api/me/notifications/unread-count')->assertJsonPath('count', 1);
        $n = $this->getJson('/api/me/notifications')->assertOk()->json('notifications.0');
        $this->assertSame('announcement', $n['type']);
        $this->assertSame('Jeûne collectif', $n['title']);

        $this->postJson("/api/me/notifications/{$n['id']}/read")->assertOk();
        $this->getJson('/api/me/notifications/unread-count')->assertJsonPath('count', 0);
        // L'annonce correspondante est aussi marquee lue.
        $this->assertNotNull(DB::table('announcement_user')->where('user_id', $member->id)->value('read_at'));

        // Personne ne peut lire la notification d'un autre.
        Sanctum::actingAs($admin);
        $this->postJson("/api/me/notifications/{$n['id']}/read")->assertNotFound();
    }

    public function test_new_event_and_exercise_notify_the_audience(): void
    {
        $admin = $this->admin();
        $member = $this->member('+14185550117');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/events', [
            'title' => 'Culte de la moisson', 'category' => 'culte', 'starts_at' => '2026-10-04 10:00',
            'scopes' => [['type' => 'church']], 'recurrence' => 'none',
        ])->assertCreated();
        $this->postJson('/api/admin/exercises', [
            'title' => 'Méditer Psaume 23', 'content' => '...', 'type' => 'verset', 'scopes' => [['type' => 'church']], 'due_date' => '2026-09-30',
        ])->assertCreated();

        $types = UserNotification::where('user_id', $member->id)->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['event', 'task'], $types);
        $this->assertSame(0, UserNotification::where('user_id', $admin->id)->count());
    }

    public function test_request_reply_notifies_the_member(): void
    {
        $admin = $this->admin();
        $member = $this->member('+14185550118');
        Sanctum::actingAs($member);
        $id = $this->postJson('/api/me/requests', ['category' => 'priere', 'message' => 'Priez pour moi'])->json('request.id');
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->where('type', 'request')->count());

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/requests/{$id}/reply", ['reply' => 'Nous prions pour vous.'])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('type', 'request_reply')->count());
    }

    // ------------------------------------------------------------ Push

    public function test_push_payload_is_encrypted_per_rfc8291_and_decryptable_by_the_device(): void
    {
        $device = WebPush::newKeyPair();
        $auth = random_bytes(16);
        $body = app(WebPush::class)->encrypt('{"title":"Bonjour"}', WebPush::b64uEncode($device['public_raw']), WebPush::b64uEncode($auth));

        // Decodage cote appareil (RFC 8188 / 8291).
        $salt = substr($body, 0, 16);
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $cipher = substr($body, 21 + $idLen);
        $shared = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPublic), $device['key'], 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$device['public_raw'].$asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));

        $this->assertSame("{\"title\":\"Bonjour\"}\x02", $plain);
    }

    public function test_push_subscription_is_used_and_removed_when_expired(): void
    {
        $member = $this->member('+14185550119');
        Sanctum::actingAs($member);
        $device = WebPush::newKeyPair();
        $sub = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => WebPush::b64uEncode($device['public_raw']), 'auth' => WebPush::b64uEncode(random_bytes(16))],
        ];

        $this->postJson('/api/me/push/subscribe', ['endpoint' => 'https://evil.example.com/x'] + ['keys' => $sub['keys']])->assertStatus(422);
        $this->postJson('/api/me/push/subscribe', $sub)->assertOk();
        $this->getJson('/api/me/push')->assertOk()->assertJsonPath('devices', 1);

        Http::fake(['fcm.googleapis.com/*' => Http::sequence()->push('', 201)->push('', 410)]);
        $this->postJson('/api/me/push/test')->assertOk();
        Http::assertSent(fn ($req) => $req->url() === $sub['endpoint']
            && $req->hasHeader('Content-Encoding', 'aes128gcm')
            && str_starts_with($req->header('Authorization')[0], 'vapid t='));

        $this->postJson('/api/me/push/test')->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    // ------------------------------------------------------------ Services

    public function test_member_joins_and_leaves_a_service_and_the_leader_is_notified(): void
    {
        $leader = $this->member('+14185550120');
        $dept = Department::create(['name' => 'Louange', 'slug' => 'louange', 'is_active' => true]);
        $dept->leaders()->attach($leader->id);
        $member = $this->member('+14185550121', ['first_name' => 'Jean', 'last_name' => 'Dupont']);
        Sanctum::actingAs($member);

        $louange = fn () => collect($this->getJson('/api/me/services')->assertOk()->json('services'))->firstWhere('id', $dept->id);
        $this->assertFalse($louange()['joined']);
        $this->postJson("/api/me/services/{$dept->id}/join")->assertOk();
        $this->assertTrue($louange()['joined']);
        $this->assertSame(1, $louange()['members_count']);
        $this->assertSame('Nouveau serviteur : Jean Dupont', UserNotification::where('user_id', $leader->id)->value('title'));
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('type', 'service')->count());

        // Rejoindre deux fois ne duplique rien.
        $this->postJson("/api/me/services/{$dept->id}/join")->assertOk();
        $this->assertSame(1, DB::table('department_profile')->count());

        $this->deleteJson("/api/me/services/{$dept->id}")->assertOk();
        $this->assertSame(0, DB::table('department_profile')->count());
    }

    // ------------------------------------------------------------ Nouveaux inscrits

    public function test_new_members_list_only_keeps_recent_unwelcomed_members(): void
    {
        $admin = $this->admin();
        $recent = $this->member('+14185550122', ['first_name' => 'Nouveau', 'last_name' => 'Venu']);
        $old = $this->member('+14185550123');
        Profile::where('user_id', $old->id)->update(['created_at' => now()->subDays(45)]);
        Sanctum::actingAs($admin);

        $stats = $this->getJson('/api/admin/stats')->assertOk();
        $ids = collect($stats->json('recent'))->pluck('user_id')->all();
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);

        $toWelcome = $stats->json('new_members.to_welcome');
        $this->postJson("/api/admin/members/{$recent->id}/welcome")->assertOk()
            ->assertJsonPath('counts.to_welcome', $toWelcome - 1);
        $list = collect($this->getJson('/api/admin/new-members')->json('members'))->pluck('user_id')->all();
        $this->assertNotContains($recent->id, $list);
        $all = collect($this->getJson('/api/admin/new-members?filter=all')->json('members'))->keyBy('user_id');
        $this->assertTrue($all[$recent->id]['welcomed']);
    }

    public function test_completing_a_profile_notifies_leaders(): void
    {
        $admin = $this->admin();
        $newcomer = User::create(['phone' => '+14185550124']);
        Profile::create(['user_id' => $newcomer->id, 'is_completed' => false]);
        Sanctum::actingAs($newcomer);

        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Tremblay', 'gender' => 'femme'])->assertOk();
        $this->assertSame('Nouveau membre : Marie Tremblay', UserNotification::where('user_id', $admin->id)->value('title'));

        // Une simple modification ensuite ne renotifie pas.
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Tremblay', 'gender' => 'femme'])->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->where('type', 'member')->count());
    }

    // ------------------------------------------------------------ Automatismes

    public function test_tick_sends_reminders_once(): void
    {
        $member = $this->member('+14185550125', ['first_name' => 'Adams', 'birth_day' => 25, 'birth_month' => 9]);
        $going = $this->member('+14185550126');
        $event = $this->makeEvent(['title' => 'Prière du soir', 'category' => 'priere', 'starts_at' => '2026-09-26 09:00']);
        Event::whereKey($event->id)->update(['created_at' => now()->subDays(3)]);
        // Se ferme ce soir a 23 h 59 : rappel nominatif dans les dernieres 24 h.
        $this->makeExercise(['title' => 'Lire Romains 8', 'content' => '...', 'type' => 'lecture', 'due_date' => '2026-09-25', 'is_active' => true]);

        $this->artisan('app:tick')->assertSuccessful();
        $this->artisan('app:tick')->assertSuccessful(); // idempotent

        $titles = UserNotification::where('user_id', $member->id)->pluck('title')->all();
        $this->assertContains('Demain : Prière du soir', $titles);
        $this->assertContains('Joyeux anniversaire, Adams ! 🎂', $titles);
        $this->assertContains('Rappel : « Lire Romains 8 »', $titles);
        $this->assertContains('Rappel : fiche de santé spirituelle de septembre 2026', $titles);
        $this->assertSame(1, collect($titles)->filter(fn ($t) => $t === 'Demain : Prière du soir')->count());

        // ~1 h avant : seuls les inscrits sont relances.
        DB::table('event_participations')->insert(['event_id' => $event->id, 'user_id' => $going->id, 'occurs_on' => '2026-09-26', 'response' => 'present', 'volunteer' => false]);
        Carbon::setTestNow(Carbon::parse('2026-09-26 08:00:00'));
        $this->artisan('app:tick')->assertSuccessful();
        $this->assertSame(1, UserNotification::where('title', 'Bientôt : Prière du soir')->count());
        $this->assertSame($going->id, UserNotification::where('title', 'Bientôt : Prière du soir')->value('user_id'));
    }
}
