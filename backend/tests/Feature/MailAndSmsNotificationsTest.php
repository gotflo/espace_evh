<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Appointment;
use App\Models\Assignment;
use App\Models\MemberDeletionRequest;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppointmentService;
use App\Services\Messenger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Courriels et textos : courriel du jour au pasteur (rendez-vous), demande de suppression avec boutons
 * « Accepter / Rejeter », nouveau devoir, rappels FISS et rapports ; file d'envoi, doublons, echecs,
 * heures calmes des textos, preferences.
 */
class MailAndSmsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private User $pr;

    private User $patriarch;

    private User $marie;

    private User $jean;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00')); // lundi
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'mail.test_to' => null, 'services.sms.test_to' => null]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pr = $this->give($this->member('+14185592001', 'Pasteur', 'pasteur@exemple.test'), 'super_admin');
        $this->patriarch = $this->give($this->member('+14185592002', 'Patriarche', 'patriarche@exemple.test'), 'patriarche', 'tribe', $this->juda->id);
        $this->marie = $this->member('+14185592003', 'Marie', 'marie@exemple.test');
        $this->jean = $this->member('+14185592004', 'Jean', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, ?string $email): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme', 'email' => $email,
            'is_completed' => true, 'tribe_id' => $this->juda->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function book(User $who, string $time): void
    {
        Sanctum::actingAs($who->fresh());
        $this->postJson('/api/me/appointments', ['host_id' => $this->pr->id, 'mode' => 'slot', 'starts_at' => self::WED.' '.$time])->assertCreated();
    }

    private function enableSms(): void
    {
        config(['services.sms.driver' => 'twilio_verify', 'services.sms.twilio.sid' => 'AC'.str_repeat('a', 32),
            'services.sms.twilio.sms_from' => '+15815550000', 'services.sms.twilio.api_key_sid' => 'SK'.str_repeat('b', 32),
            'services.sms.twilio.api_key_secret' => 'secret']);
    }

    // ---------------------------------------------------------------- Courriel du jour au pasteur

    public function test_the_pastor_gets_one_email_as_soon_as_every_slot_of_the_day_is_taken(): void
    {
        $others = [$this->member('+14185592005', 'Paul', null), $this->member('+14185592006', 'Luc', null),
            $this->member('+14185592008', 'Eve', null), $this->member('+14185592009', 'Noe', null)];
        $this->book($this->marie, '21:50');
        $this->book($this->jean, '22:05');
        $this->book($others[0], '22:20');
        $this->book($others[1], '22:35');
        $this->book($others[2], '22:50');
        $this->assertSame(0, OutboundMessage::where('kind', 'appointments_digest')->count());
        $this->book($others[3], '23:05'); // derniere des 6 places : le courriel part aussitot

        $digest = OutboundMessage::where('kind', 'appointments_digest')->sole();
        $this->assertSame('pasteur@exemple.test', $digest->recipient);
        $this->assertSame('Vos rendez-vous du mercredi 14 octobre (6)', $digest->subject);
        $this->assertSame('full', $digest->payload['reason']);
        $this->assertSame(['21h50', '22h05', '22h20', '22h35', '22h50', '23h05'], array_column($digest->payload['items'], 'time'));

        // Le rendu : liste dans l'ordre de passage, horaire, lien vers l'application.
        $html = (new NotificationMail($digest->subject, $digest->view, $digest->payload))->render();
        $this->assertStringContainsString('Toutes les places sont prises', $html);
        $this->assertStringContainsString('Marie Test', $html);
        $this->assertStringContainsString('21h50 – 23h20', $html);
        $this->assertStringContainsString('https://espace.vasesdhonneurchicoutimi.org/admin/rendez-vous', $html);

        // Les passages suivants n'envoient rien de plus.
        AppointmentService::mailDigests();
        AppointmentService::mailDigests();
        $this->assertSame(1, OutboundMessage::where('kind', 'appointments_digest')->count());
    }

    public function test_if_the_day_is_not_full_the_email_leaves_15_minutes_before(): void
    {
        $this->book($this->marie, '21:50');
        $this->book($this->jean, '22:20');
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:34:00'));
        AppointmentService::mailDigests();
        $this->assertSame(0, OutboundMessage::where('kind', 'appointments_digest')->count());

        Carbon::setTestNow(Carbon::parse(self::WED.' 21:35:00'));
        AppointmentService::mailDigests();
        AppointmentService::mailDigests();
        $digest = OutboundMessage::where('kind', 'appointments_digest')->sole();
        $this->assertSame('Vos rendez-vous de ce soir · mercredi 14 octobre (2)', $digest->subject);
        $html = (new NotificationMail($digest->subject, $digest->view, $digest->payload))->render();
        $this->assertStringContainsString('commencent dans 15 minutes', $html);
        $this->assertStringContainsString('4 créneaux libres', $html);
    }

    public function test_a_changed_list_after_an_early_email_gets_one_single_update(): void
    {
        $paul = $this->member('+14185592005', 'Paul', null);
        $luc = $this->member('+14185592006', 'Luc', null);
        $this->book($this->marie, '21:50');
        $this->book($this->jean, '22:05');
        $this->book($paul, '22:20');
        $this->book($this->member('+14185592008', 'Eve', null), '22:35');
        $this->book($this->member('+14185592009', 'Noe', null), '22:50');
        $this->book($luc, '23:05');
        $this->assertSame(1, OutboundMessage::where('kind', 'appointments_digest')->count());

        // Luc annule, une autre personne reserve : a 21h35, une seule mise a jour.
        Sanctum::actingAs($luc->fresh());
        $id = Appointment::where('user_id', $luc->id)->value('id');
        $this->postJson("/api/me/appointments/{$id}/cancel")->assertOk();
        $this->book($this->member('+14185592007', 'Anne', null), '23:05');
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:35:00'));
        AppointmentService::mailDigests();
        AppointmentService::mailDigests();
        $update = OutboundMessage::where('kind', 'appointments_digest')->orderByDesc('id')->first();
        $this->assertSame(2, OutboundMessage::where('kind', 'appointments_digest')->count());
        $this->assertStringStartsWith('Liste mise à jour · ', $update->subject);
        $this->assertContains('Anne Test', array_column($update->payload['items'], 'name'));
        $this->assertNotContains('Luc Test', array_column($update->payload['items'], 'name'));
    }

    public function test_a_pastor_without_an_email_is_told_once_in_the_app(): void
    {
        $this->pr->profile->update(['email' => null]);
        $this->book($this->marie, '21:50');
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:35:00'));
        AppointmentService::mailDigests();
        AppointmentService::mailDigests();
        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(1, UserNotification::where('user_id', $this->pr->id)->where('title', 'Ajoutez votre adresse courriel')->count());
    }

    // ---------------------------------------------------------------- Demande de suppression

    private function deletionRequest(): MemberDeletionRequest
    {
        Sanctum::actingAs($this->patriarch->fresh());
        $this->postJson("/api/admin/members/{$this->marie->id}/deletion-requests", ['reason' => 'Ne fréquente plus l\'église'])->assertCreated();

        return MemberDeletionRequest::sole();
    }

    public function test_the_pastor_gets_an_email_with_accept_and_reject_buttons_that_act_at_once(): void
    {
        $req = $this->deletionRequest();
        $mail = OutboundMessage::where('kind', 'deletion_request')->sole();
        $this->assertSame('pasteur@exemple.test', $mail->recipient);
        $this->assertSame('Demande de suppression : Marie Test', $mail->subject);
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('Accepter la suppression', $html);
        $this->assertStringContainsString('Rejeter la demande', $html);
        $this->assertStringContainsString('Ne fréquente plus', $html);
        $app = 'https://espace.vasesdhonneurchicoutimi.org/admin/validations?suppression='.$req->id;

        // Accepter une suppression (definitive) demande toujours un appui sur le bouton de la page : un antivirus
        // de messagerie qui ouvre le lien, meme dans un vrai navigateur (en-tete du clic), ne supprime rien.
        $approve = $mail->payload['approveUrl'];
        auth()->forgetGuards();
        $this->get($approve)->assertOk()->assertSee('Confirmer la suppression définitive')->assertDontSee('f.submit()', false);
        $this->get($approve, ['Sec-Fetch-User' => '?1', 'Sec-Fetch-Mode' => 'navigate'])->assertOk()->assertSee('Supprimer Marie Test ?');
        $this->assertNotNull(User::find($this->marie->id));

        // Appui sur le bouton : la decision part, puis l'application confirme le resultat.
        $this->post($approve)->assertStatus(303)->assertRedirect($app.'&resultat=approuvee');
        $this->assertNull(User::find($this->marie->id));
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Suppression effectuée : Marie Test')->count());

        // Une seconde fois (autre bouton, page rechargee) : rien n'est refait.
        $this->post($approve)->assertRedirect($app.'&resultat=deja');
        $this->post($mail->payload['rejectUrl'])->assertRedirect($app.'&resultat=deja');

        // L'application affiche la confirmation (Pasteur Resident seulement).
        Sanctum::actingAs($this->pr->fresh());
        $this->getJson("/api/admin/deletion-requests/{$req->id}")->assertOk()
            ->assertJsonPath('member_name', 'Marie Test')->assertJsonPath('status', 'approved')->assertJsonPath('decided_by', 'Pasteur Test');
        Sanctum::actingAs($this->patriarch->fresh());
        $this->getJson("/api/admin/deletion-requests/{$req->id}")->assertForbidden();
    }

    public function test_the_page_without_a_click_decides_on_its_own_then_opens_the_app(): void
    {
        $req = $this->deletionRequest();
        $reject = OutboundMessage::where('kind', 'deletion_request')->sole()->payload['rejectUrl'];
        auth()->forgetGuards();
        // Messagerie qui n'envoie pas l'en-tete du clic : la page s'envoie elle-meme (POST), puis redirige.
        $this->get($reject)->assertOk()->assertSee('Refus en cours');
        $this->post($reject)->assertStatus(303)
            ->assertRedirect('https://espace.vasesdhonneurchicoutimi.org/admin/validations?suppression='.$req->id.'&resultat=refusee');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertNotNull(User::find($this->marie->id));
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Suppression refusée : Marie Test')->count());
    }

    public function test_tampered_expired_or_foreign_links_do_nothing(): void
    {
        $req = $this->deletionRequest();
        $approve = OutboundMessage::where('kind', 'deletion_request')->sole()->payload['approveUrl'];
        $app = 'https://espace.vasesdhonneurchicoutimi.org/admin/validations?suppression='.$req->id;
        auth()->forgetGuards();

        // Lien modifie (autre decision) : rien n'est fait.
        $this->get(str_replace('/approve/', '/reject/', $approve), ['Sec-Fetch-User' => '?1'])->assertRedirect($app.'&resultat=expire');
        // Lien expire : rien n'est fait.
        Carbon::setTestNow(now()->addDays(15));
        $this->post($approve)->assertRedirect($app.'&resultat=expire');
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        // Lien signe pour quelqu'un qui n'est pas Pasteur Resident : rien n'est fait.
        $foreign = URL::temporarySignedRoute('email.deletion', now()->addDay(), ['deletionRequest' => $req->id, 'decision' => 'approve', 'pastor' => $this->patriarch->id]);
        $this->get($foreign, ['Sec-Fetch-User' => '?1'])->assertRedirect($app.'&resultat=refuse');
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertNotNull(User::find($this->marie->id));
    }

    // ---------------------------------------------------------------- Devoir (DS)

    public function test_a_new_devoir_reaches_members_by_email_or_else_by_text(): void
    {
        $this->enableSms();
        $muted = $this->member('+14185592008', 'Muet', 'muet@exemple.test');
        $muted->forceFill(['notification_prefs' => ['exercises' => false]])->save();

        Sanctum::actingAs($this->pr->fresh());
        $this->postJson('/api/admin/assignments', ['title' => 'La foi qui agit', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $id = Assignment::value('id');

        $byEmail = OutboundMessage::where('kind', 'assignment')->where('channel', 'email')->pluck('recipient')->all();
        $this->assertContains('marie@exemple.test', $byEmail);
        $this->assertNotContains('pasteur@exemple.test', $byEmail); // l'auteur ne recoit rien
        $this->assertNotContains('muet@exemple.test', $byEmail); // categorie coupee
        $sms = OutboundMessage::where('kind', 'assignment')->where('channel', 'sms')->sole();
        $this->assertSame('+14185592004', $sms->recipient); // Jean n'a pas d'adresse courriel
        $this->assertSame('My vasesdhonneur : nouveau devoir "La foi qui agit". Ouvrez-le ici : https://espace.vasesdhonneurchicoutimi.org/mon-devoir?d='.$id, $sms->payload['text']);

        $mail = OutboundMessage::where('recipient', 'marie@exemple.test')->sole();
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('Bonjour Marie', $html);
        $this->assertStringContainsString('Ouvrir mon devoir', $html);
    }

    public function test_without_a_twilio_number_texts_are_off_and_nothing_breaks(): void
    {
        Sanctum::actingAs($this->pr->fresh());
        $this->postJson('/api/admin/assignments', ['title' => 'Devoir', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->assertSame(0, OutboundMessage::where('channel', 'sms')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->jean->id)->where('type', 'assignment')->count()); // l'application previent toujours
    }

    // ---------------------------------------------------------------- FISS et rapports

    public function test_fiss_reminders_second_week_and_last_week_stop_after_submission(): void
    {
        $this->enableSms();
        SpiritualHealthForm::create(['user_id' => $this->patriarch->id, 'period' => '2026-10']);
        // Premiere semaine : plus de rappel (il part la 2e semaine).
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame(0, OutboundMessage::where('kind', 'fiss_reminder')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-09 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $first = OutboundMessage::where('kind', 'fiss_reminder')->get();
        $this->assertContains('marie@exemple.test', $first->pluck('recipient')->all());
        $this->assertContains('+14185592004', $first->pluck('recipient')->all());
        $this->assertNotContains('patriarche@exemple.test', $first->pluck('recipient')->all()); // fiche deja envoyee
        $this->assertSame(1, $first->where('recipient', 'marie@exemple.test')->count());
        $this->assertSame('Votre fiche de santé spirituelle d’octobre', $first->firstWhere('recipient', 'marie@exemple.test')->subject);
        // Aussi dans l'application (notification + push).
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('type', 'fiss')->where('title', 'Votre fiche d’octobre vous attend')->count());

        // Milieu du mois : rien. Marie envoie sa fiche.
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $this->assertSame($first->count(), OutboundMessage::where('kind', 'fiss_reminder')->count());
        SpiritualHealthForm::create(['user_id' => $this->marie->id, 'period' => '2026-10']);

        // Derniere semaine : relance pour Jean seulement.
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'fiss'])->assertSuccessful();
        $last = OutboundMessage::where('kind', 'fiss_reminder')->where('dedupe_key', 'like', 'fiss:2026-10:last:%')->get();
        $this->assertNotContains('marie@exemple.test', $last->pluck('recipient')->all());
        $this->assertContains('+14185592004', $last->pluck('recipient')->all());
        $this->assertStringContainsString('Il reste 5 jour(s)', $last->firstWhere('recipient', '+14185592004')->payload['text']);
    }

    public function test_report_reminders_first_week_and_last_week_until_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $mail = OutboundMessage::where('kind', 'report_reminder')->sole();
        $this->assertSame('patriarche@exemple.test', $mail->recipient);
        $this->assertSame('Rapport de septembre à transmettre · Tribu Juda', $mail->subject);
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('de la <strong>tribu Juda</strong>', $html);
        $this->assertStringContainsString('remplir=tribe:'.$this->juda->id, $html);

        Carbon::setTestNow(Carbon::parse('2026-10-27 09:30:00'));
        $this->artisan('app:tick', ['--only' => 'leader-reports'])->assertSuccessful();
        $this->assertSame('Rappel : rapport de septembre à transmettre · Tribu Juda', OutboundMessage::where('kind', 'report_reminder')->latest('id')->value('subject'));
        $this->assertSame(2, OutboundMessage::where('kind', 'report_reminder')->count());
    }

    // ---------------------------------------------------------------- File d'envoi

    public function test_the_outbox_sends_once_from_the_church_address_and_retries_failures(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'noreply@vasesdhonneurchicoutimi.org']);
        Messenger::queueEmail('marie@exemple.test', $this->marie->id, 'fiss_reminder', 'Sujet', 'fiss-reminder',
            ['firstName' => 'Marie', 'stage' => 'first', 'deMonth' => 'd\'octobre', 'daysLeft' => 20, 'url' => 'https://x'], 'k1');
        $this->assertNull(Messenger::queueEmail('marie@exemple.test', null, 'fiss_reminder', 'Sujet', 'fiss-reminder', [], 'k1')); // doublon

        $this->artisan('app:send-outbox')->assertSuccessful();
        $this->artisan('app:send-outbox')->assertSuccessful();
        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('marie@exemple.test')
            && $m->hasFrom('noreply@vasesdhonneurchicoutimi.org') && ! $m->hasReplyTo('contact@vasesdhonneurchicoutimi.org'));
        $this->assertSame('sent', OutboundMessage::sole()->status);
    }

    public function test_test_mode_redirects_every_email_to_the_test_address(): void
    {
        Mail::fake();
        config(['mail.test_to' => 'gotflo.ia@gmail.com']);
        Messenger::queueEmail('marie@exemple.test', null, 'fiss_reminder', 'Votre fiche', 'fiss-reminder',
            ['firstName' => 'Marie', 'stage' => 'first', 'deMonth' => 'd\'octobre', 'daysLeft' => 20, 'url' => 'https://x'], null);
        Messenger::flush();
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('gotflo.ia@gmail.com') && ! $m->hasTo('marie@exemple.test')
            && $m->mailSubject === '[Essai → marie@exemple.test] Votre fiche');
    }

    public function test_a_failing_send_is_retried_then_given_up(): void
    {
        $this->enableSms();
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 30003, 'message' => 'Unreachable'], 400)]);
        Messenger::queueSms('+14185592004', null, 'assignment', 'Bonjour', null);
        foreach ([0, 1, 5, 30, 120] as $minutes) {
            Carbon::setTestNow(now()->addMinutes($minutes));
            Messenger::flush();
        }
        $m = OutboundMessage::sole();
        $this->assertSame('failed', $m->status);
        $this->assertSame(5, $m->attempts);
        $this->assertStringContainsString('30003', $m->last_error);
    }

    public function test_texts_are_never_sent_at_night_and_keep_their_link(): void
    {
        $this->enableSms();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        Carbon::setTestNow(Carbon::parse('2026-10-12 22:15:00'));
        $m = Messenger::queueSms('+14185592004', null, 'assignment', 'Bonjour', null);
        $this->assertSame('2026-10-13 09:00', $m->available_at->format('Y-m-d H:i'));
        Messenger::flush();
        Http::assertNothingSent();
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:01:00'));
        Messenger::flush();
        Http::assertSent(fn ($r) => $r['To'] === '+14185592004' && $r['From'] === '+15815550000' && $r['Body'] === 'Bonjour');

        // Alphabet SMS standard (pas de passage en Unicode), un seul texto, lien jamais coupe.
        $text = Messenger::smsText('Fête « spéciale » : ça commence bientôt, n’oubliez pas '.str_repeat('vraiment ', 20).'https://espace.vasesdhonneurchicoutimi.org/mon-devoir?d=12');
        $this->assertLessThanOrEqual(160, mb_strlen($text));
        $this->assertStringEndsWith('https://espace.vasesdhonneurchicoutimi.org/mon-devoir?d=12', $text);
        $this->assertStringStartsWith('Fete "spéciale" : ca commence', $text); // é fait partie de l'alphabet SMS
    }

    public function test_a_member_adds_an_email_in_one_step(): void
    {
        Sanctum::actingAs($this->jean->fresh());
        $this->putJson('/api/me/email', ['email' => 'pas-une-adresse'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Cette adresse courriel n\'est pas valide (exemple : marie@gmail.com).');
        $this->putJson('/api/me/email', ['email' => '  Jean.Test@Exemple.ORG '])->assertOk()->assertJsonPath('profile.email', 'jean.test@exemple.org');
        $this->assertSame('jean.test@exemple.org', $this->jean->profile->fresh()->email);

        // Le prochain devoir lui arrive par courriel.
        Sanctum::actingAs($this->pr->fresh());
        $this->postJson('/api/admin/assignments', ['title' => 'Devoir', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->assertSame(1, OutboundMessage::where('recipient', 'jean.test@exemple.org')->count());
    }

    public function test_members_without_email_are_invited_once_then_reminded_once(): void
    {
        $title = fn () => UserNotification::where('user_id', $this->jean->id)->where('type', 'profile')->orderBy('id')->pluck('title')->all();
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->assertSame([], $title()); // pas avant 11 h

        Carbon::setTestNow(Carbon::parse('2026-10-12 11:05:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->assertSame(['Recevez vos rappels par courriel'], $title());
        $this->assertSame(0, UserNotification::where('user_id', $this->marie->id)->where('type', 'profile')->count()); // adresse deja remplie

        Carbon::setTestNow(Carbon::parse('2026-10-25 11:05:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->assertCount(1, $title()); // moins de 3 semaines

        Carbon::setTestNow(Carbon::parse('2026-11-03 11:05:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2027-03-03 11:05:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->assertSame(['Recevez vos rappels par courriel', 'Votre adresse courriel nous manque'], $title()); // puis plus rien
        $this->assertSame('/tableau-de-bord?courriel=1', UserNotification::where('user_id', $this->jean->id)->where('type', 'profile')->value('url'));
    }

    public function test_members_choose_email_and_text_channels(): void
    {
        Sanctum::actingAs($this->marie->fresh());
        $this->getJson('/api/me/notification-prefs')->assertOk()
            ->assertJsonPath('channels.0.key', 'channel_email')->assertJsonPath('channels.0.available', true)
            ->assertJsonPath('channels.0.detail', 'marie@exemple.test')->assertJsonPath('channels.1.available', false);
        $this->putJson('/api/me/notification-prefs', ['prefs' => ['channel_email' => false]])->assertOk()->assertJsonPath('channels.0.enabled', false);

        Sanctum::actingAs($this->pr->fresh());
        $this->postJson('/api/admin/assignments', ['title' => 'Devoir', 'media_type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->assertSame(0, OutboundMessage::where('recipient', 'marie@exemple.test')->count());
    }
}
