<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Exercise;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Absence d'une semaine (courriel au membre, liste au patriarche et a l'AP) et suivi des exercices donnes
 * par les patriarches (historique et avancement pour l'AP de la tribu, le PR et le PA ; jamais hors portee).
 */
class AbsenceAndExerciseFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Tribe $levi;

    private User $pr;

    private User $pa;

    private User $ap;

    private User $apLevi;

    private User $patriarch;

    private User $marie;

    private User $jean;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        Http::preventStrayRequests();
        $this->withoutDefer();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.url' => 'https://espace.vasesdhonneurchicoutimi.org', 'mail.test_to' => null]);

        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->pr = $this->give($this->member('+14185594001', 'Pasteur', null), 'super_admin');
        $this->pa = $this->give($this->member('+14185594002', 'Assistant', null), 'pasteur_assistant');
        $this->ap = $this->give($this->member('+14185594003', 'Ap', null), 'assistant_pasteur', 'tribe', $this->juda->id);
        $this->apLevi = $this->give($this->member('+14185594004', 'Aplevi', null, $this->levi), 'assistant_pasteur', 'tribe', $this->levi->id);
        $this->patriarch = $this->give($this->member('+14185594005', 'Patriarche', null), 'patriarche', 'tribe', $this->juda->id);
        $this->marie = $this->member('+14185594006', 'Marie', 'marie@exemple.test');
        $this->jean = $this->member('+14185594007', 'Jean', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, ?string $email, ?Tribe $tribe = null): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'last_seen_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Test', 'gender' => 'femme', 'email' => $email,
            'is_completed' => true, 'tribe_id' => ($tribe ?? $this->juda)->id, 'matricule' => 'VHC'.substr($phone, -4)]);
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

    // ---------------------------------------------------------------- Absence d'une semaine

    public function test_a_member_absent_for_more_than_a_week_gets_one_email_and_the_tribe_leaders_are_told(): void
    {
        $this->seenDaysAgo($this->marie, 9);
        $this->seenDaysAgo($this->jean, 3);
        $old = $this->member('+14185594008', 'Ancien', 'ancien@exemple.test');
        $this->seenDaysAgo($old, 45); // au-dela de 30 jours : le suivi d'inactivite prend le relais

        // Avant 10 h : rien.
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(0, OutboundMessage::where('kind', 'absence')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $mail = OutboundMessage::where('kind', 'absence')->sole();
        $this->assertSame('marie@exemple.test', $mail->recipient);
        $this->assertSame('Votre espace My vasesdhonneur vous attend', $mail->subject);
        $html = (new NotificationMail($mail->subject, $mail->view, $mail->payload))->render();
        $this->assertStringContainsString('Vous nous manquez, Marie !', $html);
        $this->assertStringContainsString('Vous ne vous êtes pas connectée à My vasesdhonneur depuis <strong>9 jours</strong>', $html);
        $this->assertSame(1, UserNotification::where('user_id', $this->marie->id)->where('title', 'Votre espace vous attend')->count());

        // Patriarche et AP de la tribu : la liste du jour ; pas l'AP d'une autre tribu.
        foreach ([$this->patriarch, $this->ap] as $leader) {
            $this->assertSame(1, UserNotification::where('user_id', $leader->id)->where('title', 'Marie Test : aucune connexion depuis une semaine')->count());
        }
        $this->assertSame(0, UserNotification::where('user_id', $this->apLevi->id)->where('type', 'activity')->count());

        // Une seule fois par absence, meme les jours suivants.
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(1, OutboundMessage::where('kind', 'absence')->count());

        // Elle revient, puis s'absente de nouveau plus d'une semaine : nouveau courriel.
        $this->marie->forceFill(['last_seen_at' => now()])->saveQuietly();
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(2, OutboundMessage::where('kind', 'absence')->where('recipient', 'marie@exemple.test')->count());
    }

    public function test_the_member_list_shows_who_has_been_away_for_a_week(): void
    {
        $this->seenDaysAgo($this->marie, 9);
        Sanctum::actingAs($this->patriarch);
        $list = $this->getJson('/api/admin/members?absent=1')->assertOk()->json('members');
        $this->assertSame(['Marie Test'], array_column($list, 'full_name'));
        $this->assertSame(9, $list[0]['absent_days']);
        Sanctum::actingAs($this->apLevi);
        $this->assertSame([], $this->getJson('/api/admin/members?absent=1')->assertOk()->json('members'));
    }

    public function test_pastors_are_not_sent_the_absence_email(): void
    {
        $this->pr->profile->update(['email' => 'pasteur@exemple.test']);
        $this->seenDaysAgo($this->pr, 10);
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:05:00'));
        $this->artisan('app:tick', ['--only' => 'absences'])->assertSuccessful();
        $this->assertSame(0, OutboundMessage::where('kind', 'absence')->count());
    }

    // ---------------------------------------------------------------- Exercices des patriarches

    public function test_the_tribe_ap_the_pr_and_the_pa_follow_the_exercises_given_by_a_patriarch(): void
    {
        Sanctum::actingAs($this->patriarch);
        $id = $this->postJson('/api/admin/exercises', ['title' => 'Méditer Jean 3:16', 'content' => 'Écrivez ce que ce verset vous dit.', 'type' => 'reflexion',
            'scopes' => [['type' => 'tribe', 'id' => $this->juda->id]]])->assertCreated()->json('id');
        Sanctum::actingAs($this->marie);
        $this->postJson("/api/me/exercises/{$id}/respond", ['response' => 'Dieu a tant aimé le monde.'])->assertOk();

        foreach ([$this->ap, $this->pr, $this->pa] as $viewer) {
            Sanctum::actingAs($viewer->fresh());
            $item = collect($this->getJson('/api/admin/exercises')->assertOk()->json('exercises'))->firstWhere('id', $id);
            $this->assertNotNull($item, 'Historique visible');
            $this->assertSame(['name' => 'Patriarche Test', 'role' => 'Patriarche'], $item['author']);
            $this->assertSame(1, $item['done_count']);
            $tracking = $this->getJson("/api/admin/exercises/{$id}/tracking")->assertOk();
            $this->assertSame('done', collect($tracking->json('members'))->firstWhere('name', 'Marie Test')['status']);
            $this->assertSame('Dieu a tant aimé le monde.', collect($tracking->json('members'))->firstWhere('name', 'Marie Test')['response']);
            // Historique d'un membre.
            $this->getJson("/api/admin/members/{$this->marie->id}/exercises")->assertOk()
                ->assertJsonPath('exercises.0.title', 'Méditer Jean 3:16')->assertJsonPath('exercises.0.status', 'done');
        }

        // L'AP d'une autre tribu ne voit rien.
        Sanctum::actingAs($this->apLevi);
        $this->assertNotContains($id, array_column($this->getJson('/api/admin/exercises')->json('exercises'), 'id'));
        $this->getJson("/api/admin/exercises/{$id}/tracking")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->marie->id}/exercises")->assertForbidden();
        $this->postJson("/api/admin/exercises/{$id}/remind")->assertForbidden();

        // Filtres : en cours / fermes, les miens, recherche.
        Sanctum::actingAs($this->ap->fresh());
        $this->assertSame([$id], array_column($this->getJson('/api/admin/exercises?status=open')->json('exercises'), 'id'));
        $this->assertSame([], $this->getJson('/api/admin/exercises?status=closed')->json('exercises'));
        $this->assertSame([], $this->getJson('/api/admin/exercises?mine=1')->json('exercises'));
        $this->assertSame([$id], array_column($this->getJson('/api/admin/exercises?q=Jean')->json('exercises'), 'id'));

        // Relancer ceux qui n'ont pas fini (une fois par jour).
        $this->postJson("/api/admin/exercises/{$id}/remind")->assertOk()->assertJsonPath('count', 4);
        $this->assertSame(1, UserNotification::where('user_id', $this->jean->id)->where('title', 'Rappel : Méditer Jean 3:16')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->marie->id)->where('title', 'Rappel : Méditer Jean 3:16')->count());
        $this->postJson("/api/admin/exercises/{$id}/remind")->assertStatus(429);
    }

    public function test_an_exercise_reaching_beyond_a_leader_scope_only_shows_his_members(): void
    {
        $levite = $this->member('+14185594009', 'Levite', null, $this->levi);
        Sanctum::actingAs($this->pr);
        $id = $this->postJson('/api/admin/exercises', ['title' => 'Lecture', 'content' => 'Lire Psaume 23.', 'type' => 'lecture',
            'scopes' => [['type' => 'tribe', 'id' => $this->juda->id], ['type' => 'tribe', 'id' => $this->levi->id]]])->assertCreated()->json('id');

        Sanctum::actingAs($this->apLevi);
        $names = array_column($this->getJson("/api/admin/exercises/{$id}/tracking")->assertOk()->json('members'), 'name');
        $this->assertContains('Levite Test', $names);
        $this->assertNotContains('Marie Test', $names);
        $item = collect($this->getJson('/api/admin/exercises')->json('exercises'))->firstWhere('id', $id);
        $this->assertSame(2, $item['audience_count']); // lui-meme et le levite
        $this->assertFalse($item['can_delete']);
        $this->deleteJson("/api/admin/exercises/{$id}")->assertForbidden();
        $this->assertNotNull(Exercise::find($id));
        unset($levite);
    }
}
