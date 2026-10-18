<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Services\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Relance des courriels en echec definitif (app:outbox-retry) : apercu d'abord, rien n'est envoye sans --oui ;
 * seuls les courriels encore utiles repartent (membre joignable, rappel FISS du mois et fiche pas remplie).
 */
class OutboxRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 20:00:00');
        config(['mail.test_to' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $first, ?string $email): User
    {
        $user = User::create(['phone' => '+1418555'.random_int(1000, 9999), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme', 'email' => $email, 'is_completed' => true]);

        return $user;
    }

    private function failed(User $user, string $kind, string $dedupe, string $createdAt, ?string $recipient = null): OutboundMessage
    {
        $m = OutboundMessage::create(['channel' => 'email', 'recipient' => $recipient ?? Messenger::emailOf($user) ?? 'ancienne@exemple.org',
            'user_id' => $user->id, 'kind' => $kind, 'subject' => 'Votre fiche de santé spirituelle d\'octobre', 'view' => 'fiss-reminder',
            'payload' => ['firstName' => $user->profile->first_name, 'stage' => 'first', 'deMonth' => 'd\'octobre', 'daysLeft' => 21, 'url' => 'https://exemple.org/ma-fiche'],
            'dedupe_key' => $dedupe, 'status' => 'failed', 'attempts' => 1, 'available_at' => $createdAt, 'last_error' => Messenger::MAIL_NOT_CONFIGURED]);
        $m->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $m;
    }

    public function test_preview_then_only_still_useful_emails_are_queued_again_and_sent(): void
    {
        $anne = $this->member('Anne', 'anne@exemple.org');
        $boaz = $this->member('Boaz', 'boaz@exemple.org');
        $claire = $this->member('Claire', 'claire@exemple.org');
        $david = $this->member('David', null);
        $eve = $this->member('Eve', 'eve.nouvelle@exemple.org');
        SpiritualHealthForm::create(['user_id' => $boaz->id, 'period' => '2026-10', 'meditation' => 10, 'priere' => 10, 'jeune' => 10,
            'sanctification_corps' => 'bien', 'sanctification_ame' => 'bien', 'sanctification_esprit' => 'bien', 'situation_financiere' => 10, 'situation_familiale' => 10]);
        $claire->forceFill(['blocked_at' => now()])->save();

        $toAnne = $this->failed($anne, 'fiss_reminder', "fiss:2026-10:first:{$anne->id}", '2026-10-10 13:29:47');
        $toBoaz = $this->failed($boaz, 'fiss_reminder', "fiss:2026-10:first:{$boaz->id}", '2026-10-10 13:29:47');
        $toClaire = $this->failed($claire, 'fiss_reminder', "fiss:2026-10:first:{$claire->id}", '2026-10-10 13:29:47');
        $toDavid = $this->failed($david, 'fiss_reminder', "fiss:2026-10:first:{$david->id}", '2026-10-10 13:29:47');
        $september = $this->failed($anne, 'fiss_reminder', "fiss:2026-09:last:{$anne->id}", '2026-10-05 10:00:00');
        $old = $this->failed($anne, 'absence', 'absence:old', '2026-09-20 10:00:00');
        // Adresse changee depuis : la relance part a l'adresse d'aujourd'hui.
        $toEve = $this->failed($eve, 'fiss_reminder', "fiss:2026-10:first:{$eve->id}", '2026-10-10 13:29:47', 'eve.ancienne@exemple.org');

        // Apercu : la liste et les raisons, rien ne change.
        $this->assertSame(0, Artisan::call('app:outbox-retry'));
        $out = Artisan::output();
        foreach (['à relancer', 'fiche du mois déjà remplie', 'compte supprimé ou bloqué', 'plus d\'adresse courriel', 'rappel FISS d\'un autre mois',
            'trop ancien (plus de 14 j)', '2 courriel(s) à relancer sur 7.', 'Aperçu seulement', 'a***@exemple.org'] as $text) {
            $this->assertStringContainsString($text, $out);
        }
        $this->assertStringNotContainsString('anne@exemple.org', $out);
        $this->assertSame(7, OutboundMessage::where('status', 'failed')->count());

        // --oui : seuls Anne et Eve repartent ; envoyes au passage suivant de la file.
        $this->assertSame(0, Artisan::call('app:outbox-retry', ['--oui' => true]));
        $this->assertStringContainsString('2 courriel(s) remis en file', Artisan::output());
        $toAnne->refresh();
        $this->assertSame(['pending', 0, null], [$toAnne->status, $toAnne->attempts, $toAnne->last_error]);
        $this->assertSame('eve.nouvelle@exemple.org', $toEve->fresh()->recipient);
        foreach ([$toBoaz, $toClaire, $toDavid, $september, $old] as $m) {
            $this->assertSame('failed', $m->fresh()->status);
        }

        Mail::fake();
        $this->assertSame(['sent' => 2, 'failed' => 0], Messenger::flush());
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('anne@exemple.org'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('eve.nouvelle@exemple.org'));
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('boaz@exemple.org'));

        // Une seconde relance ne renvoie rien (deja envoyes).
        Artisan::call('app:outbox-retry', ['--oui' => true]);
        $this->assertStringContainsString('0 courriel(s) remis en file', Artisan::output());
    }

    public function test_only_one_kind_can_be_retried_and_nothing_happens_without_mail_configuration(): void
    {
        $anne = $this->member('Anne', 'anne@exemple.org');
        $fiss = $this->failed($anne, 'fiss_reminder', "fiss:2026-10:first:{$anne->id}", '2026-10-16 09:00:00');
        $absence = $this->failed($anne, 'absence', 'absence:recent', '2026-10-16 09:00:00');

        Artisan::call('app:outbox-retry', ['--kind' => 'absence', '--oui' => true]);
        $this->assertSame(['failed', 'pending'], [$fiss->fresh()->status, $absence->fresh()->status]);

        // Production sans envoi configure : refus, rien n'est touche.
        $absence->refresh()->forceFill(['status' => 'failed'])->save();
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);
        $this->assertSame(1, Artisan::call('app:outbox-retry', ['--oui' => true]));
        $this->assertStringContainsString('Aucun envoi de courriel configuré', Artisan::output());
        $this->assertSame(2, OutboundMessage::where('status', 'failed')->count());
    }
}
