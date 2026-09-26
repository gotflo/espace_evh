<?php

namespace Tests\Feature;

use App\Console\Commands\AutomationTick;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\TestCase;

/**
 * Pannes volontaires : base injoignable, service push en panne, envoi automatique qui
 * echoue en cours de route. L'application doit repondre proprement puis revenir d'elle-meme
 * dans un etat coherent (aucune perte, aucun doublon).
 */
class RecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $tribe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        Http::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tribe = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first = 'Membre', array $profile = []): User
    {
        $u = User::create(['phone' => $phone, 'activity_status' => 'active']);
        Profile::create(['user_id' => $u->id, 'first_name' => $first, 'last_name' => 'Test', 'is_completed' => true, 'tribe_id' => $this->tribe->id] + $profile);
        $u->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $u;
    }

    public function test_database_outage_gives_a_clear_temporary_answer_and_the_app_recovers(): void
    {
        $member = $this->member('+14187000001');
        Sanctum::actingAs($member);
        $this->getJson('/api/me/notifications')->assertOk();

        // Base injoignable (serveur MySQL arrete, redemarrage de l'hebergeur) : chaque requete SQL
        // echoue comme en production (« Connection refused »). Ni page blanche, ni detail technique.
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $level = $connection->transactionLevel(); // setPdo() remet ce compteur a zero
        $refused = function () {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        };
        $connection->setPdo($refused)->setReconnector(fn ($c) => $c->setPdo($refused));
        try {
            $res = $this->getJson('/api/me/notifications')->assertStatus(503);
            $this->assertSame('Le service est momentanément indisponible. Réessayez dans un instant.', $res->json('message'));
            $this->assertNotNull($res->headers->get('Retry-After'));
            $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
            $this->getJson('/api/health')->assertStatus(503)->assertJson(['status' => 'down', 'checks' => ['database' => ['ok' => false]]]);
        } finally {
            // Propre aux tests : le changement de connexion a remis a zero le compteur de la
            // transaction d'isolation des tests ; on le retablit (sans objet en production).
            $connection->setPdo($pdo);
            (fn () => $this->transactions = $level)->call($connection);
        }

        // La base revient : tout refonctionne sans intervention.
        $this->getJson('/api/me/notifications')->assertOk();
        $this->assertSame(true, $this->getJson('/api/health')->json('checks.database.ok'));
    }

    public function test_push_service_outage_never_blocks_the_in_app_notification(): void
    {
        $member = $this->member('+14187000002');
        $device = WebPush::newKeyPair();
        PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://push.example/panne', 'endpoint_hash' => hash('sha256', 'panne'),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16))]);

        Http::fake(['push.example/*' => Http::response('Service Unavailable', 503)]);
        $this->assertSame(1, Notifier::send([$member->id], 'announcement', 'Nouvelle annonce : retraite'));

        Http::fake(['push.example/*' => fn () => throw new ConnectionException('timeout')]);
        $this->assertSame(1, Notifier::send([$member->id], 'announcement', 'Nouvelle annonce : concert'));

        $this->assertSame(2, UserNotification::where('user_id', $member->id)->count());
        $this->assertSame(1, PushSubscription::where('user_id', $member->id)->count(), "un appareil n'est jamais retiré pour une panne passagère");
    }

    public function test_a_push_refused_for_a_moment_is_retried_once(): void
    {
        $member = $this->member('+14187000005');
        $device = WebPush::newKeyPair();
        $sub = PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://push.example/retry', 'endpoint_hash' => hash('sha256', 'retry'),
            'public_key' => WebPush::b64uEncode($device['public_raw']), 'auth_token' => WebPush::b64uEncode(random_bytes(16))]);

        // Client HTTP vierge : le setUp enregistre une reponse universelle qui masquerait celles-ci.
        Http::swap(new Factory);
        Http::fake(['push.example/*' => Http::sequence()->push('Busy', 503)->push('', 201)]);
        Notifier::send([$member->id], 'announcement', 'Nouvelle annonce : retraite');
        Http::assertSentCount(2);
        $this->assertNotNull($sub->fresh()->last_used_at, 'envoye a la seconde tentative');

        // Refus definitif (403) : pas de nouvelle tentative.
        Http::swap(new Factory);
        Http::fake(['push.example/*' => Http::response('Forbidden', 403)]);
        Notifier::send([$member->id], 'announcement', 'Nouvelle annonce : concert');
        Http::assertSentCount(1);
    }

    public function test_an_automatic_message_that_fails_is_sent_on_the_next_pass_and_only_once(): void
    {
        $member = $this->member('+14187000003', 'Ruth', ['birth_day' => 14, 'birth_month' => 10]);

        // Panne pendant l'envoi : la table des notifications est momentanement inaccessible.
        Schema::rename('user_notifications', 'user_notifications_hors_service');
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        Schema::rename('user_notifications_hors_service', 'user_notifications');
        $this->assertSame(0, UserNotification::where('user_id', $member->id)->count());

        // Passage suivant du cron : le message part, puis plus jamais le meme jour.
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('type', 'birthday')->count());
    }

    public function test_one_failing_automation_step_does_not_stop_the_others_and_is_reported(): void
    {
        $this->member('+14187000004', 'Anne', ['birth_day' => 14, 'birth_month' => 10]);
        Schema::rename('events', 'events_hors_service');
        $this->artisan('app:tick')->assertSuccessful();
        Schema::rename('events_hors_service', 'events');

        $steps = cache()->get(AutomationTick::STATUS_KEY)['steps'];
        $this->assertIsString($steps['event-reminders'], "l'étape en panne est signalée");
        $this->assertSame(1, $steps['birthdays']['count'], 'les étapes suivantes ont tourné');
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'degraded', 'checks' => ['automation' => ['failed_steps' => 1]]]);
    }
}
