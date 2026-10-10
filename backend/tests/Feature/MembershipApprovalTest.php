<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Audience;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nouveau compte valide par le pasteur : ecran d'attente (seul l'essentiel repond), profil complet envoye au
 * pasteur par courriel avec « Valider / Rejeter » (un appui suffit), decision appliquee aussitot au compte,
 * la personne prevenue par courriel. Tant qu'il n'est pas valide, le compte n'apparait nulle part.
 */
class MembershipApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private User $pr;

    private User $patriarch;

    private const PHONE = '+14185593001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'mail.test_to' => null,
            'services.sms.driver' => 'log', 'app.expose_otp' => true]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pr = $this->member('+14185593010', 'Abraham', 'Andebi', 'pasteur@exemple.test');
        $this->pr->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->patriarch = $this->member('+14185593011', 'Paul', 'Patriarche', null);
        $this->patriarch->roles()->attach(Role::where('key', 'patriarche')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => $this->juda->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, string $last, ?string $email): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'homme', 'email' => $email,
            'is_completed' => true, 'tribe_id' => $this->juda->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    /** Inscription complete d'un nouveau compte (code par SMS, puis profil). */
    private function signUp(?string $email = null): User
    {
        $code = $this->postJson('/api/auth/request-otp', ['phone' => self::PHONE])->assertOk()->json('dev_code');
        $login = $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'code' => $code])->assertOk()
            ->assertJsonPath('is_new_account', true)->assertJsonPath('membership.status', 'pending')
            ->assertJsonPath('membership.submitted', false)->assertJsonPath('profile_completed', false);
        $user = User::where('phone', self::PHONE)->sole();
        Sanctum::actingAs($user);
        if ($email) {
            $this->putJson('/api/me/email', ['email' => $email])->assertOk();
        }
        $this->postJson('/api/profile', ['first_name' => 'Degaulle', 'last_name' => 'Kaswende', 'gender' => 'homme', 'tribe_id' => $this->juda->id,
            'birth_day' => 15, 'birth_month' => 5])->assertOk()
            ->assertJsonPath('message', 'Inscription envoyée au pasteur.')->assertJsonPath('membership.submitted', true);
        $this->assertNotEmpty($login->json('token'));

        return $user->fresh();
    }

    public function test_a_new_account_waits_for_the_pastor_and_reaches_only_the_waiting_screen(): void
    {
        $user = $this->signUp('degaulle@exemple.test');
        $this->assertSame(User::PENDING, $user->approval_status);
        $this->assertFalse((bool) $user->profile->is_completed);

        // Ecran d'attente : compte, verset du jour, adresse courriel. Tout le reste est refuse.
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('membership.status', 'pending')->assertJsonPath('membership.submitted', true);
        $this->getJson('/api/dashboard/verse')->assertOk();
        $this->putJson('/api/me/email', ['email' => 'degaulle@exemple.test'])->assertOk();
        foreach (['/api/me/home', '/api/me/fiss', '/api/me/appointments', '/api/admin/members', '/api/me/notifications', '/api/calendar'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('membership', 'pending');
        }
        $this->postJson('/api/me/requests', ['subject' => 'x', 'message' => 'y'])->assertForbidden();

        // Le pasteur est prevenu (application + courriel) ; une seule fois, meme si le profil est renvoye.
        $this->assertSame(1, UserNotification::where('user_id', $this->pr->id)->where('title', 'Nouvelle inscription : Degaulle Kaswende')->count());
        $this->postJson('/api/profile', ['first_name' => 'Degaulle', 'last_name' => 'Kaswende', 'gender' => 'homme'])->assertOk();
        $mail = OutboundMessage::where('kind', 'membership_request')->sole();
        $this->assertSame('pasteur@exemple.test', $mail->recipient);
        $this->assertSame('Nouveau membre à valider : Degaulle Kaswende', $mail->subject);
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('Un nouveau Membre souhaite rejoindre la plateforme “My vasesdhonneur”', $html);
        $this->assertStringContainsString('Cher Pasteur Abraham Andebi, un nouveau Membre, <strong>Degaulle Kaswende</strong>, a soumis une demande pour rejoindre “My vasesdhonneur”.', $html);
        foreach (['Prénoms', 'Degaulle', 'Kaswende', 'Juda', '15 mai', self::PHONE, 'degaulle@exemple.test', 'Valider', 'Rejeter'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // Invisible ailleurs tant qu'il n'est pas valide : listes, audiences, responsables.
        Sanctum::actingAs($this->pr->fresh());
        $this->assertNotContains($user->id, array_column($this->getJson('/api/admin/members')->assertOk()->json('members'), 'user_id'));
        $this->getJson("/api/admin/members/{$user->id}")->assertNotFound();
        $this->assertNotContains($user->id, Audience::userIds([['type' => 'church', 'id' => null]]));
        $this->assertSame(0, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'like', 'Nouveau membre%')->count());
        $this->getJson('/api/admin/validations')->assertOk()->assertJsonPath('memberships.0.name', 'Degaulle Kaswende')->assertJsonPath('count', 1);
    }

    public function test_the_pastor_validates_from_the_email_in_one_tap_and_the_person_is_told(): void
    {
        $user = $this->signUp('degaulle@exemple.test');
        $mail = OutboundMessage::where('kind', 'membership_request')->sole();
        $app = 'https://espace.vasesdhonneurchicoutimi.org/admin/validations?inscription='.$user->id;
        auth()->forgetGuards();

        // Un antivirus qui « ouvre » le lien ne decide rien ; un vrai appui valide aussitot.
        $this->get($mail->payload['approveUrl'])->assertOk()->assertSee('Validation en cours');
        $this->assertSame(User::PENDING, $user->fresh()->approval_status);
        $this->get($mail->payload['approveUrl'], ['Sec-Fetch-User' => '?1'])->assertStatus(303)->assertRedirect($app.'&resultat=validee');
        $user->refresh();
        $this->assertSame(User::APPROVED, $user->approval_status);
        $this->assertTrue((bool) $user->profile->is_completed);
        $this->assertSame($this->pr->id, (int) $user->approval_decided_by);

        // Rien n'est refait (second appui, autre bouton).
        $this->post($mail->payload['approveUrl'])->assertRedirect($app.'&resultat=deja');
        $this->post($mail->payload['rejectUrl'])->assertRedirect($app.'&resultat=deja');

        // La personne est prevenue (courriel + notification) ; ses responsables peuvent l'accueillir.
        $welcome = OutboundMessage::where('kind', 'membership_approved')->sole();
        $this->assertSame('degaulle@exemple.test', $welcome->recipient);
        $this->assertStringContainsString('Le pasteur a validé votre compte', (new NotificationMail($welcome->subject, $welcome->view, $welcome->payload))->render());
        $this->assertSame(1, UserNotification::where('user_id', $user->id)->where('title', 'Votre compte est validé')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Nouveau membre : Degaulle Kaswende')->count());

        // Acces complet, et l'application confirme la decision au pasteur.
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/me/notifications')->assertOk();
        $this->getJson('/api/me')->assertJsonPath('profile_completed', true)->assertJsonPath('membership.status', 'approved');
        Sanctum::actingAs($this->pr->fresh());
        $this->getJson("/api/admin/membership-requests/{$user->id}")->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('decided_by', 'Abraham Andebi')->assertJsonPath('name', 'Degaulle Kaswende');
        $this->assertContains($user->id, array_column($this->getJson('/api/admin/members')->json('members'), 'user_id'));
    }

    public function test_a_rejection_closes_access_and_can_still_be_corrected_in_the_app(): void
    {
        $user = $this->signUp('degaulle@exemple.test');
        $reject = OutboundMessage::where('kind', 'membership_request')->sole()->payload['rejectUrl'];
        auth()->forgetGuards();
        // Messagerie sans en-tete de clic : la page envoie la decision elle-meme.
        $this->get($reject)->assertOk()->assertSee('Refus en cours');
        $this->post($reject)->assertStatus(303)->assertRedirect('https://espace.vasesdhonneurchicoutimi.org/admin/validations?inscription='.$user->id.'&resultat=refusee');
        $this->assertSame(User::REJECTED, $user->fresh()->approval_status);
        $this->assertSame('Votre demande d\'inscription à My vasesdhonneur', OutboundMessage::where('kind', 'membership_rejected')->sole()->subject);

        // Compte rejete : il voit la reponse, rien d'autre (pas meme son profil).
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('membership.status', 'rejected');
        $this->getJson('/api/profile')->assertForbidden()->assertJsonPath('membership', 'rejected');
        $this->postJson('/api/profile', ['first_name' => 'X', 'last_name' => 'Y', 'gender' => 'homme'])->assertForbidden();

        // Erreur corrigee par le pasteur dans l'application.
        Sanctum::actingAs($this->pr->fresh());
        $this->getJson('/api/admin/validations')->assertJsonPath('memberships_rejected.0.user_id', $user->id)->assertJsonPath('count', 0);
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'rejected'])->assertStatus(409);
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/me/notifications')->assertOk();
    }

    public function test_only_the_pastor_decides_and_tampered_or_expired_links_do_nothing(): void
    {
        $user = $this->signUp();
        // Sans adresse courriel : pas de courriel de resultat, mais la notification dans l'application.
        $approve = OutboundMessage::where('kind', 'membership_request')->sole()->payload['approveUrl'];
        $app = 'https://espace.vasesdhonneurchicoutimi.org/admin/validations?inscription='.$user->id;

        // Ni un responsable, ni la personne elle-meme.
        Sanctum::actingAs($this->patriarch->fresh());
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'approved'])->assertForbidden();
        $this->getJson("/api/admin/membership-requests/{$user->id}")->assertForbidden();
        $this->getJson('/api/admin/validations')->assertJsonPath('memberships', []);
        Sanctum::actingAs($user->fresh());
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'approved'])->assertForbidden();

        auth()->forgetGuards();
        $this->get(str_replace('/approve/', '/reject/', $approve), ['Sec-Fetch-User' => '?1'])->assertRedirect($app.'&resultat=expire');
        $foreign = URL::temporarySignedRoute('email.membership', now()->addDay(), ['member' => $user->id, 'decision' => 'approve', 'pastor' => $this->patriarch->id]);
        $this->get($foreign, ['Sec-Fetch-User' => '?1'])->assertRedirect($app.'&resultat=refuse');
        Carbon::setTestNow(now()->addDays(31));
        $this->post($approve)->assertRedirect($app.'&resultat=expire');
        $this->assertSame(User::PENDING, $user->fresh()->approval_status);

        // Le pasteur valide dans l'application : la personne est prevenue dans l'application.
        Sanctum::actingAs($this->pr->fresh());
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'approved'])->assertOk();
        $this->assertSame(0, OutboundMessage::whereIn('kind', ['membership_approved', 'membership_rejected'])->count());
        $this->assertSame(1, UserNotification::where('user_id', $user->id)->where('title', 'Votre compte est validé')->count());
    }

    public function test_a_late_validation_counts_as_the_arrival_no_absence_mail_no_overdue_welcome(): void
    {
        $user = $this->signUp('degaulle@exemple.test');
        // Le pasteur valide 12 jours plus tard ; la personne n'a pas rouvert l'application entre-temps.
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:05:00'));
        Sanctum::actingAs($this->pr->fresh());
        $this->postJson("/api/admin/validations/memberships/{$user->id}", ['decision' => 'approved'])->assertOk();

        // Le patriarche la voit parmi les nouveaux a accueillir, arrivee aujourd'hui.
        Sanctum::actingAs($this->patriarch->fresh());
        $row = collect($this->getJson('/api/admin/new-members')->assertOk()->json('members'))->firstWhere('user_id', $user->id);
        $this->assertNotNull($row);
        $this->assertSame(0, $row['days_ago']);

        // Pas de relance « a accueillir » ni de courriel d'absence des le lendemain.
        Carbon::setTestNow(Carbon::parse('2026-10-25 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'À accueillir : Degaulle Kaswende')->count());
        $this->assertSame(0, OutboundMessage::where('kind', 'absence')->where('recipient', 'degaulle@exemple.test')->count());

        // Trois jours apres la validation, toujours pas accueillie : le patriarche est relance.
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'followups'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $this->patriarch->id)->where('title', 'À accueillir : Degaulle Kaswende')->count());
    }

    public function test_existing_members_and_predefined_accounts_are_never_put_on_hold(): void
    {
        $marie = $this->member('+14185593020', 'Marie', 'Test', null);
        Sanctum::actingAs($marie);
        $this->getJson('/api/me/notifications')->assertOk();
        $this->getJson('/api/me')->assertJsonPath('membership.status', 'approved');

        // Compte predefini du Pasteur Resident : jamais en attente.
        $code = $this->postJson('/api/auth/request-otp', ['phone' => '+14185500785'])->assertOk()->json('dev_code');
        $this->postJson('/api/auth/verify-otp', ['phone' => '+14185500785', 'code' => $code])->assertOk()->assertJsonPath('membership.status', 'approved');
    }
}
