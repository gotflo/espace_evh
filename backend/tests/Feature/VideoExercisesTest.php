<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseVideoView;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\YouTube;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoExercisesTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://www.youtube.com/watch?v=KSvbDy7Yv7k&list=PL5zw7CK5_R7QBy01F2L7tLwu61ZfqoOXi&index=2&pp=iAQB';

    private Tribe $juda;

    private Tribe $levi;

    private User $patriarch;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->levi = Tribe::create(['name' => 'Lévi', 'slug' => 'levi']);
        $this->patriarch = $this->member('+14185558001', $this->juda, 'Patriarche');
        $this->patriarch->roles()->attach(Role::where('key', 'patriarche')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => $this->juda->id]);
        $this->member = $this->member('+14185558002', $this->juda, 'Marie');
        $this->other = $this->member('+14185558003', $this->levi, 'Paul');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, Tribe $tribe, string $first): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'is_completed' => true, 'tribe_id' => $tribe->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function publish(array $overrides = []): Exercise
    {
        Sanctum::actingAs($this->patriarch);
        $this->postJson('/api/admin/exercises', array_merge([
            'title' => 'Enseignement : la foi', 'type' => 'video', 'video_url' => self::LINK, 'video_duration' => 600,
            'content' => 'Notez les trois points clés.', 'requires_response' => true,
            'closes_at' => '2026-10-08 21:00', 'scopes' => [['type' => 'tribe', 'id' => $this->juda->id]],
        ], $overrides))->assertCreated();

        return Exercise::latest('id')->firstOrFail();
    }

    private function beat(Exercise $exercise, array $payload, int $afterSeconds = 15)
    {
        Carbon::setTestNow(now()->addSeconds($afterSeconds));
        Sanctum::actingAs($this->member);

        return $this->postJson("/api/me/exercises/{$exercise->id}/progress", $payload + ['duration' => 600, 'rate' => 1]);
    }

    public function test_youtube_links_are_reduced_to_a_video_id(): void
    {
        $this->assertSame('KSvbDy7Yv7k', YouTube::videoId(self::LINK));
        $this->assertSame('KSvbDy7Yv7k', YouTube::videoId('https://youtu.be/KSvbDy7Yv7k?t=42'));
        $this->assertSame('KSvbDy7Yv7k', YouTube::videoId('youtube.com/shorts/KSvbDy7Yv7k'));
        $this->assertSame('KSvbDy7Yv7k', YouTube::videoId('https://www.youtube.com/embed/KSvbDy7Yv7k'));
        $this->assertSame('KSvbDy7Yv7k', YouTube::videoId('https://m.youtube.com/watch?v=KSvbDy7Yv7k'));
        $this->assertNull(YouTube::videoId('https://evil.example/watch?v=KSvbDy7Yv7k'));
        $this->assertNull(YouTube::videoId('javascript:alert(1)'));
        $this->assertNull(YouTube::videoId('https://www.youtube.com/watch?v=<script>'));
        $this->assertNull(YouTube::videoId('https://www.youtube.com/playlist?list=PL5zw7CK5_R7QBy01F2L7tLwu61ZfqoOXi'));
    }

    public function test_preview_reads_the_title_and_detects_videos_that_cannot_be_embedded(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::sequence()
                ->push(['title' => 'La foi qui déplace les montagnes', 'author_name' => 'VH Chicoutimi'])
                ->push('Unauthorized', 401),
        ]);
        Sanctum::actingAs($this->patriarch);

        $this->postJson('/api/admin/exercises/video-preview', ['url' => self::LINK])->assertOk()
            ->assertJson(['video_id' => 'KSvbDy7Yv7k', 'title' => 'La foi qui déplace les montagnes', 'embeddable' => true]);
        $this->postJson('/api/admin/exercises/video-preview', ['url' => self::LINK])->assertOk()->assertJson(['embeddable' => false]);
        $this->postJson('/api/admin/exercises/video-preview', ['url' => 'https://vimeo.com/123'])->assertStatus(422);
    }

    public function test_publication_targets_the_chosen_scope_and_names_the_exercise(): void
    {
        $exercise = $this->publish();
        $this->assertSame('KSvbDy7Yv7k', $exercise->video_id);
        $this->assertSame('2026-10-08 21:00:00', $exercise->closes_at->format('Y-m-d H:i:s'));

        $n = UserNotification::where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame('Nouvelle vidéo à regarder : Enseignement : la foi', $n->title);
        $this->assertStringContainsString('jeudi 8 octobre à 21h00', $n->body);
        $this->assertSame('/exercices/'.$exercise->id, $n->url);
        $this->assertSame(0, UserNotification::where('user_id', $this->other->id)->count());

        // Un patriarche ne publie pas pour une autre tribu ; un fidele d'une autre tribu ne voit pas l'exercice.
        $this->postJson('/api/admin/exercises', ['title' => 'X', 'type' => 'video', 'video_url' => self::LINK,
            'scopes' => [['type' => 'tribe', 'id' => $this->levi->id]]])->assertStatus(403);
        Sanctum::actingAs($this->other);
        $this->getJson('/api/me/exercises/'.$exercise->id)->assertStatus(403);
        $this->postJson("/api/me/exercises/{$exercise->id}/progress", ['segments' => [[0, 10]]])->assertStatus(403);
    }

    public function test_watching_is_tracked_and_skipping_is_detected(): void
    {
        $exercise = $this->publish();

        $this->beat($exercise, ['segments' => [], 'position' => 0], 0)->assertOk();                  // lecture lancee
        $this->beat($exercise, ['segments' => [[0, 15]], 'position' => 15])->assertJson(['percent' => 2]);
        // Avance rapide de 15 s a 400 s : signale, et les passages sautes ne comptent pas.
        $this->beat($exercise, ['segments' => [[400, 415]], 'position' => 415, 'seeks' => 1, 'skipped' => 385])
            ->assertJson(['percent' => 5, 'video_completed' => false]);

        $view = ExerciseVideoView::firstOrFail();
        $this->assertSame(1, $view->seek_count);
        $this->assertSame(385, $view->skipped_seconds);
        $this->assertSame(30, $view->watched_seconds);

        Sanctum::actingAs($this->patriarch);
        $row = collect($this->getJson("/api/admin/exercises/{$exercise->id}/tracking")->assertOk()->json('members'))
            ->firstWhere('user_id', $this->member->id);
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame(1, $row['seek_count']);
        $this->assertSame(385, $row['skipped_seconds']);
    }

    public function test_skipping_is_detected_by_the_server_even_if_the_browser_does_not_report_it(): void
    {
        $exercise = $this->publish();
        $this->beat($exercise, ['segments' => [], 'position' => 0], 0);
        $this->beat($exercise, ['segments' => [[0, 18.5]], 'position' => 18.5], 20);
        // Saut effectue pendant la pause (aucun « seeks » envoye) puis lecture de 78 a 81 s.
        $this->beat($exercise, ['segments' => [[78.1, 81.3]], 'position' => 81.3], 10)->assertOk();

        $view = ExerciseVideoView::firstOrFail();
        $this->assertSame(1, $view->seek_count);
        $this->assertEqualsWithDelta(60, $view->skipped_seconds, 1);
        $this->assertSame(22, $view->watched_seconds);
    }

    public function test_the_server_refuses_more_watching_than_time_allows(): void
    {
        $exercise = $this->publish();
        $this->beat($exercise, ['segments' => [], 'position' => 0], 0)->assertOk();
        // 15 s plus tard, pretendre avoir regarde 10 minutes : ignore.
        $this->beat($exercise, ['segments' => [[0, 600]], 'position' => 600])->assertJson(['percent' => 0, 'video_completed' => false]);
        // Lecture en 2x : 30 s de video en 15 s, accepte.
        $this->beat($exercise, ['segments' => [[0, 30]], 'position' => 30, 'rate' => 2])->assertJson(['percent' => 5]);
        $this->assertEquals(2.0, ExerciseVideoView::value('max_rate'));
    }

    public function test_exercise_is_done_after_full_viewing_and_the_written_response(): void
    {
        $exercise = $this->publish();
        $this->beat($exercise, ['segments' => [], 'position' => 0], 0);
        for ($t = 0; $t < 570; $t += 30) {
            $this->beat($exercise, ['segments' => [[$t, $t + 30]], 'position' => $t + 30], 30)->assertOk();
        }
        $this->beat($exercise, ['segments' => [[570, 600]], 'position' => 600], 30)->assertJson(['video_completed' => true, 'status' => 'in_progress']);

        $this->postJson("/api/me/exercises/{$exercise->id}/respond", ['response' => 'La foi, la patience, l\'obéissance.'])
            ->assertOk()->assertJson(['status' => 'done']);

        $this->assertTrue($this->getJson('/api/me/exercises/'.$exercise->id)->json('exercise.completed'));
        Sanctum::actingAs($this->patriarch);
        $this->assertSame(1, $this->getJson("/api/admin/exercises/{$exercise->id}/tracking")->json('summary.done'));
    }

    public function test_video_only_exercise_needs_no_response(): void
    {
        $exercise = $this->publish(['requires_response' => false, 'content' => null, 'video_duration' => 60]);
        $this->beat($exercise, ['segments' => [], 'position' => 0, 'duration' => 60], 0);
        $this->beat($exercise, ['segments' => [[0, 30]], 'position' => 30, 'duration' => 60], 30);
        $this->beat($exercise, ['segments' => [[30, 58]], 'position' => 58, 'duration' => 60], 30)
            ->assertJson(['video_completed' => true, 'status' => 'done']);
        $this->postJson("/api/me/exercises/{$exercise->id}/respond", ['response' => 'x'])->assertStatus(422);
    }

    public function test_reminders_name_the_exercise_and_it_closes_automatically(): void
    {
        $exercise = $this->publish();

        // Veille de la fermeture : seul celui qui n'a pas termine est relance.
        Carbon::setTestNow(Carbon::parse('2026-10-07 22:00'));
        $this->artisan('app:tick', ['--only' => 'tasks'])->assertSuccessful();
        $this->assertSame(0, UserNotification::where('type', 'task_reminder')->count(), 'pas de rappel la nuit');

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00'));
        $this->artisan('app:tick', ['--only' => 'tasks'])->assertSuccessful();
        $this->artisan('app:tick', ['--only' => 'tasks'])->assertSuccessful();
        $reminder = UserNotification::where('user_id', $this->member->id)->where('type', 'task_reminder')->sole();
        $this->assertSame('Rappel : « Enseignement : la foi »', $reminder->title);
        $this->assertStringContainsString("aujourd'hui à 21h00", $reminder->body);

        Carbon::setTestNow(Carbon::parse('2026-10-08 19:00'));
        $this->artisan('app:tick', ['--only' => 'tasks'])->assertSuccessful();
        $this->assertSame('high', UserNotification::where('user_id', $this->member->id)->where('title', 'Dernier rappel : « Enseignement : la foi »')->value('priority'));

        // Apres 21 h : ferme, plus rien n'est accepte, et l'auteur recoit le bilan.
        Carbon::setTestNow(Carbon::parse('2026-10-08 21:05'));
        Sanctum::actingAs($this->member);
        $this->postJson("/api/me/exercises/{$exercise->id}/respond", ['response' => 'En retard'])->assertStatus(422);
        $this->postJson("/api/me/exercises/{$exercise->id}/progress", ['segments' => [[0, 10]]])->assertStatus(422);
        $this->assertTrue($this->getJson('/api/me/exercises/'.$exercise->id)->json('exercise.is_closed'));

        $this->artisan('app:tick', ['--only' => 'tasks'])->assertSuccessful();
        $summary = UserNotification::where('user_id', $this->patriarch->id)->where('title', 'Exercice fermé : Enseignement : la foi')->sole();
        $this->assertSame("0 fidèle(s) sur 1 l'ont terminé. Consultez le suivi détaillé.", $summary->body);
    }

    public function test_leader_outside_the_scope_cannot_see_the_tracking(): void
    {
        $exercise = $this->publish();
        $leviPatriarch = $this->member('+14185558004', $this->levi, 'Levi');
        $leviPatriarch->roles()->attach(Role::where('key', 'patriarche')->value('id'), ['scope_kind' => 'tribe', 'scope_id' => $this->levi->id]);
        Sanctum::actingAs($leviPatriarch);
        $this->getJson("/api/admin/exercises/{$exercise->id}/tracking")->assertStatus(403);
    }
}
