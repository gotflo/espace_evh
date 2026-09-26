<?php

namespace Tests\Feature;

use App\Models\DashboardVerse;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\VerseService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Versets du tableau de bord administrables (PR, PA) : programmation, rotation, mise en avant. */
class DashboardVersesTest extends TestCase
{
    use RefreshDatabase;

    private User $pa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->pa = $this->user('+14185557101', 'pasteur_assistant');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $phone, string $role, array $pivot = []): User
    {
        $u = User::create(['phone' => $phone]);
        Profile::create(['user_id' => $u->id, 'first_name' => 'U', 'last_name' => substr($phone, -3), 'is_completed' => true]);
        $u->roles()->attach(Role::where('key', $role)->value('id'), $pivot);

        return $u;
    }

    private function verse(array $data)
    {
        return $this->postJson('/api/admin/verses', array_merge(['label' => 'Parole du mois', 'status' => 'published'], $data));
    }

    private function today(): array
    {
        Cache::flush();

        return $this->getJson('/api/dashboard/verse')->assertOk()->json('verse');
    }

    public function test_the_current_verse_is_kept_after_deployment_and_everyone_can_read_it(): void
    {
        Sanctum::actingAs($this->user('+14185557102', 'fidele'));
        $this->assertSame('Actes 20.28', $this->today()['reference']);
        $this->assertSame('Notre appel', $this->today()['label']);
    }

    public function test_only_pr_and_pa_can_manage_verses(): void
    {
        $tribe = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        Sanctum::actingAs($this->user('+14185557103', 'patriarche', ['scope_kind' => 'tribe', 'scope_id' => $tribe->id]));
        $this->getJson('/api/admin/verses')->assertStatus(403);
        $this->verse(['text' => 'Texte', 'reference' => 'Jean 3.16'])->assertStatus(403);

        Sanctum::actingAs($this->user('+14185557104', 'super_admin'));
        $this->getJson('/api/admin/verses')->assertOk();
    }

    public function test_drafts_future_and_expired_texts_are_never_shown(): void
    {
        DashboardVerse::query()->delete();
        Sanctum::actingAs($this->pa);
        $this->verse(['text' => 'Brouillon', 'reference' => 'Ps 1.1', 'status' => 'draft'])->assertCreated();
        $this->verse(['text' => 'Futur', 'reference' => 'Ps 2.1', 'starts_at' => '2026-11-01 00:00'])->assertCreated();
        DashboardVerse::create(['label' => 'X', 'text' => 'Expiré', 'reference' => 'Ps 3.1', 'status' => 'published', 'ends_at' => '2026-10-01 00:00']);

        $this->assertSame('Actes 20.28', $this->today()['reference'], 'rien d\'actif : le verset de l\'église s\'affiche');

        $states = collect($this->getJson('/api/admin/verses')->json('verses'))->pluck('state', 'reference');
        $this->assertEqualsCanonicalizing(['Ps 1.1' => 'draft', 'Ps 2.1' => 'scheduled', 'Ps 3.1' => 'expired'], $states->only(['Ps 1.1', 'Ps 2.1', 'Ps 3.1'])->all());

        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00'));
        $this->assertSame('Ps 2.1', $this->today()['reference'], 'publié automatiquement à sa date');
    }

    public function test_active_texts_rotate_every_day_in_the_chosen_order(): void
    {
        Sanctum::actingAs($this->pa);
        $this->verse(['text' => 'Deuxième', 'reference' => 'Jean 3.16'])->assertCreated();
        $seen = [];
        for ($d = 0; $d < 4; $d++) {
            Carbon::setTestNow(Carbon::parse('2026-10-14 10:00')->addDays($d));
            $seen[] = $this->today()['reference'];
        }
        $this->assertCount(2, array_unique($seen));
        $this->assertNotSame($seen[0], $seen[1], 'un texte différent chaque jour');
        $this->assertSame($seen[0], $seen[2]);

        // Même jour = même texte pour tout le monde, même en rechargeant.
        $this->assertSame($this->today(), $this->today());
    }

    public function test_a_pinned_text_replaces_the_rotation_during_its_period_and_two_cannot_overlap(): void
    {
        Sanctum::actingAs($this->pa);
        $this->verse(['text' => 'Temps de jeûne', 'reference' => 'Esaïe 58.6', 'is_pinned' => true,
            'starts_at' => '2026-10-10 00:00', 'ends_at' => '2026-10-20 23:59'])->assertCreated();
        $this->assertSame('Esaïe 58.6', $this->today()['reference']);

        $this->verse(['text' => 'Autre', 'reference' => 'Joël 2.12', 'is_pinned' => true,
            'starts_at' => '2026-10-15 00:00', 'ends_at' => '2026-10-25 23:59'])->assertStatus(422)->assertJsonValidationErrors('is_pinned');

        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00'));
        $this->assertSame('Actes 20.28', $this->today()['reference'], 'fin de la période : retour à la rotation');
    }

    public function test_incoherent_or_duplicate_texts_are_refused(): void
    {
        Sanctum::actingAs($this->pa);
        $this->verse(['text' => 'Au commencement', 'reference' => 'Jean 1.1', 'starts_at' => '2026-10-20', 'ends_at' => '2026-10-19'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->verse(['text' => 'Déjà fini', 'reference' => 'Jean 1.2', 'ends_at' => '2026-10-01'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->verse(['text' => DashboardVerse::first()->text, 'reference' => 'actes 20.28'])
            ->assertStatus(422)->assertJsonValidationErrors('text');
    }

    public function test_changes_are_traced_with_their_author_and_order_can_be_changed(): void
    {
        Sanctum::actingAs($this->pa);
        $id = $this->verse(['text' => 'Premier jet', 'reference' => 'Rom 12.1', 'status' => 'draft'])->json('verse.id');
        $this->putJson("/api/admin/verses/{$id}", ['label' => 'Parole du mois', 'text' => 'Texte corrigé', 'reference' => 'Rom 12.1', 'status' => 'published'])->assertOk();

        $history = $this->getJson("/api/admin/verses/{$id}/history")->assertOk()->json('history');
        $this->assertSame(['Verset du tableau de bord modifié', 'Verset du tableau de bord ajouté'], array_column($history, 'label'));
        $this->assertSame('U 101', $history[0]['actor']);

        $first = DashboardVerse::orderBy('position')->first()->id;
        $this->postJson('/api/admin/verses/reorder', ['ids' => [$id, $first]])->assertOk();
        $this->assertSame([$id, $first], DashboardVerse::orderBy('position')->pluck('id')->all());
        $this->assertSame('Rom 12.1', VerseService::pick(Carbon::parse('1970-01-01 12:00'))['reference'], 'jour 0 : premier de la liste');
    }
}
