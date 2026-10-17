<?php

namespace Tests\Feature;

use App\Console\Commands\Relance;
use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Relance manuelle (app:relance) : apercu sans envoi, membres sans courriel, absents (meme apres le
 * message automatique), tribu manquante (liste seulement) ; une fois par jour, jamais aux comptes
 * bloques, en attente, de gestion, ni aux autorites pastorales pour l'absence.
 */
class RelanceCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private User $pr;

    private User $marie;

    private User $jean;

    private User $paul;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'mail.test_to' => null, 'services.sms.test_to' => null]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pr = $this->give($this->member('+14185597001', 'Pasteur', 'pasteur@exemple.test'), 'super_admin');
        $this->marie = $this->member('+14185597002', 'Marie', 'marie@exemple.test');
        $this->jean = $this->member('+14185597003', 'Jean', null);
        $this->paul = $this->member('+14185597004', 'Paul', '');
        PushSubscription::create(['user_id' => $this->paul->id, 'endpoint' => 'https://push.example/paul', 'endpoint_hash' => hash('sha256', 'paul'),
            'public_key' => 'cle', 'auth_token' => 'jeton']);

        // Jamais relances : compte bloque, inscription en attente, compte de gestion.
        $this->member('+14185597005', 'Bloque', null)->forceFill(['blocked_at' => now()])->save();
        $this->member('+14185597006', 'Attente', null)->forceFill(['approval_status' => User::PENDING])->save();
        $this->member('+14185597007', 'Gestion', null)->forceFill(['platform_manager_at' => now()])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, ?string $email, bool $completed = true, bool $withTribe = true): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'last_seen_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme', 'email' => $email,
            'is_completed' => $completed, 'tribe_id' => $withTribe ? $this->juda->id : null, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function seenDaysAgo(User $user, int $days): void
    {
        $user->forceFill(['last_seen_at' => now()->subDays($days), 'last_login_at' => now()->subDays($days)])->saveQuietly();
    }

    private function emailNotices(): array
    {
        return UserNotification::where('title', Relance::EMAIL_TITLE)->orderBy('user_id')->pluck('user_id')->all();
    }

    // ---------------------------------------------------------------- Courriel manquant

    public function test_the_preview_counts_members_without_email_and_sends_nothing(): void
    {
        $this->artisan('app:relance', ['cible' => 'courriel', '--liste' => true])
            ->expectsOutputToContain('Destinataires : 2')
            ->expectsOutputToContain('avec les notifications push sur un appareil : 1')
            ->expectsOutputToContain(Relance::EMAIL_TITLE)
            ->expectsOutputToContain('+14185597003')
            ->doesntExpectOutputToContain('+14185597005')
            ->expectsOutputToContain('APERÇU : rien n’a été envoyé')
            ->assertSuccessful();

        $this->assertSame([], $this->emailNotices());
        $this->assertSame(0, UserNotification::count());
        $this->assertSame(0, AuditLog::where('action', 'members.relanced')->count());
    }

    public function test_sending_reaches_members_without_email_once_a_day_and_counts_as_an_invite(): void
    {
        $this->artisan('app:relance', ['cible' => 'courriel', '--envoyer' => true])
            ->expectsOutputToContain('Relance envoyée : 2 membre(s).')->assertSuccessful();

        $this->assertSame([$this->jean->id, $this->paul->id], $this->emailNotices());
        $notice = UserNotification::where('user_id', $this->jean->id)->sole();
        $this->assertSame(['profile', Relance::EMAIL_BODY, '/tableau-de-bord?courriel=1', 'high'], [$notice->type, $notice->body, $notice->url, $notice->priority]);
        $this->assertSame(['2026-10-16'], $this->jean->fresh()->notification_prefs['email_invites']);
        $this->assertSame(2, AuditLog::where('action', 'members.relanced')->sole()->new_values['recipients']);

        // Deuxieme lancement le meme jour : personne n'est relance deux fois.
        $this->artisan('app:relance', ['cible' => 'courriel', '--envoyer' => true])
            ->expectsOutputToContain('Relance envoyée : 0 membre(s).')->assertSuccessful();
        $this->assertCount(2, $this->emailNotices());

        // L'invitation automatique tient compte de la relance (pas de seconde invitation le jour meme).
        Carbon::setTestNow(Carbon::parse('2026-10-16 11:05:00'));
        $this->artisan('app:tick', ['--only' => 'email-invites'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('user_id', $this->jean->id)->where('title', '!=', Relance::EMAIL_TITLE)->count());

        // Une adresse ajoutee : plus de relance ; un autre jour, les autres peuvent etre relances encore.
        $this->jean->profile->update(['email' => 'jean@exemple.test']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $this->artisan('app:relance', ['cible' => 'courriel', '--envoyer' => true])
            ->expectsOutputToContain('Relance envoyée : 1 membre(s).')->assertSuccessful();
        $this->assertSame([$this->jean->id, $this->paul->id, $this->paul->id], $this->emailNotices());
    }

    // ---------------------------------------------------------------- Absents

    public function test_the_absent_preview_shows_who_and_by_which_channel(): void
    {
        $this->seenDaysAgo($this->marie, 45);
        $this->seenDaysAgo($this->paul, 20);
        $this->seenDaysAgo($this->jean, 9);
        $this->seenDaysAgo($this->pr, 40); // autorite pastorale : pas concernee
        $newcomer = $this->member('+14185597008', 'Nouveau', null);
        $this->seenDaysAgo($newcomer, 8);
        $newcomer->forceFill(['approval_decided_at' => now()->subDays(3)])->save(); // valide il y a 3 jours

        $this->artisan('app:relance', ['cible' => 'absents', '--liste' => true])
            ->expectsOutputToContain('aucune visite depuis au moins 7 jours')
            ->expectsOutputToContain('Destinataires : 3')
            ->expectsOutputToContain('absents moins de 14 jours : 1')
            ->expectsOutputToContain('absents de 14 à 30 jours : 1')
            ->expectsOutputToContain('absents de 31 à 60 jours : 1')
            ->expectsOutputToContain('courriel : 1')
            ->expectsOutputToContain('push seulement : 1')
            ->expectsOutputToContain('rien avant leur prochaine visite (ni courriel ni push) : 1')
            ->doesntExpectOutputToContain('+14185597001')
            ->doesntExpectOutputToContain('+14185597008')
            ->assertSuccessful();
        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(0, UserNotification::count());

        $this->artisan('app:relance', ['cible' => 'absents', '--jours' => 30])
            ->expectsOutputToContain('Destinataires : 1')->assertSuccessful();
    }

    public function test_absent_members_are_reminded_again_after_the_automatic_message_but_once(): void
    {
        $this->seenDaysAgo($this->marie, 9);
        $this->seenDaysAgo($this->jean, 12);

        // Message automatique d'absence deja parti (app:tick).
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(1, OutboundMessage::where('kind', 'absence')->count());
        $this->artisan('app:relance', ['cible' => 'absents'])
            ->expectsOutputToContain('déjà relancés automatiquement pour cette absence : 2')->assertSuccessful();

        $this->artisan('app:relance', ['cible' => 'absents', '--envoyer' => true])
            ->expectsOutputToContain('Relance envoyée : 2 membre(s)')->assertSuccessful();
        $mails = OutboundMessage::where('kind', 'absence')->where('recipient', 'marie@exemple.test')->orderBy('id')->get();
        $this->assertCount(2, $mails);
        $html = (new NotificationMail($mails[1]->subject, $mails[1]->view, $mails[1]->payload))->render();
        $this->assertStringContainsString('depuis <strong>9 jours</strong>', $html);
        $this->assertSame(2, UserNotification::where('user_id', $this->jean->id)->where('title', 'Votre espace vous attend')->count());
        $this->assertSame(['email' => 1, 'push / application' => 1], AuditLog::where('action', 'members.relanced')->sole()->new_values['channels']);

        // Ni deuxieme relance le meme jour, ni nouveau message automatique pour cette absence.
        $this->artisan('app:relance', ['cible' => 'absents', '--envoyer' => true])->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(2, OutboundMessage::where('kind', 'absence')->count());
    }

    // ---------------------------------------------------------------- Tribu

    public function test_members_without_a_tribe_are_listed_for_a_text_and_nothing_is_sent(): void
    {
        $this->member('+14185597009', 'Sanstribu', null, completed: false, withTribe: false); // rouvert : il ne manque que la tribu
        $this->give($this->member('+14185597010', 'Ap', null, withTribe: false), 'assistant_pasteur'); // tribu facultative
        $draft = $this->member('+14185597011', 'Brouillon', null, completed: false, withTribe: false);
        $draft->profile->update(['matricule' => null]); // inscription jamais terminee : autre cas

        $this->artisan('app:relance', ['cible' => 'tribu', '--liste' => true])
            ->expectsOutputToContain('Personnes visées : 1')
            ->expectsOutputToContain('+14185597009')
            ->doesntExpectOutputToContain('+14185597010')
            ->doesntExpectOutputToContain('+14185597011')
            ->assertSuccessful();

        $this->artisan('app:relance', ['cible' => 'tribu', '--envoyer' => true])->assertFailed();
        $this->assertSame(0, UserNotification::count());
        $this->assertSame(0, OutboundMessage::count());
    }
}
