<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppointmentService;
use App\Services\Monitoring\UserAdmin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Rendez-vous avec le pasteur : plages par defaut (mercredi 21h50 - 23h00, dimanche 12h50 - 14h00,
 * 15 min), reservation d'un creneau ou d'une option, file du protocole par priorite, placement
 * automatique, blocages, rappels, droits et confidentialite du message.
 */
class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $pr;

    private User $pa;

    private User $protocol;

    private User $marie;

    private User $jean;

    private User $paul;

    private const WED = '2026-10-14';

    private const SUN = '2026-10-18';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00')); // lundi
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);

        $tribe = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pr = $this->give($this->member('+14185591001', $tribe, 'Pasteur'), 'super_admin');
        $this->pa = $this->give($this->member('+14185591002', $tribe, 'Assistant'), 'pasteur_assistant');
        $this->protocol = $this->give($this->member('+14185591003', $tribe, 'Protocole'), 'protocole');
        $this->marie = $this->member('+14185591004', $tribe, 'Marie');
        $this->jean = $this->member('+14185591005', $tribe, 'Jean');
        $this->paul = $this->member('+14185591006', $tribe, 'Paul');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, ?Tribe $tribe, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme',
            'is_completed' => true, 'tribe_id' => $tribe?->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function give(User $user, string $roleKey, ?string $scopeKind = null, ?int $scopeId = null): User
    {
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'), ['scope_kind' => $scopeKind, 'scope_id' => $scopeId]);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh());
    }

    private function book(User $who, array $body)
    {
        $this->as($who);

        return $this->postJson('/api/me/appointments', $body + ['host_id' => $this->pr->id]);
    }

    private function sessionOn(array $sessions, string $date): ?array
    {
        return collect($sessions)->firstWhere('date', $date);
    }

    private function titles(User $user): array
    {
        return UserNotification::where('user_id', $user->id)->orderBy('id')->pluck('title')->all();
    }

    // ---------------------------------------------------------------- Plages par defaut

    public function test_default_slots_are_open_after_the_wednesday_and_sunday_services(): void
    {
        $this->as($this->marie);
        $r = $this->getJson('/api/me/appointments')->assertOk()
            ->assertJsonPath('host_id', $this->pr->id)->assertJsonPath('accepting', true)->assertJsonPath('slot_minutes', 15);
        $wed = $this->sessionOn($r->json('sessions'), self::WED);
        // Au moins 6 creneaux par seance : 21h50 - 23h20 le mercredi, 12h50 - 14h20 le dimanche.
        $this->assertSame('21h50 – 23h20', $wed['range']);
        $this->assertCount(6, $wed['slots']);
        $sun = $this->sessionOn($r->json('sessions'), self::SUN);
        $this->assertSame('12h50 – 14h20', $sun['range']);
        $this->assertSame(['12:50', '13:05', '13:20', '13:35', '13:50', '14:05'], array_column($sun['slots'], 'start'));
        $this->assertSame(['21:50', '22:05', '22:20', '22:35', '22:50', '23:05'], array_column($wed['slots'], 'start'));
        $this->assertSame(6, $wed['free']);
        // Ni lundi, ni mardi : pas de seance.
        $this->assertNull($this->sessionOn($r->json('sessions'), '2026-10-13'));
        // Le pasteur ne prend pas rendez-vous avec lui-meme : pas de seance proposee.
        $this->as($this->pr);
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('host_id', null)->assertJsonPath('sessions', []);
    }

    // ---------------------------------------------------------------- Reservation d'un creneau

    public function test_a_member_books_a_free_slot_and_nobody_else_can_take_it(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05', 'urgency' => 'important', 'message' => 'Pour mon mariage'])
            ->assertCreated()->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('appointment.when', 'mercredi 14 octobre à 22h05');

        // Pasteur et protocole sont prevenus.
        $this->assertContains('Nouveau rendez-vous : Marie Test', $this->titles($this->pr));
        $this->assertContains('Nouveau rendez-vous : Marie Test', $this->titles($this->protocol));
        $this->assertSame('Mercredi 14 octobre à 22h05 · Importante', UserNotification::where('user_id', $this->pr->id)->latest('id')->value('body'));

        // Le meme creneau n'est plus proposé ; une deuxieme reservation est refusee.
        $this->as($this->jean);
        $wed = $this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED);
        $this->assertSame('taken', collect($wed['slots'])->firstWhere('start', '22:05')['state']);
        $this->assertArrayNotHasKey('appointment', collect($wed['slots'])->firstWhere('start', '22:05')); // aucun nom chez un membre
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertStatus(409);
        // Une seule demande active a la fois.
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 22:20'])->assertStatus(409);
        // Un creneau qui n'existe pas (hors plage, pas aligne) est refuse.
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:10'])->assertStatus(409);
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => '2026-10-13 21:50'])->assertStatus(409);
        $this->assertSame(1, Appointment::count());
    }

    public function test_two_bookings_of_the_same_slot_cannot_both_succeed(): void
    {
        // Meme si la verification passe pour les deux, la base refuse le second (cle unique du creneau).
        $start = Carbon::parse(self::WED.' 21:50');
        AppointmentService::request($this->marie, $this->pr, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50']);
        $this->expectException(HttpException::class);
        Appointment::query()->update(['slot_key' => $this->pr->id.'|'.$start->format('Y-m-d H:i')]);
        AppointmentService::assign($this->pr, Appointment::create([
            'host_id' => $this->pr->id, 'user_id' => $this->jean->id, 'status' => 'pending', 'preference' => 'first', 'preferred_date' => self::WED,
        ]), $start);
    }

    public function test_the_message_is_read_by_the_pastor_only(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50', 'message' => 'Sujet personnel'])->assertCreated();
        $this->as($this->pr);
        $slot = collect($this->sessionOn($this->getJson('/api/admin/appointments')->assertOk()->json('sessions'), self::WED)['slots'])->firstWhere('start', '21:50');
        $this->assertSame('Sujet personnel', $slot['appointment']['message']);
        $this->assertSame('Marie Test', $slot['appointment']['member']['name']);

        $this->as($this->protocol);
        $res = $this->getJson('/api/admin/appointments')->assertOk();
        $slot = collect($this->sessionOn($res->json('sessions'), self::WED)['slots'])->firstWhere('start', '21:50');
        $this->assertNull($slot['appointment']['message']);
        // Le protocole ne voit ni le sujet ni meme qu'il y en a un (seances, liste par priorite).
        $this->assertFalse($slot['appointment']['has_message']);
        $this->assertStringNotContainsString('Sujet personnel', $res->getContent());
        $this->assertFalse($res->json('priority.0.has_message'));
        $this->assertSame('+14185591004', $slot['appointment']['member']['phone']);
    }

    public function test_a_slot_cannot_be_booked_by_a_member_less_than_30_minutes_before(): void
    {
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:30:00'));
        $this->as($this->marie);
        $wed = $this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED);
        $this->assertSame('past', collect($wed['slots'])->firstWhere('start', '21:50')['state']);
        $this->assertSame('free', collect($wed['slots'])->firstWhere('start', '22:05')['state']);
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertStatus(409);

        // « Ce jour-la » prend la premiere heure encore reservable (22h05).
        $this->book($this->jean, ['mode' => 'session', 'date' => self::WED])->assertCreated()->assertJsonPath('appointment.time', '22h05');
        // Le protocole, lui, peut encore deplacer quelqu'un au dernier moment.
        $this->as($this->protocol);
        $id = Appointment::where('user_id', $this->jean->id)->value('id');
        $this->postJson("/api/admin/appointments/{$id}/assign", ['starts_at' => self::WED.' 21:50'])->assertOk();
    }

    // ---------------------------------------------------------------- Options : confirmees aussitot

    public function test_every_booking_is_confirmed_automatically_even_without_a_precise_time(): void
    {
        // « Ce jour-la » : la premiere heure libre du jour choisi.
        $this->book($this->marie, ['mode' => 'session', 'date' => self::SUN])->assertCreated()
            ->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('appointment.when', 'dimanche 18 octobre à 12h50')
            ->assertJsonPath('message', 'Rendez-vous confirmé : dimanche 18 octobre à 12h50.');
        // « Le plus tot possible » : le premier creneau libre de toutes les seances.
        $this->book($this->jean, ['mode' => 'first', 'urgency' => 'urgent'])->assertCreated()
            ->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('appointment.when', 'mercredi 14 octobre à 21h50');
        $this->book($this->paul, ['mode' => 'session', 'date' => self::SUN, 'urgency' => 'important'])->assertCreated()
            ->assertJsonPath('appointment.when', 'dimanche 18 octobre à 13h05');
        $this->assertSame(0, Appointment::where('status', 'pending')->count());
        // Le pasteur est prevenu, en priorite haute pour une urgence.
        $this->assertContains('Nouveau rendez-vous : Jean Test', $this->titles($this->pr));
        $this->assertContains('Nouveau rendez-vous : Jean Test', $this->titles($this->protocol));

        // Jour complet : on propose une autre date.
        foreach (['+14185591007', '+14185591008', '+14185591009', '+14185591010'] as $i => $phone) {
            $this->book($this->member($phone, null, 'Extra'.$i), ['mode' => 'session', 'date' => self::SUN])->assertCreated();
        }
        $this->book($this->pa, ['mode' => 'session', 'date' => self::SUN])->assertStatus(409)
            ->assertJsonPath('message', 'Plus aucune place ce jour-là. Choisissez une autre date.');

        // Protocole : rendez-vous par ordre de priorite (urgence, puis anciennete).
        $this->as($this->protocol);
        $list = $this->getJson('/api/admin/appointments')->assertOk()->json('priority');
        $this->assertSame(['Jean Test', 'Paul Test', 'Marie Test'], array_slice(array_column(array_column($list, 'member'), 'name'), 0, 3));
        $this->assertSame([1, 2, 3], array_slice(array_column($list, 'rank'), 0, 3));
        $this->postJson("/api/admin/appointments/{$list[2]['id']}/urgency", ['urgency' => 'urgent'])->assertOk();
        $this->assertSame('Jean Test', $this->getJson('/api/admin/appointments')->json('priority.0.member.name'));
        $this->assertSame('Marie Test', $this->getJson('/api/admin/appointments')->json('priority.1.member.name'));

        // Deplacement par le protocole : creneau pris refuse, creneau libre accepte, membre prevenu.
        $this->postJson("/api/admin/appointments/{$list[2]['id']}/assign", ['starts_at' => self::WED.' 21:50'])->assertStatus(409);
        $this->postJson("/api/admin/appointments/{$list[2]['id']}/assign", ['starts_at' => self::WED.' 22:35'])->assertOk();
        $this->assertContains('Rendez-vous avec le pasteur déplacé', $this->titles($this->marie));
        $this->assertNull(Appointment::where('slot_key', $this->pr->id.'|'.self::SUN.' 12:50')->first());
    }

    public function test_the_day_list_is_sent_three_hours_before_the_session(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:20'])->assertCreated();
        Carbon::setTestNow(Carbon::parse(self::WED.' 18:40:00'));
        AppointmentService::tick();
        $this->assertNotContains('2 rendez-vous avec le pasteur aujourd\'hui', $this->titles($this->protocol));
        Carbon::setTestNow(Carbon::parse(self::WED.' 18:55:00'));
        AppointmentService::tick();
        AppointmentService::tick();
        $this->assertSame(1, UserNotification::where('user_id', $this->pr->id)->where('title', '2 rendez-vous avec le pasteur aujourd\'hui')->count());
        $this->assertSame('21h50 Marie Test · 22h20 Jean Test', UserNotification::where('user_id', $this->protocol->id)->latest('id')->value('body'));
    }

    // ---------------------------------------------------------------- Disponibilites

    public function test_blocking_a_session_moves_its_appointments_to_the_next_free_slot(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50', 'urgency' => 'urgent'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertCreated();
        $this->as($this->pr);
        $this->postJson('/api/admin/appointment-availability/blocks', ['host_id' => $this->pr->id, 'date' => self::WED, 'reason' => 'Voyage'])
            ->assertCreated()->assertJsonPath('requeued', 2);

        // Deplaces au prochain creneau libre (dimanche), toujours confirmes, la plus prioritaire d'abord.
        $marie = Appointment::where('user_id', $this->marie->id)->first();
        $this->assertSame('confirmed', $marie->status);
        $this->assertSame(self::SUN.' 12:50', $marie->starts_at->format('Y-m-d H:i'));
        $this->assertSame(self::SUN.' 13:05', Appointment::where('user_id', $this->jean->id)->first()->starts_at->format('Y-m-d H:i'));
        $this->assertContains('Rendez-vous avec le pasteur déplacé', $this->titles($this->marie));
        $this->assertStringContainsString('Nouveau rendez-vous : dimanche 18 octobre à 12h50', UserNotification::where('user_id', $this->marie->id)->latest('id')->value('body'));
        $this->assertContains('2 rendez-vous modifiés par le pasteur', $this->titles($this->protocol));

        // Plus de seance mercredi chez les membres ; de nouveau ouverte apres deblocage.
        $this->as($this->paul);
        $this->assertNull($this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED));
        $this->book($this->paul, ['mode' => 'session', 'date' => self::WED])->assertStatus(409);
        $this->as($this->pr);
        $blockId = $this->getJson('/api/admin/appointments')->json('availability.blocks.0.id');
        $this->deleteJson("/api/admin/appointment-availability/blocks/{$blockId}")->assertOk();
        $this->as($this->paul);
        $this->assertSame(6, $this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED)['free']);
    }

    public function test_without_any_free_slot_left_the_appointment_is_cancelled_and_the_member_told(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 15, 'accepting' => true, 'weekly' => []])
            ->assertOk()->assertJsonPath('requeued', 1);
        $a = Appointment::first();
        $this->assertSame('cancelled', $a->status);
        $this->assertNull($a->slot_key);
        $this->assertContains('Votre rendez-vous avec le pasteur est annulé', $this->titles($this->marie));
    }

    public function test_the_pastor_blocks_a_single_slot_or_adds_a_window(): void
    {
        $this->as($this->pr);
        $this->postJson('/api/admin/appointment-availability/blocks', ['host_id' => $this->pr->id, 'date' => self::WED, 'starts_at' => '22:05', 'ends_at' => '22:20'])->assertCreated();
        $this->postJson('/api/admin/appointment-availability/windows', ['host_id' => $this->pr->id, 'date' => '2026-10-17', 'starts_at' => '10:00', 'ends_at' => '11:00'])->assertCreated();
        // Chevauchement, date passee, fin avant debut : refuses.
        $this->postJson('/api/admin/appointment-availability/windows', ['host_id' => $this->pr->id, 'date' => '2026-10-17', 'starts_at' => '10:30', 'ends_at' => '12:00'])->assertStatus(422);
        $this->postJson('/api/admin/appointment-availability/windows', ['host_id' => $this->pr->id, 'date' => self::WED, 'starts_at' => '22:00', 'ends_at' => '22:30'])->assertStatus(422);
        $this->postJson('/api/admin/appointment-availability/windows', ['host_id' => $this->pr->id, 'date' => '2026-10-10', 'starts_at' => '10:00', 'ends_at' => '11:00'])->assertStatus(422);
        $this->postJson('/api/admin/appointment-availability/windows', ['host_id' => $this->pr->id, 'date' => '2026-10-17', 'starts_at' => '15:00', 'ends_at' => '14:00'])->assertStatus(422);

        $this->as($this->marie);
        $sessions = $this->getJson('/api/me/appointments')->json('sessions');
        $this->assertSame(['free', 'taken', 'free', 'free', 'free', 'free'], array_column($this->sessionOn($sessions, self::WED)['slots'], 'state'));
        // Plage ajoutee de 10 h a 11 h : prolongee pour offrir 6 rendez-vous (jusqu'a 11h30).
        $this->assertSame(['10:00', '10:15', '10:30', '10:45', '11:00', '11:15'], array_column($this->sessionOn($sessions, '2026-10-17')['slots'], 'start'));
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => '2026-10-17 10:15'])->assertCreated();
        // Le creneau bloque n'est jamais attribue automatiquement.
        $this->book($this->jean, ['mode' => 'session', 'date' => self::WED])->assertCreated()->assertJsonPath('appointment.time', '21h50');
        $this->book($this->paul, ['mode' => 'session', 'date' => self::WED])->assertCreated()->assertJsonPath('appointment.time', '22h20');
    }

    public function test_changing_the_weekly_windows_or_the_duration_keeps_bookings_consistent(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::SUN.' 13:05'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertCreated();

        // Rendez-vous de 20 min et plus de dimanche : le rendez-vous du dimanche passe au mercredi suivant.
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 20, 'accepting' => true,
            'weekly' => [['weekday' => 3, 'starts_at' => '21:50', 'ends_at' => '23:00']]])->assertOk()->assertJsonPath('requeued', 1);
        $marie = Appointment::where('user_id', $this->marie->id)->first();
        $this->assertSame('confirmed', $marie->status);
        $this->assertSame('2026-10-21 21:50', $marie->starts_at->format('Y-m-d H:i'));
        $this->assertSame(self::WED.' 22:05', Appointment::where('user_id', $this->jean->id)->first()->starts_at->format('Y-m-d H:i'));

        // Mercredi : creneaux de 20 min ; ceux qui chevauchent le rendez-vous de 22h05 restent pris.
        $this->as($this->paul);
        $wed = $this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED);
        $this->assertSame(['21:50', '22:10', '22:30', '22:50', '23:10', '23:30'], array_column($wed['slots'], 'start'));
        $this->assertSame(['taken', 'taken', 'free', 'free', 'free', 'free'], array_column($wed['slots'], 'state'));
        $this->book($this->paul, ['mode' => 'slot', 'starts_at' => self::WED.' 22:10'])->assertStatus(409);
        $this->book($this->paul, ['mode' => 'slot', 'starts_at' => self::WED.' 22:30'])->assertCreated();

        // Deux plages qui se chevauchent le meme jour : refuse.
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 15, 'accepting' => true,
            'weekly' => [['weekday' => 3, 'starts_at' => '21:50', 'ends_at' => '23:00'], ['weekday' => 3, 'starts_at' => '22:30', 'ends_at' => '23:30']]])->assertStatus(422);
    }

    public function test_a_second_pastor_appears_only_when_he_takes_appointments(): void
    {
        $other = $this->give($this->member('+14185591009', null, 'Second'), 'super_admin');
        $this->as($this->marie);
        $this->assertCount(2, $this->getJson('/api/me/appointments')->json('hosts'));

        // Le PR ferme les rendez-vous de l'autre compte : il disparait chez les membres.
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $other->id, 'slot_minutes' => 15, 'accepting' => false, 'weekly' => []])->assertOk();
        $this->as($this->marie);
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonCount(1, 'hosts')->assertJsonPath('host_id', $this->pr->id);
        $this->getJson('/api/me/appointments?host='.$other->id)->assertNotFound();
        $this->postJson('/api/me/appointments', ['host_id' => $other->id, 'mode' => 'first'])->assertStatus(409);
    }

    public function test_appointments_can_be_paused(): void
    {
        $this->as($this->pr);
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 15, 'accepting' => false,
            'weekly' => [['weekday' => 3, 'starts_at' => '21:50', 'ends_at' => '23:00']]])->assertOk();
        $this->as($this->marie);
        $this->getJson('/api/me/appointments')->assertOk()->assertJsonPath('accepting', false)->assertJsonPath('sessions', []);
        $this->book($this->marie, ['mode' => 'first'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- Annulation, suite, rappels

    public function test_cancellations_free_the_slot_and_notify_the_other_side(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $id = Appointment::value('id');

        // Personne d'autre que le membre ne passe par son espace pour annuler.
        $this->as($this->jean);
        $this->postJson("/api/me/appointments/{$id}/cancel")->assertNotFound();
        $this->as($this->marie);
        $this->postJson("/api/me/appointments/{$id}/cancel")->assertOk();
        $this->assertContains('Rendez-vous annulé : Marie Test', $this->titles($this->pr));
        // Creneau de nouveau libre, nouvelle demande possible.
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertCreated();

        // Le protocole annule avec un motif, communique au membre.
        $this->as($this->protocol);
        $jeanId = Appointment::where('user_id', $this->jean->id)->where('status', 'confirmed')->value('id');
        $this->postJson("/api/admin/appointments/{$jeanId}/cancel", ['reason' => 'Le pasteur est en déplacement'])->assertOk();
        $this->assertSame('Votre rendez-vous avec le pasteur est annulé', UserNotification::where('user_id', $this->jean->id)->latest('id')->value('title'));
        $this->assertSame('Mercredi 14 octobre à 21h50 · Le pasteur est en déplacement Vous pouvez reprendre rendez-vous sur un autre créneau.', UserNotification::where('user_id', $this->jean->id)->latest('id')->value('body'));
        // Revocation sans motif : message par defaut.
        $marieId = Appointment::where('user_id', $this->marie->id)->where('status', 'confirmed')->value('id');
        $this->postJson("/api/admin/appointments/{$marieId}/cancel", [])->assertOk();
        $this->assertStringContainsString("Le pasteur n'est pas disponible à ce moment.", UserNotification::where('user_id', $this->marie->id)->latest('id')->value('body'));
    }

    public function test_reminders_outcomes_and_automatic_closing(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:35'])->assertCreated();
        $id = Appointment::where('user_id', $this->marie->id)->value('id');

        // Avant le rendez-vous : impossible de le marquer comme fait.
        $this->as($this->protocol);
        $this->postJson("/api/admin/appointments/{$id}/outcome", ['status' => 'done'])->assertStatus(409);

        // Le jour meme, des 8 h : un seul rappel.
        Carbon::setTestNow(Carbon::parse(self::WED.' 07:30:00'));
        AppointmentService::tick();
        $this->assertNotContains('Rendez-vous avec le pasteur aujourd\'hui à 21h50', $this->titles($this->marie));
        Carbon::setTestNow(Carbon::parse(self::WED.' 08:05:00'));
        AppointmentService::tick();
        AppointmentService::tick();
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('title', 'Rendez-vous avec le pasteur aujourd\'hui à 21h50')->count());

        // Apres : le protocole note une absence.
        Carbon::setTestNow(Carbon::parse(self::WED.' 22:10:00'));
        $this->as($this->protocol);
        $this->postJson("/api/admin/appointments/{$id}/outcome", ['status' => 'no_show'])->assertOk();
        $this->assertSame('no_show', Appointment::find($id)->status);

        // Sans suite donnee, un rendez-vous passe se clot tout seul (fait).
        Carbon::setTestNow(Carbon::parse('2026-10-15 06:00:00'));
        AppointmentService::tick();
        $this->assertSame('done', Appointment::where('user_id', $this->jean->id)->value('status'));
    }

    // ---------------------------------------------------------------- Creneaux qui se suivent, appel du suivant

    public function test_slots_follow_each_other_without_any_break(): void
    {
        foreach ([15, 20, 10] as $minutes) {
            $this->as($this->pr);
            $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => $minutes, 'accepting' => true,
                'weekly' => [['weekday' => 3, 'starts_at' => '21:50', 'ends_at' => '23:00']]])->assertOk();
            $this->as($this->marie);
            $slots = $this->sessionOn($this->getJson('/api/me/appointments')->json('sessions'), self::WED)['slots'];
            $this->assertSame('21:50', $slots[0]['start']);
            foreach (array_slice($slots, 1) as $i => $slot) {
                $this->assertSame($slots[$i]['end'], $slot['start'], "{$minutes} min : pas de pause entre deux rendez-vous");
            }
            // Au moins 6 rendez-vous : la fin deborde si la plage est trop courte (15 et 20 min), sinon 23 h.
            $this->assertGreaterThanOrEqual(6, count($slots));
            $this->assertSame(['15' => '23:20', '20' => '23:50', '10' => '23:00'][(string) $minutes], end($slots)['end']);
        }
    }

    public function test_when_someone_leaves_the_next_person_is_called_right_away(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertCreated();
        $this->book($this->paul, ['mode' => 'slot', 'starts_at' => self::WED.' 22:20'])->assertCreated();
        [$m, $j, $p] = [$this->marie, $this->jean, $this->paul];
        $id = fn (User $u) => Appointment::where('user_id', $u->id)->value('id');

        // Un autre jour : impossible de marquer la fin.
        $this->as($this->protocol);
        $this->postJson("/api/admin/appointments/{$id($m)}/outcome", ['status' => 'done'])->assertStatus(409);

        // Marie sort a 21h58, avant la fin de ses 15 min : Jean est appele aussitot.
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:58:00'));
        $this->postJson("/api/admin/appointments/{$id($m)}/outcome", ['status' => 'done'])->assertOk()
            ->assertJsonPath('called', 'Jean Test')->assertJsonPath('message', 'Terminé. Jean Test est appelé(e) : c\'est son tour.');
        $this->assertSame(1, UserNotification::where('user_id', $j->id)->where('title', 'C\'est votre tour')->count());
        // Marie reste visible (recue) pour le protocole.
        $slot = collect($this->sessionOn($this->getJson('/api/admin/appointments')->json('sessions'), self::WED)['slots'])->firstWhere('start', '21:50');
        $this->assertSame('done', $slot['state']);
        $this->assertSame('Marie Test', $slot['appointment']['member']['name']);

        // Rappel manuel : une fois par minute au plus.
        $this->postJson("/api/admin/appointments/{$id($j)}/call")->assertOk()->assertJsonPath('message', 'Jean Test est appelé(e).');
        $this->postJson("/api/admin/appointments/{$id($j)}/call")->assertOk()->assertJsonPath('message', 'Déjà appelé(e) à l\'instant.');
        $this->assertSame(2, UserNotification::where('user_id', $j->id)->where('title', 'C\'est votre tour')->count());

        // Jean absent : Paul est appele.
        Carbon::setTestNow(Carbon::parse(self::WED.' 22:03:00'));
        $this->postJson("/api/admin/appointments/{$id($j)}/outcome", ['status' => 'no_show'])->assertOk()->assertJsonPath('called', 'Paul Test');
        $this->assertSame(1, UserNotification::where('user_id', $p->id)->where('title', 'C\'est votre tour')->count());
        // Dernier de la soiree : personne a appeler.
        $this->postJson("/api/admin/appointments/{$id($p)}/outcome", ['status' => 'done'])->assertOk()->assertJsonPath('called', null);
        // Corriger une suite deja donnee n'appelle personne une seconde fois.
        $this->postJson("/api/admin/appointments/{$id($j)}/outcome", ['status' => 'done'])->assertOk()->assertJsonPath('called', null);
    }

    public function test_the_next_person_is_the_first_still_awaited_and_not_someone_hours_later(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 22:05'])->assertCreated();
        $this->book($this->paul, ['mode' => 'slot', 'starts_at' => self::WED.' 22:20'])->assertCreated();

        // Ordre bouscule : Paul passe avant Jean ; c'est Jean (toujours attendu) qui est appele, pas personne apres Paul.
        Carbon::setTestNow(Carbon::parse(self::WED.' 21:52:00'));
        $this->as($this->protocol);
        $paulId = Appointment::where('user_id', $this->paul->id)->value('id');
        $this->postJson("/api/admin/appointments/{$paulId}/outcome", ['status' => 'done'])->assertOk()->assertJsonPath('called', 'Marie Test');

        // A midi, pour un rendez-vous du soir : personne n'est appele.
        Carbon::setTestNow(Carbon::parse(self::WED.' 12:00:00'));
        $marieId = Appointment::where('user_id', $this->marie->id)->value('id');
        $this->postJson("/api/admin/appointments/{$marieId}/outcome", ['status' => 'no_show'])->assertOk()->assertJsonPath('called', null);
        $this->assertSame(0, UserNotification::where('user_id', $this->jean->id)->where('title', 'C\'est votre tour')->count());
    }

    // ---------------------------------------------------------------- Droits

    public function test_only_the_pastor_and_the_protocol_organize_and_only_the_pastor_manages_his_availability(): void
    {
        $this->as($this->marie);
        $this->getJson('/api/admin/appointments')->assertForbidden();
        $this->as($this->pa);
        $this->getJson('/api/admin/appointments')->assertForbidden();

        $this->book($this->marie, ['mode' => 'first'])->assertCreated();
        $id = Appointment::value('id');
        foreach ([$this->marie, $this->jean, $this->pa] as $who) {
            $this->as($who);
            $this->postJson("/api/admin/appointments/{$id}/assign", ['starts_at' => self::WED.' 21:50'])->assertForbidden();
        }

        // Le protocole organise mais ne touche pas aux disponibilites.
        $this->as($this->protocol);
        $this->getJson('/api/admin/appointments')->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('availability', null);
        $this->postJson('/api/admin/appointment-availability/blocks', ['host_id' => $this->pr->id, 'date' => self::WED])->assertForbidden();
        $this->putJson('/api/admin/appointment-availability', ['host_id' => $this->pr->id, 'slot_minutes' => 15, 'accepting' => false, 'weekly' => []])->assertForbidden();

        $this->as($this->pr);
        $this->getJson('/api/admin/appointments')->assertOk()->assertJsonPath('can_manage', true)->assertJsonPath('is_host', true)
            ->assertJsonPath('availability.slot_minutes', 15)->assertJsonCount(2, 'availability.weekly');
    }

    public function test_deleting_a_member_removes_his_appointments_and_frees_the_slot(): void
    {
        $this->book($this->marie, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
        UserAdmin::delete($this->marie);
        $this->assertSame(0, Appointment::count());
        $this->book($this->jean, ['mode' => 'slot', 'starts_at' => self::WED.' 21:50'])->assertCreated();
    }
}
