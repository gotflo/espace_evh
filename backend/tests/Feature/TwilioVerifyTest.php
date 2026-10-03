<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Connexion par code SMS avec Twilio Verify (SMS_DRIVER=twilio_verify), Twilio simule. */
class TwilioVerifyTest extends TestCase
{
    use RefreshDatabase;

    // Identifiants factices construits par morceaux : ils ont le format attendu par l'application sans
    // ressembler, dans le code source, a de vrais identifiants Twilio (detection de secrets de GitHub).
    private const FAKE = '0123456789abcdef0123456789abcdef';

    private const SERVICE = 'VA'.self::FAKE;

    private const ACCOUNT = 'AC'.self::FAKE;

    private const API_KEY = 'SK'.self::FAKE;

    private const BASE = 'https://verify.twilio.com/v2/Services/'.self::SERVICE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'services.sms.driver' => 'twilio_verify',
            'services.sms.twilio.sid' => self::ACCOUNT,
            'services.sms.twilio.api_key_sid' => self::API_KEY,
            'services.sms.twilio.api_key_secret' => 'secret-de-test',
            'services.sms.twilio.token' => null,
            'services.sms.twilio.verify_service_sid' => self::SERVICE,
            'services.sms.twilio.template_sid' => 'HJ4d9c5db569029bedab5b28ab79f4cc8d',
            'services.sms.twilio.retry_delay_ms' => 0,
            // Meme si l'affichage du code a l'ecran est reste active, Twilio ne le communique jamais.
            'app.expose_otp' => true,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_member_signs_in_with_a_code_sent_and_checked_by_twilio(): void
    {
        Http::fake([
            self::BASE.'/Verifications' => Http::response(['status' => 'pending'], 201),
            self::BASE.'/VerificationCheck' => Http::sequence()
                ->push(['status' => 'pending'], 200)    // mauvais code
                ->push(['status' => 'approved'], 200),  // bon code
        ]);

        $sent = $this->postJson('/api/auth/request-otp', ['phone' => '418 555 0142', 'country' => 'CA'])->assertOk();
        $this->assertSame('+14185550142', $sent->json('phone'));
        $this->assertArrayNotHasKey('dev_code', $sent->json());
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Verifications' && $r->method() === 'POST'
            && $r['To'] === '+14185550142' && $r['Channel'] === 'sms' && $r['Locale'] === 'fr'
            && $r['TemplateSid'] === 'HJ4d9c5db569029bedab5b28ab79f4cc8d'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::API_KEY.':secret-de-test')));

        // Un second envoi immediat est refuse sans appeler Twilio (anti-spam local).
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550142'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        Http::assertSentCount(1);

        // Mauvais code : refuse, compte comme un essai ; le format est controle avant tout appel.
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550142', 'code' => '12ab'])->assertStatus(422);
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550142', 'code' => '000000'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame(1, (int) OtpCode::where('phone', '+14185550142')->value('attempts'));
        $this->assertSame(0, User::count());

        // Bon code : compte cree et connecte ; le code ne se reutilise pas.
        $login = $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550142', 'code' => '482913'])->assertOk();
        $this->assertNotEmpty($login->json('token'));
        $this->assertTrue($login->json('is_new_account'));
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/VerificationCheck' && $r['To'] === '+14185550142' && $r['Code'] === '482913');
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550142', 'code' => '482913'])->assertStatus(422);
        Http::assertSentCount(3);
    }

    public function test_an_expired_or_exhausted_code_is_simply_refused(): void
    {
        Http::fake([
            self::BASE.'/Verifications' => Http::response(['status' => 'pending'], 201),
            self::BASE.'/VerificationCheck' => Http::sequence()
                ->push(['code' => 20404, 'message' => 'not found'], 404)          // verification expiree chez Twilio
                ->push(['code' => 60202, 'message' => 'max check attempts'], 429), // trop d'essais chez Twilio
        ]);
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550143'])->assertOk();

        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550143', 'code' => '111111'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550143', 'code' => '222222'])->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Apres 5 essais, le serveur ne consulte meme plus Twilio.
        OtpCode::where('phone', '+14185550143')->update(['attempts' => 5]);
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550143', 'code' => '333333'])->assertStatus(422);
        Http::assertSentCount(3);

        // Code local expire (10 minutes) : refuse sans appel.
        OtpCode::where('phone', '+14185550143')->update(['attempts' => 0]);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550143', 'code' => '444444'])->assertStatus(422);
        Http::assertSentCount(3);
    }

    public function test_a_number_refused_by_twilio_gets_a_clear_message_and_can_retry_at_once(): void
    {
        Log::spy();
        Http::fake([self::BASE.'/Verifications' => Http::sequence()
            ->push(['code' => 60205, 'message' => 'SMS is not supported by landline phone number'], 403)
            ->push(['status' => 'pending'], 201)]);

        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550144'])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Ce numéro ne peut pas recevoir de SMS (ligne fixe). Utilisez un numéro de cellulaire.');
        // Refus definitif : pas de second essai inutile ; l'envoi rate ne declenche pas le delai anti-spam.
        Http::assertSentCount(1);
        $this->assertSame(0, OtpCode::count());
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550144'])->assertOk();
        // Le numero complet n'est jamais ecrit dans les journaux.
        Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $message, $context) => $level === 'warning' && $context['phone'] === '…0144');
    }

    public function test_a_passing_twilio_failure_is_retried_once_so_the_member_does_not_have_to(): void
    {
        // Cas observe le 2 octobre 2026 : 21608 renvoye quelques secondes apres l'approbation du profil
        // Twilio, puis envoi accepte. Meme chose pour une panne passagere (5xx) ou un reseau coupe.
        Http::fake([self::BASE.'/Verifications' => Http::sequence()
            ->push(['code' => 21608, 'message' => 'unverified'], 400)->push(['status' => 'pending'], 201)
            ->push('Service Unavailable', 503)->push(['status' => 'pending'], 201)
            ->push(['code' => 21608, 'message' => 'unverified'], 400)->push(['code' => 21608, 'message' => 'unverified'], 400)]);

        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550150'])->assertOk();
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550151'])->assertOk();
        Http::assertSentCount(4);

        // Toujours refuse apres le second essai : message neutre pour le membre (jamais « phase d'essai »),
        // erreur de configuration journalisee pour l'equipe.
        Log::spy();
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550152'])->assertStatus(503)
            ->assertJsonPath('message', "L'envoi du code par SMS est momentanément indisponible. Réessayez dans quelques minutes.");
        Http::assertSentCount(6);
        Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $message, $context) => $level === 'error' && $context['twilio_code'] === 21608);
        $this->assertSame(2, OtpCode::count());
    }

    public function test_checking_a_code_is_never_sent_twice_to_twilio(): void
    {
        // Une verification ne se rejoue pas : un bon code deja accepte serait ensuite refuse.
        Http::fake([
            self::BASE.'/Verifications' => Http::response(['status' => 'pending'], 201),
            self::BASE.'/VerificationCheck' => Http::response('Service Unavailable', 503),
        ]);
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550153'])->assertOk();
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550153', 'code' => '123456'])->assertStatus(503);
        Http::assertSentCount(2);
    }

    public function test_an_outage_or_a_bad_configuration_never_shows_a_technical_error(): void
    {
        Log::spy();
        Http::fake([
            self::BASE.'/Verifications' => Http::sequence()->push(['code' => 20003, 'message' => 'Authenticate'], 401)->push(['status' => 'pending'], 201),
            self::BASE.'/VerificationCheck' => Http::response('Bad gateway', 502),
        ]);

        // Identifiants refuses par Twilio : 503 avec un message en francais, erreur journalisee.
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550145'])->assertStatus(503)
            ->assertJsonPath('message', "L'envoi du code par SMS est momentanément indisponible. Réessayez dans quelques minutes.");
        Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $message, $context) => $level === 'error' && $context['twilio_code'] === 20003);

        // Panne pendant la verification : 503, le code n'est pas consomme ni compte comme un essai.
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550145'])->assertOk();
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550145', 'code' => '123456'])->assertStatus(503)
            ->assertJsonPath('message', 'La vérification du code est momentanément indisponible. Réessayez dans quelques minutes.');
        $this->assertSame([0, null], [(int) OtpCode::first()->attempts, OtpCode::first()->consumed_at]);

        // Variable manquante : aucun appel a Twilio, meme message.
        config(['services.sms.twilio.verify_service_sid' => null]);
        $this->postJson('/api/auth/request-otp', ['phone' => '+14185550146'])->assertStatus(503);
        Http::assertSentCount(3);
    }

    public function test_the_diagnostic_command_checks_the_credentials_without_sending_an_sms(): void
    {
        Http::fake([
            self::BASE => Http::sequence()
                ->push(['friendly_name' => 'My vasesdhonneur', 'code_length' => 6], 200)
                ->push(['friendly_name' => 'My vasesdhonneur', 'code_length' => 4], 200)
                ->push(['code' => 20003], 401),
        ]);

        $this->artisan('app:sms-check')->expectsOutputToContain('Connexion à Twilio Verify : OK')
            ->expectsOutputToContain('« My vasesdhonneur »')->expectsOutputToContain("le SMS affiche le nom de l'application")->doesntExpectOutputToContain('secret-de-test')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::BASE);
        // Longueur de code differente de celle attendue par l'application : signalee.
        $this->artisan('app:sms-check')->expectsOutputToContain("l'application attend 6 chiffres")->assertFailed();
        $this->artisan('app:sms-check')->expectsOutputToContain('Identifiants refusés')->assertFailed();
        Http::assertSentCount(3);
    }

    public function test_log_mode_still_works_without_twilio(): void
    {
        config(['services.sms.driver' => 'log']);
        Http::fake();

        $code = $this->postJson('/api/auth/request-otp', ['phone' => '+14185550147'])->assertOk()->json('dev_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185550147', 'code' => $code])->assertOk();
        Http::assertNothingSent();
    }
}
