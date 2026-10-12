<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Services\Messenger;
use App\Services\Monitoring\HealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Production sans envoi de courriel configure (MAIL_MAILER absent = « log ») : les courriels ne sont jamais
 * comptes comme envoyes, la supervision le signale et l'essai d'envoi le dit clairement.
 */
class MailNotConfiguredTest extends TestCase
{
    use RefreshDatabase;

    private function queue(): void
    {
        Messenger::queueEmail('membre@exemple.org', null, 'absence', 'Votre espace vous attend', 'membership-approved',
            ['firstName' => 'Marie', 'url' => 'https://exemple.org'], 'absence-test:'.uniqid());
    }

    private function production(string $mailer): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => $mailer, 'mail.test_to' => null]);
    }

    public function test_unsent_emails_are_marked_failed_not_sent_in_production(): void
    {
        Mail::fake();
        $this->production('log');
        $this->queue();

        $this->assertSame(['sent' => 0, 'failed' => 1], Messenger::flush());
        Mail::assertNothingSent();
        $message = OutboundMessage::sole();
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertSame(Messenger::MAIL_NOT_CONFIGURED, $message->last_error);

        $mail = HealthService::detailed()['checks']['mail'];
        $this->assertFalse($mail['ok']);
        $this->assertFalse($mail['configured']);
        $this->assertSame(1, $mail['failed_24h']);
        $this->assertStringContainsString('ne partent pas', $mail['note']);
    }

    public function test_configured_smtp_sends_normally_and_health_is_green(): void
    {
        Mail::fake();
        $this->production('smtp');
        $this->queue();

        $this->assertSame(['sent' => 1, 'failed' => 0], Messenger::flush());
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('membre@exemple.org'));
        $this->assertSame('sent', OutboundMessage::sole()->status);
        $this->assertTrue(HealthService::detailed()['checks']['mail']['ok']);
    }

    public function test_development_log_mailer_is_still_accepted(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->assertTrue(Messenger::emailDeliverable());
        $this->queue();
        $this->assertSame(1, Messenger::flush()['sent']);
    }

    public function test_mail_test_command_refuses_without_mail_configuration(): void
    {
        $this->production('log');
        $this->assertSame(1, Artisan::call('app:mail-test', ['to' => 'moi@exemple.org']));
        $this->assertStringContainsString('Aucun envoi de courriel configuré', Artisan::output());
    }
}
