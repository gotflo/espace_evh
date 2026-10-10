<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Services\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Courriels d'essai envoyes a de vraies personnes (MAIL_TEST_BANNER) : sujet « [TEST] » et bandeau
 * d'avertissement ; sans l'option, aucun changement.
 */
class EmailTestBannerTest extends TestCase
{
    use RefreshDatabase;

    private function queue(): void
    {
        Messenger::queueEmail('pasteur@exemple.org', null, 'membership_request', 'Nouveau membre à valider : Inscription TEST', 'membership-approved',
            ['firstName' => 'Inscription', 'url' => 'https://exemple.org'], 'banner-test:'.uniqid());
    }

    public function test_test_emails_are_clearly_marked(): void
    {
        Mail::fake();
        config(['mail.test_to' => null, 'mail.test_banner' => true]);
        $this->queue();
        Messenger::flush();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('pasteur@exemple.org')
            && $m->mailSubject === '[TEST] Nouveau membre à valider : Inscription TEST'
            && str_contains($m->render(), 'TEST — COURRIEL D\'ESSAI'));
    }

    public function test_ordinary_emails_are_unchanged(): void
    {
        Mail::fake();
        config(['mail.test_to' => null, 'mail.test_banner' => false]);
        $this->queue();
        Messenger::flush();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->mailSubject === 'Nouveau membre à valider : Inscription TEST'
            && ! str_contains($m->render(), 'COURRIEL D\'ESSAI'));
        $this->assertSame('sent', OutboundMessage::sole()->status);
    }
}
