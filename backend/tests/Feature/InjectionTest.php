<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Protection contre les injections (SQL, jokers de recherche, iCal, contenu affiche). */
class InjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $pastor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->pastor = $this->member('+14185559000', 'Pasteur', 'Résident');
        $this->pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->member('+14185559001', 'Marie', 'Tremblay');
        $this->member('+14185559002', 'Jean', 'Dupont');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $phone, string $first, string $last): User
    {
        $user = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'is_completed' => true]);

        return $user;
    }

    public function test_sql_injection_attempts_in_searches_return_nothing_and_break_nothing(): void
    {
        Sanctum::actingAs($this->pastor);
        foreach (["' OR '1'='1", "'; DROP TABLE users; --", '" OR ""="', "1) UNION SELECT phone FROM users --", '\\'] as $payload) {
            $res = $this->getJson('/api/admin/members?q='.urlencode($payload))->assertOk();
            $this->assertCount(0, $res->json('members'), $payload);
        }
        $this->assertSame(3, User::count(), 'aucune table ni ligne touchee');
    }

    public function test_search_wildcards_are_searched_literally(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->assertCount(0, $this->getJson('/api/admin/members?q=%25')->json('members'), '% ne liste pas tout le monde');
        $this->assertCount(0, $this->getJson('/api/admin/members?q=_')->json('members'));
        $this->assertCount(1, $this->getJson('/api/admin/members?q=trem')->json('members'));

        Sanctum::actingAs(User::where('phone', '+14185559001')->first());
        $this->assertCount(0, $this->getJson('/api/me/family/search?q=%25%25')->json('results') ?? []);
    }

    public function test_calendar_feed_cannot_be_injected_with_new_lines(): void
    {
        $this->makeEvent(['title' => "Culte\r\nBEGIN:VEVENT\r\nSUMMARY:Piege", 'category' => 'culte',
            'starts_at' => '2026-10-18 09:30', 'location' => "Salle\rX-INJECTION:1"]);
        $token = Str::random(48);
        $this->pastor->forceFill(['calendar_token' => $token])->save();

        $ics = $this->get('/api/calendar/feed/'.$token.'.ics')->assertOk()->getContent();
        $lines = explode("\r\n", $ics);
        $this->assertSame(count(array_keys($lines, 'END:VEVENT', true)), count(array_keys($lines, 'BEGIN:VEVENT', true)), 'autant de debuts que de fins');
        $this->assertEmpty(array_filter($lines, fn ($l) => $l === 'SUMMARY:Piege' || str_starts_with($l, 'X-INJECTION')), 'aucune ligne injectee');
        $this->assertStringNotContainsString("\r", str_replace("\r\n", '', $ics), 'aucun retour chariot isole');
        $this->assertStringContainsString('SUMMARY:Culte\\nBEGIN:VEVENT\\nSUMMARY:Piege', $ics);
    }

    public function test_html_and_scripts_are_stored_as_plain_text(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/admin/announcements', [
            'title' => '<img src=x onerror=alert(1)>', 'body' => '<script>alert("x")</script>',
            'category' => 'info', 'scopes' => [['type' => 'church', 'id' => null]],
        ])->assertSuccessful();

        Sanctum::actingAs(User::where('phone', '+14185559001')->first());
        $item = collect($this->getJson('/api/me/announcements')->json('announcements'))->first();
        // Le texte est rendu tel quel (echappe par l'interface), jamais interprete comme HTML.
        $this->assertSame('<script>alert("x")</script>', $item['body']);
        $this->assertSame('<img src=x onerror=alert(1)>', $item['title']);
    }

    public function test_unknown_or_forged_fields_are_ignored(): void
    {
        $member = User::where('phone', '+14185559001')->first();
        Sanctum::actingAs($member);
        $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Tremblay', 'gender' => 'femme',
            'user_id' => $this->pastor->id, 'is_admin' => true, 'completion' => 100, 'id' => 999])->assertOk();

        $this->assertSame($member->id, Profile::where('first_name', 'Marie')->value('user_id'));
        $this->assertFalse($member->fresh()->hasPermission('roles.manage'));
    }
}
