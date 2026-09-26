<?php

namespace Tests\Feature;

use App\Console\Commands\AutomationTick;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pagination, accueil apres absence, preferences push, sante, rapport mensuel automatique. */
class ScaleAndAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        Http::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first = 'Membre', string $role = 'fidele', array $pivot = []): User
    {
        $u = User::create(['phone' => $phone, 'activity_status' => 'active']);
        Profile::create(['user_id' => $u->id, 'first_name' => $first, 'last_name' => substr($phone, -4), 'is_completed' => true, 'tribe_id' => $tribe?->id]);
        $u->roles()->attach(Role::where('key', $role)->value('id'), $pivot);

        return $u;
    }

    public function test_member_list_is_paginated_and_stays_within_the_ap_tribes(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->member('+1418600'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $i % 2 ? $this->juda : $this->levi, 'Membre'.$i);
        }
        $pastor = $this->member('+14186999000', null, 'Pasteur', 'super_admin');
        Sanctum::actingAs($pastor);
        $page1 = $this->getJson('/api/admin/members')->assertOk()->json();
        $this->assertCount(50, $page1['members']);
        $this->assertSame(121, $page1['total']);
        $this->assertTrue($page1['has_more']);
        $page3 = $this->getJson('/api/admin/members?page=3')->json();
        $this->assertCount(21, $page3['members']);
        $this->assertFalse($page3['has_more']);
        $all = array_merge($page1['members'], $this->getJson('/api/admin/members?page=2')->json('members'), $page3['members']);
        $this->assertCount(121, array_unique(array_column($all, 'user_id')), 'aucun doublon ni oubli entre les pages');

        $ap = $this->member('+14186999001', null, 'AP', 'assistant_pasteur', ['scope_kind' => 'tribe', 'scope_id' => $this->juda->id]);
        Sanctum::actingAs($ap);
        $res = $this->getJson('/api/admin/members?per_page=100')->json();
        $this->assertSame(60, $res['total'], 'seulement sa tribu');
    }

    public function test_report_member_list_is_paginated_and_the_pdf_export_gets_everyone(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $this->member('+1418610'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->juda, 'Fidele'.$i);
        }
        Sanctum::actingAs($this->member('+14186999002', null, 'Pasteur', 'super_admin'));
        $page = $this->getJson('/api/admin/reports/members?scope=church&filter=all')->assertOk()->json();
        $this->assertCount(60, $page['members']);
        $this->assertSame(71, $page['total']);
        $this->assertTrue($page['has_more']);
        $this->assertCount(71, $this->getJson('/api/admin/reports/members?scope=church&filter=all&all=1')->json('members'));
        $this->assertSame(11, $this->getJson('/api/admin/reports/members?scope=church&filter=all&q=Fidele1')->json('total'));
    }

    public function test_dashboard_schedule_asks_the_calendar_for_events_only(): void
    {
        $member = $this->member('+14186999007', $this->juda);
        Profile::where('user_id', $member->id)->update(['birth_day' => 14, 'birth_month' => 10]);
        $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => now()->setTime(19, 0)]);
        Sanctum::actingAs($member);

        $full = $this->getJson('/api/calendar?from=2026-10-14&to=2026-10-14')->assertOk()->json();
        $this->assertNotEmpty($full['birthdays']);
        $light = $this->getJson('/api/calendar?from=2026-10-14&to=2026-10-14&only=events')->assertOk()->json();
        $this->assertSame([], $light['birthdays']);
        $this->assertSame(array_column($full['events'], 'title'), array_column($light['events'], 'title'));
        $this->getJson('/api/calendar?from=2026-10-14&to=2026-10-14&only=tout')->assertStatus(422);
    }

    public function test_dashboard_reads_are_grouped_in_one_call_with_the_same_answers(): void
    {
        $member = $this->member('+14186999008', $this->juda);
        $this->makeEvent(['title' => 'Culte', 'category' => 'culte', 'starts_at' => now()->addDay()->setTime(19, 0)]);
        $this->makeExercise(['title' => 'Lire Jacques 1', 'content' => '...', 'type' => 'lecture', 'is_active' => true]);
        Sanctum::actingAs($member);

        $paths = ['/dashboard/verse', '/me/fiss', '/me/events?days=30', '/me/announcements', '/me/exercises',
            '/calendar?from=2026-10-14&to=2026-10-20&only=events'];
        $home = $this->getJson('/api/me/home?'.http_build_query(['paths' => $paths]))->assertOk()->json('responses');
        foreach ($paths as $path) {
            $this->assertSame(200, $home[$path]['status'], $path);
            $this->assertEquals($this->getJson('/api'.$path)->json(), $home[$path]['body'], $path);
        }

        // Seules les lectures prevues ; erreurs de parametres rendues pour chaque bloc.
        $other = $this->getJson('/api/me/home?'.http_build_query(['paths' => ['/admin/members', '/calendar?from=x']]))->assertOk()->json('responses');
        $this->assertSame(404, $other['/admin/members']['status']);
        $this->assertSame(422, $other['/calendar?from=x']['status']);
        $this->getJson('/api/me/home')->assertStatus(422);
    }

    public function test_welcome_back_lists_what_happened_since_the_last_visit(): void
    {
        $member = $this->member('+14186999003', $this->juda);
        $member->forceFill(['last_seen_at' => now()->subDays(12)])->save();
        Sanctum::actingAs($member);
        $this->assertStringStartsWith('2026-10-02', $this->getJson('/api/me')->json('user.last_seen_at'));

        $this->makeEvent(['title' => 'Retraite', 'category' => 'autre', 'starts_at' => now()->addDays(5)->setTime(9, 0)]);
        $this->makeExercise(['title' => 'Lire Jacques 1', 'content' => '...', 'type' => 'lecture', 'is_active' => true]);

        $res = $this->getJson('/api/me/welcome-back?since='.now()->subDays(12)->toIso8601String())->assertOk()->json();
        $this->assertSame(1, $res['events']['count']);
        $this->assertSame('Retraite', $res['events']['items'][0]['title']);
        $this->assertSame(1, $res['exercises']['count']);
    }

    public function test_push_preferences_mute_a_category_but_keep_the_in_app_notification(): void
    {
        $member = $this->member('+14186999004', $this->juda);
        $device = WebPush::newKeyPair();
        PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://push.example/x', 'endpoint_hash' => hash('sha256', 'x'),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16))]);
        Sanctum::actingAs($member);
        $this->putJson('/api/me/notification-prefs', ['prefs' => ['services' => false, 'inconnu' => false]])->assertOk();
        $this->assertSame(['services' => false], $member->fresh()->notification_prefs);

        Http::fake(['push.example/*' => Http::response('', 201)]);
        Notifier::send([$member->id], 'event_reminder', 'Bientôt : Culte', null, null, ['kind' => 'service']);
        Http::assertNothingSent();
        Notifier::send([$member->id], 'event_reminder', 'Bientôt : Concert');
        Http::assertSentCount(1);
        Notifier::send([$member->id], 'request_reply', 'Réponse à votre demande');
        Http::assertSentCount(2);
        $this->assertSame(3, UserNotification::where('user_id', $member->id)->count(), 'tout reste dans la cloche');
    }

    public function test_push_is_limited_per_hour_but_urgent_messages_and_the_bell_are_not(): void
    {
        $member = $this->member('+14186999009', $this->juda);
        $device = WebPush::newKeyPair();
        PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://push.example/y', 'endpoint_hash' => hash('sha256', 'y'),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16))]);
        Http::fake(['push.example/*' => Http::response('', 201)]);

        for ($i = 1; $i <= 8; $i++) {
            Notifier::send([$member->id], 'announcement', "Annonce {$i}");
        }
        Http::assertSentCount(Notifier::PUSH_HOURLY_LIMIT);
        Notifier::send([$member->id], 'request_reply', 'Réponse urgente', null, null, [], 'high');
        Http::assertSentCount(Notifier::PUSH_HOURLY_LIMIT + 1);
        $this->assertSame(9, UserNotification::where('user_id', $member->id)->count(), 'tout reste dans la cloche');

        Carbon::setTestNow(now()->addMinutes(61));
        Notifier::send([$member->id], 'announcement', 'Annonce du lendemain');
        Http::assertSentCount(Notifier::PUSH_HOURLY_LIMIT + 2);
    }

    public function test_health_reports_the_database_and_the_automations(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'degraded', 'checks' => ['database' => ['ok' => true], 'automation' => ['ok' => false]]]);
        $this->artisan('app:tick')->assertSuccessful();
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertArrayHasKey('activity', Cache::get(AutomationTick::STATUS_KEY)['steps']);

        Carbon::setTestNow(now()->addMinutes(30));
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'degraded', 'checks' => ['automation' => ['last_run_minutes' => 30]]]);
    }

    public function test_leaders_receive_last_months_key_figures_once_at_the_start_of_the_month(): void
    {
        $patriarch = $this->member('+14186999005', $this->juda, 'Patriarche', 'patriarche', ['scope_kind' => 'tribe', 'scope_id' => $this->juda->id]);
        $m = $this->member('+14186999006', $this->juda);
        Profile::where('user_id', $m->id)->update(['created_at' => '2026-09-05']);
        SpiritualHealthForm::create(['user_id' => $m->id, 'period' => '2026-10', 'meditation' => 16, 'priere' => 16, 'jeune' => 16]);

        Carbon::setTestNow(Carbon::parse('2026-11-01 09:30'));
        $this->artisan('app:tick', ['--only' => 'monthly-report'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'monthly-report'])->assertSuccessful();

        $n = UserNotification::where('user_id', $patriarch->id)->where('type', 'report')->sole();
        $this->assertSame('Rapport de octobre 2026 · Tribu Juda', $n->title);
        $this->assertStringContainsString('vie spirituelle : 80 %', $n->body);
        $this->assertSame(0, UserNotification::where('user_id', $m->id)->where('type', 'report')->count());
    }
}
