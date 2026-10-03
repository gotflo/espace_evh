<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\Monitoring\Redactor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Agent de supervision de la plateforme (la console est un projet separe) : collecte des mesures
 * sans donnee privee, API de controle signee (secret partage, horodatage, anti-rejeu), actions
 * executees avec les regles et l'audit de la plateforme, blocage effectif des comptes.
 */
class MonitoringAgentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-de-test-partage-entre-console-et-plateforme';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['monitoring.agent_secret' => self::SECRET, 'app.expose_otp' => true, 'services.sms.driver' => 'log']);
        Http::preventStrayRequests();
    }

    /** Requete signee exactement comme la console (AgentClient). */
    private function agent(string $method, string $path, array $body = [], array $query = [], ?int $timestamp = null, string $secret = self::SECRET): TestResponse
    {
        $this->app['auth']->forgetGuards();
        ksort($query);
        $content = $method === 'GET' || $method === 'DELETE' ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();
        $actor = rawurlencode('Florent (console)');
        $full = '/api/agent/'.$path;
        $canonical = implode("\n", [(string) $timestamp, $method, $full, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $actor, hash('sha256', $content)]);
        $headers = [
            'X-Agent-Timestamp' => (string) $timestamp, 'X-Agent-Actor' => $actor,
            'X-Agent-Signature' => hash_hmac('sha256', $canonical, $secret),
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
        ];
        $uri = $full.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $content);
    }

    private function member(string $phone, string $first = 'Marie', array $roles = ['fidele']): User
    {
        $user = User::create(['phone' => $phone, 'phone_verified_at' => now(), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'is_completed' => true]);
        $user->roles()->attach(Role::whereIn('key', $roles)->pluck('id'));

        return $user;
    }

    // ------------------------------------------------------------------ Signature

    public function test_only_correctly_signed_fresh_requests_are_accepted(): void
    {
        $this->agent('GET', 'health')->assertOk()->assertJsonStructure(['status', 'checks' => ['database', 'sms', 'mail', 'automation'], 'config' => ['debug', 'sms_driver'], 'time']);

        $this->agent('GET', 'health', secret: 'un-autre-secret-de-la-meme-longueur-ou-presque!!')->assertStatus(401);
        $this->agent('GET', 'health', timestamp: time() - 600)->assertStatus(401);
        $this->getJson('/api/agent/health')->assertStatus(401);

        // Rejeu de la meme requete signee : refuse.
        $ts = time();
        $this->agent('GET', 'packages', timestamp: $ts)->assertOk();
        $this->agent('GET', 'packages', timestamp: $ts)->assertStatus(401);

        // Corps modifie apres signature : refuse.
        $user = $this->member('+14185550110');
        $ts = time() + 1;
        $actor = rawurlencode('x');
        $canonical = implode("\n", [(string) $ts, 'POST', '/api/agent/users/'.$user->id.'/block', '', $actor, hash('sha256', '{"reason":"ok ok"}')]);
        $this->call('POST', '/api/agent/users/'.$user->id.'/block', [], [], [], $this->transformHeadersToServerVars([
            'X-Agent-Timestamp' => (string) $ts, 'X-Agent-Actor' => $actor, 'X-Agent-Signature' => hash_hmac('sha256', $canonical, self::SECRET),
            'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ]), '{"reason":"autre motif"}')->assertStatus(401);
        $this->assertNull($user->fresh()->blocked_at);

        // Sans secret configure : API fermee.
        config(['monitoring.agent_secret' => '']);
        $this->agent('GET', 'health')->assertStatus(503);
    }

    public function test_health_never_reveals_secrets(): void
    {
        config(['services.sms.twilio.api_key_secret' => 'tres-secret-twilio', 'app.key' => 'base64:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345678901234567']);
        $body = $this->agent('GET', 'health')->assertOk()->getContent();
        $this->assertStringNotContainsString('tres-secret-twilio', $body);
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOP', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    // ------------------------------------------------------------------ Actions

    public function test_blocking_from_the_console_closes_sessions_and_prevents_login_until_unblocked(): void
    {
        $member = $this->member('+14185550120');
        $token = $member->createToken('mobile')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertOk();

        $this->agent('POST', 'users/'.$member->id.'/block', ['reason' => 'Usurpation signalée'])->assertOk();
        $this->assertNotNull($member->fresh()->blocked_at);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->postJson('/api/auth/request-otp', ['phone' => '+14185550120'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $log = DB::table('audit_logs')->where('action', 'member.blocked')->first();
        $this->assertSame('Florent (console)', json_decode($log->context, true)['operator']);

        $this->agent('POST', 'users/'.$member->id.'/block', ['reason' => 'encore'])->assertStatus(422);
        $this->agent('POST', 'users/'.$member->id.'/unblock')->assertOk();
        $this->assertNull($member->fresh()->blocked_at);
        $this->withoutHeader('Authorization')->postJson('/api/auth/request-otp', ['phone' => '+14185550120'])->assertOk();
    }

    public function test_deletion_reports_its_impact_and_follows_the_database_rules(): void
    {
        $member = $this->member('+14185550130');
        SpiritualHealthForm::create(['user_id' => $member->id, 'period' => '2026-09', 'submitted_at' => now()]);
        $member->createToken('mobile');

        $impact = $this->agent('GET', 'users/'.$member->id.'/impact')->assertOk();
        $this->assertTrue(collect($impact->json('deleted'))->pluck('label')->contains('Fiches de santé spirituelle (FISS)'));

        $this->agent('DELETE', 'users/'.$member->id)->assertOk();
        $this->assertNull(User::find($member->id));
        $this->assertSame(0, SpiritualHealthForm::where('user_id', $member->id)->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'member.deleted')->exists());
        $this->agent('GET', 'users/'.$member->id.'/impact')->assertStatus(404);
    }

    public function test_troubleshooting_actions_and_alerts_in_the_application(): void
    {
        $this->agent('POST', 'actions/flush_push')->assertOk()->assertJsonStructure(['message', 'details' => ['sent', 'waiting']]);
        $this->agent('POST', 'actions/sms_check')->assertOk();
        $this->agent('POST', 'actions/inconnue')->assertStatus(404);

        $owner = $this->member('+14187181876', 'Florent');
        $blocked = $this->member('+14185550140');
        $blocked->forceFill(['blocked_at' => now()])->save();
        $this->agent('POST', 'notify', ['phones' => ['+14187181876', '+14185550140', '+14185559999'], 'title' => 'Supervision : test', 'body' => 'Message'])
            ->assertOk()->assertJsonPath('recipients', 1);
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $owner->id)->where('type', 'monitor_alert')->count());
        $this->assertSame(0, DB::table('user_notifications')->where('user_id', $blocked->id)->count());
    }

    // ------------------------------------------------------------------ Collecte

    public function test_requests_are_measured_and_errors_logged_without_secrets(): void
    {
        Route::middleware('api')->get('/api/test-boom', fn () => throw new \RuntimeException('Echec pour +14185559876 avec le code 482913'));
        $member = $this->member('+14185550150');
        $token = $member->createToken('mobile')->plainTextToken;

        $ok = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertOk();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $ok->headers->get('X-Request-Id'));
        $this->assertSame(1, (int) DB::table('monitor_request_stats')->where('route', 'api/me')->where('status', 200)->sum('hits'));
        $this->assertSame(1, DB::table('monitor_user_days')->where('user_id', $member->id)->count());

        $boom = $this->getJson('/api/test-boom')->assertStatus(500);
        $event = DB::table('monitor_events')->where('type', 'exception')->first();
        $this->assertSame($boom->headers->get('X-Request-Id'), $event->request_id);
        $this->assertStringNotContainsString('482913', $event->message);
        $this->assertStringNotContainsString('5559876', $event->message);
        $this->assertSame(1, DB::table('monitor_requests')->where('reason', 'error')->count());
        // Les appels de la console sont mesures comme les autres (service « supervision »).
        $this->agent('GET', 'health')->assertOk();
        $this->assertSame('supervision', DB::table('monitor_request_stats')->where('route', 'api/agent/health')->value('service'));
    }

    public function test_browser_errors_and_server_logs_are_collected(): void
    {
        $this->postJson('/api/monitor/client-errors', ['kind' => 'error', 'message' => 'TypeError: x is undefined',
            'stack' => "TypeError\n at https://site/assets/Members-AbC123xy.js:1:2", 'path' => '/admin/membres?q=secret'])->assertOk();
        $e = DB::table('monitor_events')->where('service', 'browser')->first();
        $this->assertSame('/admin/membres', $e->route);
        $this->assertStringNotContainsString('secret', (string) $e->context);

        Log::error('Push : envoi impossible', ['outbox' => 3]);
        $this->assertTrue(DB::table('monitor_events')->where('service', 'push')->where('level', 'error')->exists());
        Log::info('[SMS simule] vers +14185550000 : code 123456');
        $this->assertFalse(DB::table('monitor_events')->where('message', 'like', '%SMS simule%')->exists());

        // Les erreurs remontees par un navigateur n'entament pas la limite de connexion des membres.
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/monitor/client-errors', ['kind' => 'error', 'message' => 'E'.$i])->assertOk();
        }
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550171'])->assertOk();
    }

    public function test_log_files_are_redacted_at_the_source(): void
    {
        $file = storage_path('logs/agent-test.log');
        file_put_contents($file, "[2026-10-03 10:00:00] local.ERROR: echec pour +14185551234 code 654321 Bearer 5|abcdefghijklmnopqrstuvwxyz0123\n");
        try {
            $r = $this->agent('GET', 'logs/files/agent-test.log', query: ['lines' => 50])->assertOk();
            $text = implode("\n", $r->json('lines'));
            foreach (['5551234', '654321', 'abcdefghijklmnop'] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
            $this->agent('GET', 'logs/files/..%2F.env')->assertStatus(404);
        } finally {
            @unlink($file);
        }
    }

    public function test_telemetry_is_pruned_after_the_safety_limit(): void
    {
        DB::table('monitor_events')->insert(['created_at' => now()->subDays(200), 'level' => 'error', 'service' => 'api', 'type' => 'log', 'message' => 'ancien']);
        \App\Services\Monitoring\Monitor::prune();
        $this->assertSame(0, DB::table('monitor_events')->where('message', 'ancien')->count());
        $this->assertStringNotContainsString('482913', Redactor::text('code 482913'));
    }
}
