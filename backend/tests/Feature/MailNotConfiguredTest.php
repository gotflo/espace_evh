<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Services\Messenger;
use App\Services\Monitoring\HealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_unsent_emails_wait_in_the_queue_then_are_marked_failed_not_sent_in_production(): void
    {
        Carbon::setTestNow('2026-10-10 13:30:00');
        Mail::fake();
        $this->production('log');
        $this->queue();

        // Jamais compte « envoye » : garde en file, sans tentative, et la supervision le signale.
        $this->assertSame(['sent' => 0, 'failed' => 0], Messenger::flush());
        Mail::assertNothingSent();
        $message = OutboundMessage::sole();
        $this->assertSame('pending', $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertNull($message->sent_at);
        $this->assertSame(Messenger::MAIL_WAITING, $message->last_error);
        $mail = HealthService::detailed()['checks']['mail'];
        $this->assertFalse($mail['ok']);
        $this->assertFalse($mail['configured']);
        $this->assertSame(1, $mail['waiting']);
        $this->assertStringContainsString('ne partent pas', $mail['note']);

        // Toujours pas configure 24 h plus tard : abandonne, un rappel perime n'a plus de sens.
        Carbon::setTestNow('2026-10-11 13:45:00');
        $this->assertSame(['sent' => 0, 'failed' => 1], Messenger::flush());
        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame(Messenger::MAIL_NOT_CONFIGURED, $message->last_error);
        $this->assertSame(1, HealthService::detailed()['checks']['mail']['failed_24h']);
        Mail::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_emails_kept_while_mail_was_not_configured_leave_once_it_is(): void
    {
        Carbon::setTestNow('2026-10-10 13:30:00');
        Mail::fake();
        $this->production('log');
        $this->queue();
        Messenger::flush();
        $this->assertSame('pending', OutboundMessage::sole()->status);

        // L'envoi est configure quelques heures plus tard : le courriel part au passage suivant.
        Carbon::setTestNow('2026-10-10 18:00:00');
        config(['mail.default' => 'smtp']);
        $this->assertSame(['sent' => 1, 'failed' => 0], Messenger::flush());
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('membre@exemple.org'));
        $message = OutboundMessage::sole();
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->last_error);
        Carbon::setTestNow();
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
