<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Profile;
use App\Models\Role;
use App\Models\ScheduledNotification;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\PermissionCatalog;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Centre de notifications : acces reserve, coupure datee des rappels d'un evenement (jeune de 40 jours), suspension
 * datee d'un rappel automatique, relances manuelles (une fois par jour), notifications personnalisees (tout de
 * suite, programmees, annulees).
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pr;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 10:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config(['app.url' => 'https://espace.exemple.test', 'mail.test_to' => null, 'services.sms.test_to' => null]);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pr = $this->give($this->person('Pierre', $this->juda), 'super_admin');
    }

    public function test_only_the_platform_management_account_opens_the_center(): void
    {
        // Reserve a l'administration de la plateforme : pas meme le Pasteur Resident (tous les droits des roles).
        foreach ([$this->pr, $this->give($this->person('Pa', $this->juda), 'pasteur_assistant'), $this->give($this->person('Patri', $this->juda), 'patriarche', 'tribe', $this->juda->id),
            $this->person('Fidele', $this->juda)] as $user) {
            $this->as($user);
            $this->getJson('/api/admin/notification-center')->assertForbidden();
            $this->postJson('/api/admin/notification-center/messages', ['title' => 'X', 'scopes' => [['type' => 'church']]])->assertForbidden();
        }

        // Aucun role ne peut le donner : la permission n'existe pas dans le catalogue des roles.
        $this->assertArrayNotHasKey('notifications.manage', PermissionCatalog::all());

        $manager = $this->manager();
        $this->assertTrue($manager->fresh()->isPlatformManager());
        $this->as($manager);
        $this->getJson('/api/admin/notification-center')->assertOk()->assertJsonStructure(['automations', 'events', 'launchers', 'scheduled', 'audiences']);
    }

    public function test_a_weekly_service_is_silent_during_the_fast_and_its_reminders_come_back_after(): void
    {
        $member = $this->person('Marie', $this->juda);
        // Comme en production : un seul evenement porte ce nom (Bloom Light a son propre titre).
        Event::where('title', 'like', 'Bloom Light%')->update(['title' => 'Bloom Light']);
        // Le culte tel qu'il existe deja (cree avec les horaires des cultes), sinon cree comme en production.
        $event = Event::where('title', 'Coin cocktail fraternel')->first();
        if (! $event) {
            $event = Event::create(['title' => 'Coin cocktail fraternel', 'category' => 'culte', 'starts_at' => '2026-11-08 12:00:00',
                'recurrence' => 'weekly', 'remind_all' => true, 'is_personal' => false]);
            $event->syncScopes([['type' => 'church', 'id' => null]]);
        }
        $this->assertSame(1, Event::where('title', 'Coin cocktail fraternel')->count());

        $this->as($this->manager());
        $this->putJson("/api/admin/notification-center/events/{$event->id}", ['muted' => false, 'paused_from' => '2026-11-09', 'paused_until' => '2026-12-18'])
            ->assertOk()->assertJsonPath('message', 'Rappels coupés pour la période choisie : ils reprendront d’eux-mêmes.');
        $row = collect($this->getJson('/api/admin/notification-center')->json('events'))->firstWhere('id', $event->id);
        $this->assertSame(['2026-11-09', '2026-12-18', true], [$row['muted_from'], $row['muted_until'], $row['muted_today']]);

        // Pendant le jeune : ni le programme de la veille, ni le rappel juste avant.
        $soon = fn (string $day) => Carbon::parse($day.' '.$event->starts_at->format('H:i'))->subMinutes(20)->format('Y-m-d H:i');
        foreach (['2026-11-14 19:00', $soon('2026-11-15'), '2026-12-12 19:00', $soon('2026-12-13')] as $at) {
            $this->travelTo(Carbon::parse($at));
            $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        }
        // (les autres cultes de la semaine gardent leur programme : seul le Coin cocktail se tait)
        $cocktail = fn () => UserNotification::where('user_id', $member->id)
            ->where(fn ($q) => $q->where('title', 'like', '%cocktail%')->orWhere('body', 'like', '%cocktail%'))->count();
        $this->assertSame(0, $cocktail(), 'aucun rappel du Coin cocktail pendant la periode');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $member->id)->count(), 'les autres cultes gardent leur programme');

        // Apres le 18 decembre : les rappels reprennent d'eux-memes (dimanche 20).
        $this->travelTo(Carbon::parse('2026-12-19 19:00'));
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        $this->travelTo(Carbon::parse($soon('2026-12-20')));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->assertSame(1, $cocktail(), 'programme de la veille revenu (une seule notification, rien le jour meme)');
    }

    public function test_an_automatic_reminder_can_be_turned_off_or_paused_for_a_period(): void
    {
        $member = $this->person('Anniv', $this->juda);
        Profile::where('user_id', $member->id)->update(['birth_day' => 9, 'birth_month' => 11]);
        $this->as($this->manager());

        // Suspendu aujourd'hui : rien ; la suspension est visible et datee.
        $this->putJson('/api/admin/notification-center/automations/automation.birthdays/pause',
            ['paused_from' => '2026-11-09', 'paused_until' => '2026-11-10', 'reason' => 'Essai'])->assertOk();
        $row = collect($this->getJson('/api/admin/notification-center')->json('automations'))->firstWhere('key', 'automation.birthdays');
        $this->assertTrue($row['paused_now']);
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('user_id', $member->id)->count());

        // Suspension retiree : le rappel part.
        $this->putJson('/api/admin/notification-center/automations/automation.birthdays/pause', ['paused_from' => null, 'paused_until' => null])->assertOk();
        $this->artisan('app:tick', ['--only' => 'birthdays'])->assertSuccessful();
        $this->assertSame(1, UserNotification::where('user_id', $member->id)->where('type', 'birthday')->count());

        // Desactive (meme reglage que dans Reglages) ; une periode deja passee ou incoherente est refusee.
        $this->putJson('/api/admin/notification-center/automations/automation.birthdays', ['enabled' => false])->assertOk();
        $this->getJson('/api/admin/settings')->assertOk();
        $this->assertFalse(\App\Support\PlatformSettings::enabled('automation.birthdays'));
        $this->putJson('/api/admin/notification-center/automations/automation.birthdays/pause', ['paused_from' => '2026-11-01', 'paused_until' => '2026-11-02'])->assertStatus(422);
        $this->putJson('/api/admin/notification-center/automations/automation.birthdays/pause', ['paused_from' => '2026-11-20', 'paused_until' => '2026-11-12'])->assertStatus(422);
        $this->putJson('/api/admin/notification-center/automations/inconnu/pause', ['paused_from' => '2026-11-20', 'paused_until' => '2026-11-22'])->assertNotFound();
    }

    public function test_a_manual_reminder_goes_to_those_who_have_not_done_it_once_a_day(): void
    {
        $done = $this->person('Faite', $this->juda);
        $todo = $this->person('AFaire', $this->levi);
        SpiritualHealthForm::create(['user_id' => $done->id, 'period' => '2026-11', 'meditation' => 10, 'submitted_at' => now()]);
        $this->as($this->manager());
        $fiss = collect($this->getJson('/api/admin/notification-center')->json('launchers'))->firstWhere('key', 'fiss');
        $this->assertSame(2, $fiss['targets'], 'Marie... non : AFaire et le PR lui-meme (sans fiche)');

        $this->postJson('/api/admin/notification-center/launch/fiss')->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $todo->id)->where('type', 'fiss')->count());
        $this->assertSame(0, UserNotification::where('user_id', $done->id)->count());
        $this->postJson('/api/admin/notification-center/launch/fiss')->assertStatus(409);
        $this->postJson('/api/admin/notification-center/launch/inconnu')->assertNotFound();
    }

    public function test_a_custom_notification_goes_now_or_at_the_chosen_time_to_the_chosen_groups(): void
    {
        $juda = $this->person('Juda1', $this->juda);
        $levi = $this->person('Levi1', $this->levi);
        $this->as($this->manager());

        $this->postJson('/api/admin/notification-center/preview', ['scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])
            ->assertOk()->assertJsonPath('recipients', 1);
        $this->postJson('/api/admin/notification-center/messages', ['title' => 'Jeûne : prière à 6 h', 'body' => 'Rendez-vous au temple.',
            'scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])->assertCreated();
        $this->assertSame(1, UserNotification::where('user_id', $levi->id)->where('title', 'Jeûne : prière à 6 h')->count());
        $this->assertSame(0, UserNotification::where('user_id', $juda->id)->count());

        // Programmee : rien avant l'heure ; partie a l'heure par la tache de chaque minute ; annulee : rien.
        $this->postJson('/api/admin/notification-center/messages', ['title' => 'Programmée', 'scopes' => [['type' => 'church']],
            'send_at' => '2026-11-09 14:00'])->assertCreated();
        $cancelled = $this->postJson('/api/admin/notification-center/messages', ['title' => 'Annulée', 'scopes' => [['type' => 'church']],
            'send_at' => '2026-11-09 15:00'])->assertCreated()->json('notification.id');
        $this->deleteJson("/api/admin/notification-center/messages/{$cancelled}")->assertOk();
        $this->deleteJson("/api/admin/notification-center/messages/{$cancelled}")->assertStatus(409);
        $this->artisan('app:push-outbox')->assertSuccessful();
        $this->assertSame(0, UserNotification::where('title', 'Programmée')->count());
        $this->travelTo(Carbon::parse('2026-11-09 16:00'));
        $this->artisan('app:push-outbox')->assertSuccessful();
        // Toute l'eglise : les trois membres (Pasteur compris) ; le compte de gestion, auteur, n'est pas un membre.
        $this->assertSame(3, UserNotification::where('title', 'Programmée')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->manager()->id)->where('title', 'Programmée')->count());
        $this->assertSame(0, UserNotification::where('title', 'Annulée')->count());
        $this->assertSame(ScheduledNotification::SENT, ScheduledNotification::where('title', 'Programmée')->value('status'));

        // Refus : date passee, sans destinataire, groupe inexistant.
        $this->postJson('/api/admin/notification-center/messages', ['title' => 'X', 'scopes' => [['type' => 'church']], 'send_at' => '2026-11-01 10:00'])->assertStatus(422);
        $this->postJson('/api/admin/notification-center/messages', ['title' => 'X', 'scopes' => []])->assertStatus(422);
        $this->postJson('/api/admin/notification-center/messages', ['title' => 'X', 'scopes' => [['type' => 'tribe', 'id' => 999]]])->assertStatus(422);
    }

    private function person(string $first, Tribe $tribe): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418557'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now(), 'activity_status' => 'active',
            'approval_status' => User::APPROVED]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true, 'tribe_id' => $tribe->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    /** Le compte de gestion (l'administration de la plateforme), seul a ouvrir le centre. */
    private function manager(): User
    {
        return User::where('phone', '+22893471703')->whereNotNull('platform_manager_at')->first()
            ?? PlatformManager::activate('+22893471703')[0];
    }

    private function give(User $user, string $key, ?string $kind = null, ?int $id = null): User
    {
        $user->roles()->attach(Role::where('key', $key)->value('id'), ['scope_kind' => $kind, 'scope_id' => $id]);

        return $user->fresh();
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }
}
