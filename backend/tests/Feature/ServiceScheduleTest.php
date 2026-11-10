<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Programme hebdomadaire des cultes : calendrier, recapitulatif de la veille, rappel avant chaque rendez-vous. */
class ServiceScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $sunday;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Un dimanche posterieur au debut des series creees par la migration.
        $this->sunday = Event::where('title', 'Culte de contemplation et de célébration')->firstOrFail()
            ->starts_at->copy()->addWeeks(2)->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $user->id, 'first_name' => 'Membre', 'last_name' => substr($phone, -3), 'is_completed' => true]);

        return $user;
    }

    public function test_weekly_services_are_in_everyones_calendar(): void
    {
        $events = Event::where('remind_all', true)->orderBy('starts_at')->get();
        $this->assertCount(6, $events); // mercredi, samedi des miracles, 4 rendez-vous du dimanche
        $this->assertTrue($events->every(fn (Event $e) => $e->recurrence === 'weekly' && $e->isForWholeChurch()));

        $member = $this->member('+14185557001');
        Sanctum::actingAs($member);
        $wednesday = $this->sunday->copy()->subDays(4)->toDateString();
        $res = $this->getJson('/api/calendar?from='.$wednesday.'&to='.$this->sunday->toDateString())->assertOk();
        $titles = collect($res->json('events'))->map(fn ($e) => substr($e['starts_at'], 11, 5).' '.$e['title'])->all();

        $this->assertContains("19:30 Mercredi de l'intercession", $titles);
        // Mercredi : sans heure de fin.
        $this->assertNull(collect($res->json('events'))->firstWhere('title', "Mercredi de l'intercession")['ends_at']);
        $this->assertContains("08:45 Temps de prière & d'intercession", $titles);
        $this->assertContains('09:30 Culte de contemplation et de célébration', $titles);
        $this->assertContains('12:30 Healing Time', $titles);
        $this->assertContains('13:00 Bloom Light · Coin cocktail fraternel', $titles);
        $this->assertTrue(collect($res->json('events'))->every(fn ($e) => $e['remind_all'] === true));
    }

    public function test_the_evening_before_everyone_gets_one_program_summary_once(): void
    {
        $a = $this->member('+14185557002');
        $b = $this->member('+14185557003');
        $culte = Event::where('title', 'Culte de contemplation et de célébration')->first();
        EventParticipation::create(['event_id' => $culte->id, 'user_id' => $b->id, 'occurs_on' => $this->sunday->toDateString(), 'response' => 'absent']);

        // Samedi matin : pas de rappel « Demain » par rendez-vous.
        Carbon::setTestNow($this->sunday->copy()->subDay()->setTime(9, 45));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        $this->assertSame(0, UserNotification::whereIn('user_id', [$a->id, $b->id])->count());

        // Samedi 18 h : un seul recapitulatif, sans doublon au passage suivant.
        Carbon::setTestNow($this->sunday->copy()->subDay()->setTime(18, 5));
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();

        $forA = UserNotification::where('user_id', $a->id)->get();
        $this->assertCount(1, $forA);
        $this->assertSame('Demain dimanche : programme du culte', $forA[0]->title);
        $this->assertSame("08h45 Temps de prière & d'intercession · 09h30 Culte de contemplation et de célébration · 12h30 Healing Time · 13h00 Bloom Light · Coin cocktail fraternel", $forA[0]->body);

        // Absent declare au culte : il n'apparait pas dans son programme.
        $forB = UserNotification::where('user_id', $b->id)->get();
        $this->assertCount(1, $forB);
        $this->assertStringNotContainsString('Culte de contemplation', $forB[0]->body);
    }

    public function test_a_service_is_reminded_only_the_day_before_never_on_the_same_day(): void
    {
        $member = $this->member('+14185557004');

        // Regle de l'eglise : une seule notification par culte, la veille. Le jour meme : rien, a aucune heure.
        foreach ([[7, 0], [8, 15], [9, 0], [12, 0], [12, 30], [18, 0]] as [$h, $m]) {
            Carbon::setTestNow($this->sunday->copy()->setTime($h, $m));
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        }
        $this->assertSame(0, UserNotification::where('user_id', $member->id)->count(), 'aucun rappel le jour meme');

        // La veille au soir : le programme du lendemain, une seule fois.
        Carbon::setTestNow($this->sunday->copy()->subDay()->setTime(18, 5));
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        $this->assertSame(['Demain dimanche : programme du culte'], UserNotification::where('user_id', $member->id)->pluck('title')->all());
    }

    public function test_leader_can_make_a_recurring_event_a_service_with_reminders_for_all(): void
    {
        $pastor = $this->member('+14185557005');
        $pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        Sanctum::actingAs($pastor);

        $this->postJson('/api/admin/events', [
            'title' => 'Répétition générale', 'category' => 'autre', 'starts_at' => $this->sunday->copy()->setTime(15, 0)->toDateTimeString(),
            'recurrence' => 'weekly', 'remind_all' => true, 'scopes' => [['type' => 'church', 'id' => null]],
        ])->assertCreated();
        $this->assertTrue(Event::where('title', 'Répétition générale')->value('remind_all'));

        // Un evenement ponctuel ne peut pas etre un rendez-vous regulier.
        $this->postJson('/api/admin/events', [
            'title' => 'Concert', 'category' => 'autre', 'starts_at' => $this->sunday->copy()->setTime(18, 0)->toDateTimeString(),
            'remind_all' => true, 'scopes' => [['type' => 'church', 'id' => null]],
        ])->assertCreated();
        $this->assertFalse(Event::where('title', 'Concert')->value('remind_all'));
    }

    public function test_a_muted_service_stays_in_the_calendar_but_sends_nothing(): void
    {
        $bloom = Event::where('title', 'Bloom Light · Coin cocktail fraternel')->firstOrFail();
        $bloom->update(['muted' => true]);
        $member = $this->member('+14185557006');
        $date = $this->sunday->toDateString();
        EventParticipation::create(['event_id' => $bloom->id, 'user_id' => $member->id, 'occurs_on' => $date, 'response' => 'present']);

        // Toujours au calendrier.
        Sanctum::actingAs($member);
        $res = $this->getJson('/api/calendar?from='.$date.'&to='.$date)->assertOk();
        $this->assertContains('Bloom Light · Coin cocktail fraternel', collect($res->json('events'))->pluck('title')->all());

        // Programme de la veille sans Bloom Light ; aucun rappel « Bientôt » pour lui.
        Carbon::setTestNow($this->sunday->copy()->subDay()->setTime(18, 5));
        $this->artisan('app:tick', ['--only' => 'service-digest'])->assertSuccessful();
        foreach ([[8, 15], [9, 0], [12, 0], [12, 30]] as [$h, $m]) {
            Carbon::setTestNow($this->sunday->copy()->setTime($h, $m));
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        }
        $sent = UserNotification::where('user_id', $member->id)->orderBy('id')->get();
        $this->assertSame("08h45 Temps de prière & d'intercession · 09h30 Culte de contemplation et de célébration · 12h30 Healing Time", $sent[0]->body);
        // Une seule notification : le programme de la veille (rien le jour meme).
        $this->assertSame(['Demain dimanche : programme du culte'], $sent->pluck('title')->all());

        // Ni « Événement modifié » ni « Événement annulé » pour l'inscrit.
        Carbon::setTestNow($this->sunday->copy()->subDays(3)->setTime(10, 0));
        $pastor = $this->member('+14185557007');
        $pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        Sanctum::actingAs($pastor);
        $before = UserNotification::where('user_id', $member->id)->count();
        $this->putJson('/api/admin/events/'.$bloom->id, ['location' => 'Salle du bas'])->assertOk();
        $this->deleteJson('/api/admin/events/'.$bloom->id)->assertOk();
        $this->assertSame($before, UserNotification::where('user_id', $member->id)->count());
    }

    public function test_a_muted_event_gets_no_reminder(): void
    {
        $member = $this->member('+14185557008');
        // Deux evenements identiques, l'un aux rappels coupes.
        foreach (['Pique-nique' => false, 'Sortie au parc' => true] as $title => $muted) {
            $event = Event::create(['title' => $title, 'category' => 'sortie', 'starts_at' => $this->sunday->copy()->setTime(15, 0),
                'recurrence' => 'none', 'is_personal' => false, 'muted' => $muted]);
            $event->syncScopes([['type' => 'church', 'id' => null]]);
            EventParticipation::create(['event_id' => $event->id, 'user_id' => $member->id, 'occurs_on' => $this->sunday->toDateString(), 'response' => 'present']);
        }

        // Veille au soir, puis peu avant.
        foreach ([$this->sunday->copy()->subDay()->setTime(19, 0), $this->sunday->copy()->setTime(14, 15)] as $at) {
            Carbon::setTestNow($at);
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        }
        $titles = UserNotification::where('user_id', $member->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(['Demain : Pique-nique', 'Bientôt : Pique-nique'], $titles);
    }
}
