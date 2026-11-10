<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Support\ProfileCompletion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regles de l'eglise (09/11) : pour un culte, une seule notification, la veille ; pour chaque tache a remplir,
 * deux rappels au plus (un au debut, un vers la fin), puis plus rien.
 */
class RemindersLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active', 'approval_status' => User::APPROVED]);
        $profile = Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => $this->juda->id, 'matricule' => 'VHC'.substr($phone, -4)]);
        Profile::whereKey($profile->id)->update(['created_at' => now()->subDays(3)]);
        ProfileCompletion::refresh($profile->fresh());
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function profileReminders(User $user): int
    {
        return UserNotification::where('user_id', $user->id)->where('type', 'profile')->where('title', 'like', 'Complétez votre profil%')->count();
    }

    public function test_an_incomplete_profile_gets_two_reminders_at_most_then_nothing(): void
    {
        $marie = $this->member('+14185559001', 'Marie');
        $this->assertLessThan(100, (int) $marie->profile->fresh()->completion, 'profil incomplet');

        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->assertSame(1, $this->profileReminders($marie), 'un premier rappel');

        foreach ([1, 7, 13] as $days) {
            Carbon::setTestNow(Carbon::parse('2026-11-10 10:00')->addDays($days));
            $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        }
        $this->assertSame(1, $this->profileReminders($marie), 'rien avant 2 semaines');

        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00'));
        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->assertSame(2, $this->profileReminders($marie), 'un dernier rappel');

        // Ensuite plus rien, meme des mois plus tard (et meme si le membre a efface ses notifications).
        UserNotification::where('user_id', $marie->id)->delete();
        foreach (['2026-12-08', '2027-01-05', '2027-06-01'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00'));
            $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        }
        $this->assertSame(0, $this->profileReminders($marie));
        $this->assertCount(2, $marie->fresh()->notification_prefs['profile_reminders']);
    }

    public function test_reminders_received_before_the_rule_count(): void
    {
        $paul = $this->member('+14185559002', 'Paul');
        $anne = $this->member('+14185559003', 'Anne');
        // Paul en a deja recu deux (ancien rythme : toutes les deux semaines), Anne un seul il y a 3 semaines.
        Notifier::once("profile-incomplete:{$paul->id}:2026-21");
        Notifier::once("profile-incomplete:{$paul->id}:2026-22");
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00'));
        Notifier::once("profile-incomplete:{$anne->id}:2026-21");
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00'));

        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->assertSame(0, $this->profileReminders($paul), 'deja deux : plus rien');
        $this->assertSame(1, $this->profileReminders($anne), 'un seul avant : le dernier');
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00'));
        $this->artisan('app:tick', ['--only' => 'profiles'])->assertSuccessful();
        $this->assertSame(1, $this->profileReminders($anne));
    }

    public function test_a_culte_is_never_reminded_on_the_same_day_but_other_events_still_are(): void
    {
        $member = $this->member('+14185559004', 'Luc');
        $culte = Event::create(['title' => 'Culte spécial', 'category' => 'culte', 'starts_at' => '2026-11-12 19:00:00', 'is_personal' => false]);
        $culte->syncScopes([['type' => 'church', 'id' => null]]);
        $priere = Event::create(['title' => 'Veillée de prière', 'category' => 'priere', 'starts_at' => '2026-11-12 19:00:00', 'is_personal' => false]);
        $priere->syncScopes([['type' => 'church', 'id' => null]]);
        Event::whereIn('id', [$culte->id, $priere->id])->update(['created_at' => now()->subDays(5)]);
        foreach ([$culte, $priere] as $e) {
            EventParticipation::create(['event_id' => $e->id, 'user_id' => $member->id, 'occurs_on' => '2026-11-12', 'response' => 'present']);
        }

        // La veille au soir : « Demain » pour les deux.
        Carbon::setTestNow(Carbon::parse('2026-11-11 19:05'));
        $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        // Le jour meme, peu avant : seulement la veillee (le culte a deja eu son rappel).
        foreach (['2026-11-12 10:00', '2026-11-12 18:00', '2026-11-12 18:30'] as $at) {
            Carbon::setTestNow(Carbon::parse($at));
            $this->artisan('app:tick', ['--only' => 'event-reminders'])->assertSuccessful();
        }
        $titles = UserNotification::where('user_id', $member->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(1, collect($titles)->filter(fn ($t) => str_contains($t, 'Culte spécial'))->count(), implode(' | ', $titles));
        $this->assertContains('Demain : Culte spécial', $titles);
        $this->assertContains('Bientôt : Veillée de prière', $titles);
    }
}
