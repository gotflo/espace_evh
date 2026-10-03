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
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fiabilite et diagnostic des notifications push (production : hebergement mutualise). */
class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::swap(new Factory);
    }

    private function member(string $phone): User
    {
        $u = User::create(['phone' => $phone, 'activity_status' => 'active']);
        Profile::create(['user_id' => $u->id, 'first_name' => 'Membre', 'last_name' => substr($phone, -3), 'is_completed' => true]);
        $u->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $u;
    }

    private function subscribe(User $user, string $endpoint, string $ua = 'Mozilla/5.0 (Linux; Android 14) Chrome/140.0 Mobile'): PushSubscription
    {
        $device = WebPush::newKeyPair();

        return PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16)),
            'user_agent' => $ua,
        ]);
    }

    public function test_vapid_authorization_is_a_valid_es256_token_for_the_push_service(): void
    {
        $member = $this->member('+14187100001');
        $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/abc');
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        Notifier::send([$member->id], 'announcement', 'Nouvelle annonce : retraite');

        Http::assertSent(function (HttpRequest $r) {
            preg_match('/^vapid t=([^,]+), k=(\S+)$/', $r->header('Authorization')[0] ?? '', $m);
            $this->assertCount(3, $m, 'en-tete « vapid t=…, k=… »');
            [$h, $c, $sig] = explode('.', $m[1]);
            $claims = json_decode(WebPush::b64uDecode($c), true);
            $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64uDecode($h), true));
            $this->assertSame('https://fcm.googleapis.com', $claims['aud'], 'audience = origine du service push');
            $this->assertMatchesRegularExpression('/^(mailto:|https:\/\/)/', $claims['sub'], 'contact exige par Apple et Google');
            $this->assertLessThanOrEqual(time() + 24 * 3600, $claims['exp']);
            // Signature ES256 (r||s) verifiee avec la cle publique annoncee.
            $raw = WebPush::b64uDecode($sig);
            $int = fn (string $x) => ltrim($x, "\0") === '' ? "\0" : (ord(ltrim($x, "\0")[0]) > 0x7F ? "\0".ltrim($x, "\0") : ltrim($x, "\0"));
            $sr = $int(substr($raw, 0, 32));
            $ss = $int(substr($raw, 32));
            $der = "\x30".chr(4 + strlen($sr) + strlen($ss))."\x02".chr(strlen($sr)).$sr."\x02".chr(strlen($ss)).$ss;
            $this->assertSame(1, openssl_verify($h.'.'.$c, $der, WebPush::publicKeyFromRaw(WebPush::b64uDecode($m[2])), OPENSSL_ALGO_SHA256));
            $this->assertSame('aes128gcm', $r->header('Content-Encoding')[0] ?? null);

            return true;
        });
    }

    public function test_a_push_left_waiting_is_sent_by_the_cron_exactly_once(): void
    {
        $member = $this->member('+14187100002');
        $sub = $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/def');
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        // Envoi dont le traitement apres la reponse n'a jamais eu lieu (processus coupe par l'hebergeur).
        $id = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode([$member->id]), 'urgency' => 'normal',
            'payload' => json_encode(['title' => 'Annonce', 'body' => '', 'url' => '/']), 'created_at' => now()->subMinutes(2)]);
        $recent = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode([$member->id]), 'urgency' => 'normal',
            'payload' => json_encode(['title' => 'Toute recente', 'body' => '', 'url' => '/']), 'created_at' => now()]);

        $this->artisan('app:push-outbox')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertNotNull(DB::table('push_outbox')->where('id', $id)->value('sent_at'));
        $this->assertNull(DB::table('push_outbox')->where('id', $recent)->value('sent_at'), "l'envoi immediat a encore sa chance");
        $this->assertNotNull($sub->fresh()->last_used_at);

        // Envoi immediat et cron qui se croisent : un seul envoi.
        $this->assertFalse(Notifier::deliver($id));
        $this->assertTrue(Notifier::deliver($recent));
        $this->assertFalse(Notifier::deliver($recent));
        $this->artisan('app:push-outbox')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_automations_also_flush_the_outbox_if_the_minute_cron_does_not_run(): void
    {
        $member = $this->member('+14187100008');
        $this->subscribe($member, 'https://web.push.apple.com/abc', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7) Safari/604.1');
        Http::fake(['web.push.apple.com/*' => Http::response('', 201)]);
        $id = DB::table('push_outbox')->insertGetId(['user_ids' => json_encode([$member->id]), 'urgency' => 'high',
            'payload' => json_encode(['title' => 'Test', 'body' => '', 'url' => '/']), 'created_at' => now()->subMinutes(3)]);

        $this->artisan('app:tick', ['--only' => 'push-outbox'])->assertSuccessful();
        $this->assertNotNull(DB::table('push_outbox')->where('id', $id)->value('sent_at'));
        Http::assertSentCount(1);
        $this->artisan('app:push-check')->expectsOutputToContain('Dernier rattrapage (cron chaque minute, automatismes en secours) : il y a 0 min')->assertSuccessful();
    }

    public function test_resubscribing_the_same_phone_removes_its_old_addresses_only(): void
    {
        $member = $this->member('+14187100009');
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7) Safari/604.1';
        $this->subscribe($member, 'https://web.push.apple.com/ancienne-1', $iphone);
        $this->subscribe($member, 'https://web.push.apple.com/ancienne-2', $iphone);
        $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/ordinateur', 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0');
        $other = $this->member('+14187100010');
        $this->subscribe($other, 'https://web.push.apple.com/autre-membre', $iphone);

        Sanctum::actingAs($member);
        $device = WebPush::newKeyPair();
        $this->withHeader('User-Agent', $iphone)->postJson('/api/me/push/subscribe', ['endpoint' => 'https://web.push.apple.com/nouvelle',
            'keys' => ['p256dh' => WebPush::b64uEncode($device['public_raw']), 'auth' => WebPush::b64uEncode(random_bytes(16))]])->assertOk();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/ordinateur', 'https://web.push.apple.com/nouvelle'],
            PushSubscription::where('user_id', $member->id)->orderBy('endpoint')->pluck('endpoint')->all());
        $this->assertSame(1, PushSubscription::where('user_id', $other->id)->count(), 'les appareils des autres membres ne changent pas');
    }

    public function test_the_phone_acknowledges_what_it_received_and_displayed(): void
    {
        $member = $this->member('+14187100011');
        $sub = $this->subscribe($member, 'https://web.push.apple.com/recu', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7) Safari/604.1');

        // Sans session : le service worker s'identifie par son adresse d'abonnement.
        $this->postJson('/api/push/receipt', ['endpoint' => $sub->endpoint, 'status' => 'error', 'error' => 'TypeError: renotify'])->assertNoContent();
        $this->assertSame('TypeError: renotify', $sub->fresh()->last_error);
        $this->postJson('/api/push/receipt', ['endpoint' => $sub->endpoint, 'status' => 'shown'])->assertNoContent();
        $this->assertNull($sub->fresh()->last_error);
        $this->assertNotNull($sub->fresh()->last_received_at);

        $this->postJson('/api/push/receipt', ['endpoint' => 'https://web.push.apple.com/inconnue', 'status' => 'shown'])->assertNoContent();
        $this->postJson('/api/push/receipt', ['endpoint' => 'http://ailleurs', 'status' => 'autre'])->assertStatus(422);
        $this->artisan('app:push-check')->expectsOutputToContain('notification affichée')->assertSuccessful();
    }

    public function test_normal_sending_goes_through_the_outbox_and_is_marked_sent(): void
    {
        $member = $this->member('+14187100003');
        $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/ghi');
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        Notifier::send([$member->id], 'announcement', 'Culte ce soir');

        $row = DB::table('push_outbox')->sole();
        $this->assertNotNull($row->sent_at);
        $this->assertSame(['sent' => 1, 'expired' => 0, 'failed' => 0], json_decode($row->result, true));
        $this->artisan('app:push-outbox')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_test_button_sends_now_and_explains_each_device(): void
    {
        $member = $this->member('+14187100004');
        $phone = $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/phone');
        $this->subscribe($member, 'https://web.push.apple.com/old', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1');
        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'web.push.apple.com/*' => Http::response('{"reason":"BadJwtToken"}', 403),
        ]);
        Sanctum::actingAs($member);

        $res = $this->postJson('/api/me/push/test', ['endpoint' => $phone->endpoint])->assertOk()->json();
        $this->assertStringStartsWith('Envoyée à 1 appareil(s) sur 2', $res['message']);
        $android = collect($res['devices'])->firstWhere('this_device', true);
        $this->assertSame('Android · Chrome', $android['device']);
        $this->assertTrue($android['ok']);
        $iphone = collect($res['devices'])->firstWhere('ok', false);
        $this->assertSame('iPhone / iPad · Safari', $iphone['device']);
        $this->assertStringContainsString('la clé du serveur ne correspond plus', $iphone['explanation']);
        // Un envoi de test n'apparait pas dans la page Notifications du membre.
        $this->assertSame(0, \App\Models\UserNotification::where('user_id', $member->id)->count());

        // Sans appareil abonne : message clair au lieu d'un faux « envoye ».
        Sanctum::actingAs($this->member('+14187100005'));
        $this->postJson('/api/me/push/test')->assertStatus(422)->assertJsonPath('message',
            'Aucun appareil n\'est abonné pour ce compte. Activez les notifications sur cet appareil, puis réessayez.');
    }

    public function test_delayed_test_goes_through_the_outbox_without_limits(): void
    {
        $member = $this->member('+14187100007');
        $this->subscribe($member, 'https://web.push.apple.com/iphone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1');
        Http::fake(['web.push.apple.com/*' => Http::response('', 201)]);
        Sanctum::actingAs($member);

        $this->postJson('/api/me/push/test', ['delayed' => true])->assertOk()
            ->assertJsonPath('message', 'Revenez à l\'écran d\'accueil de votre téléphone maintenant : la notification arrive dans 10 secondes.');
        $row = DB::table('push_outbox')->sole();
        $this->assertSame('high', $row->urgency);
        $this->assertNotNull($row->sent_at);
        Http::assertSentCount(1);
    }

    public function test_health_reports_push_readiness_and_waiting_messages(): void
    {
        $this->artisan('app:tick')->assertSuccessful();
        $push = $this->getJson('/api/health')->assertOk()->json('checks.push');
        $this->assertTrue($push['ok']);
        $this->assertSame(0, $push['waiting']);

        DB::table('push_outbox')->insert(['user_ids' => '[1]', 'payload' => '{}', 'urgency' => 'normal', 'created_at' => now()->subMinutes(10)]);
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'degraded', 'checks' => ['push' => ['ok' => false, 'waiting' => 1]]]);
    }

    public function test_diagnostic_command_reports_and_sends_a_test(): void
    {
        $member = $this->member('+14187100006');
        $this->subscribe($member, 'https://fcm.googleapis.com/fcm/send/jkl');
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        $this->artisan('app:push-check')->expectsOutputToContain('Chiffrement et signature : OK')->assertSuccessful();
        $this->artisan('app:push-check', ['membre' => '+14187100006'])->expectsOutputToContain('Envoyés : 1')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_test_notifications_already_listed_are_removed(): void
    {
        $member = $this->member('+14187100009');
        \App\Models\UserNotification::create(['user_id' => $member->id, 'type' => 'system', 'title' => 'Notification de test', 'body' => 'Si vous lisez ceci...']);
        \App\Models\UserNotification::create(['user_id' => $member->id, 'type' => 'announcement', 'title' => 'Culte de dimanche']);
        \App\Models\UserNotification::create(['user_id' => $member->id, 'type' => 'system', 'title' => 'Bienvenue']);

        (require database_path('migrations/2026_10_03_100010_remove_test_notifications.php'))->up();

        $this->assertSame(['Culte de dimanche', 'Bienvenue'], \App\Models\UserNotification::orderBy('id')->pluck('title')->all());
    }
}
