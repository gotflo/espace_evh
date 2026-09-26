<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Tenue en charge et comportement propre en cas d'incident (doublons, appareils expires...). */
class RobustnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $user->id, 'first_name' => 'M', 'last_name' => substr($phone, -3), 'is_completed' => true]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function subscribe(User $user, string $endpoint): PushSubscription
    {
        $device = WebPush::newKeyPair();

        return PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16)),
        ]);
    }

    public function test_push_is_sent_to_many_devices_in_parallel_and_expired_devices_are_removed(): void
    {
        Http::fake([
            'push.example/gone/*' => Http::response('', 410),
            'push.example/fail/*' => Http::response('', 500),
            'push.example/*' => Http::response('', 201),
        ]);
        $users = collect(range(1, 60))->map(fn ($i) => $this->member('+1418555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)));
        foreach ($users as $i => $u) {
            $path = $i < 3 ? 'gone' : ($i === 3 ? 'fail' : 'ok');
            $this->subscribe($u, "https://push.example/{$path}/{$u->id}");
        }

        $count = Notifier::send($users->pluck('id'), 'announcement', 'Culte ce dimanche', 'Bienvenue à tous');

        $this->assertSame(60, $count);
        $this->assertSame(60, DB::table('user_notifications')->count());
        Http::assertSentCount(60);
        $this->assertSame(57, PushSubscription::count(), 'les 3 appareils expires (410) sont supprimes');
        $this->assertSame(56, PushSubscription::whereNotNull('last_used_at')->count(), 'un echec 500 ne bloque pas les autres');
    }

    public function test_pulse_is_light_and_changes_only_when_data_changes(): void
    {
        $member = $this->member('+14185550001');
        Sanctum::actingAs($member);

        $first = $this->getJson('/api/me/pulse')->assertOk()->json();
        $this->assertSame(0, $first['unread']);
        $this->assertArrayHasKey('announcements', $first['versions']);

        DB::enableQueryLog();
        $this->getJson('/api/me/pulse')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, $queries, 'signatures en cache : seul le compteur personnel est lu');

        Notifier::send([$member->id], 'system', 'Test');
        $this->makeExercise(['title' => 'Nouvelle vidéo', 'content' => '', 'type' => 'lecture', 'is_active' => true]);
        Carbon::setTestNow(now()->addSeconds(11));
        $after = $this->getJson('/api/me/pulse')->assertOk()->json();
        $this->assertSame(1, $after['unread']);
        $this->assertNotSame($first['versions']['exercises'], $after['versions']['exercises']);
        $this->assertSame($first['versions']['announcements'], $after['versions']['announcements']);
    }

    public function test_simultaneous_duplicate_is_answered_cleanly_not_with_a_server_error(): void
    {
        Route::middleware('api')->post('/api/test-duplicate', function () {
            DB::table('users')->insert(['phone' => '+14185550002', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('users')->insert(['phone' => '+14185550002', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->postJson('/api/test-duplicate')->assertStatus(409)->assertJson(['message' => 'Cette action a déjà été enregistrée.']);
    }

    public function test_api_never_answers_500_to_a_visitor_without_session(): void
    {
        $this->get('/api/me')->assertStatus(401);
        $this->get('/api/admin/members')->assertStatus(401);
        $this->postJson('/api/me/fiss', [])->assertStatus(401);
    }

    public function test_session_last_use_is_written_at_most_every_five_minutes(): void
    {
        $member = $this->member('+14185550003');
        $token = $member->createToken('app')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->get('/api/me', $headers)->assertOk();
        $first = DB::table('personal_access_tokens')->value('last_used_at');
        $this->assertNotNull($first);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->app['auth']->forgetGuards();
        $this->get('/api/me', $headers)->assertOk();
        $this->assertSame($first, DB::table('personal_access_tokens')->value('last_used_at'), 'pas de reecriture avant 5 min');

        Carbon::setTestNow(now()->addMinutes(4));
        $this->app['auth']->forgetGuards();
        $this->get('/api/me', $headers)->assertOk();
        $this->assertNotSame($first, DB::table('personal_access_tokens')->value('last_used_at'));
    }
}
