<?php

namespace Tests\Feature;

use App\Models\DashboardVerse;
use App\Models\Profile;
use App\Models\ResolutionResource;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\PlatformManager;
use App\Support\ResolutionCatalog;
use App\Support\YouTube;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ressources et liens video : reconnaissance automatique des liens YouTube, liens invalides, champs
 * obligatoires, brouillon / publication, suppression et droits ; textes bibliques geres par le compte de gestion.
 */
class ResourcesAndVideoLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        Http::preventStrayRequests();
        config(['platform.manager.phone' => '+22893471703']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $tribe = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->marie = User::create(['phone' => '+14185570000', 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $this->marie->id, 'first_name' => 'Marie', 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true, 'tribe_id' => $tribe->id]);
        $this->marie->roles()->attach(Role::where('key', 'fidele')->value('id'));
        [$this->manager] = PlatformManager::activate('+22893471703');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    /** @return array<string, mixed> */
    private function resource(array $overrides = []): array
    {
        return array_merge(['theme' => 'meditation', 'title' => 'Méditer la Parole', 'author' => 'Pst Abraham Andebi', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], $overrides);
    }

    /** @return array<int, string> titres des ressources vues par un membre */
    private function memberTitles(): array
    {
        $this->actAs($this->marie);

        return collect($this->getJson('/api/me/resolutions')->assertOk()->json('resources'))->flatMap(fn ($g) => $g['items'])->pluck('title')->all();
    }

    public function test_youtube_links_are_recognized_automatically(): void
    {
        $this->actAs($this->manager);
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
        ] as $i => $url) {
            $created = $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => $url, 'title' => 'Vidéo '.$i]))
                ->assertCreated()->json('resource');
            $this->assertSame('dQw4w9WgXcQ', $created['youtube_id'], $url);
            $this->assertSame('youtube', $created['kind']);
            $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $created['url']);
            $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $created['thumbnail']);
        }
        // Lien ordinaire, chaine et liste de lecture YouTube : de simples liens.
        foreach (['https://www.vasesdhonneurchicoutimi.org/predications', 'https://www.youtube.com/@VasesdHonneur', 'https://www.youtube.com/playlist?list=PL123'] as $url) {
            $created = $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => $url, 'title' => $url]))->assertCreated()->json('resource');
            $this->assertNull($created['youtube_id']);
            $this->assertSame('link', $created['kind']);
        }
    }

    public function test_invalid_links_and_missing_fields_are_refused(): void
    {
        $this->actAs($this->manager);
        foreach ([
            'https://www.youtube.com/watch?v=abc',      // identifiant tronque
            'https://youtu.be/',                        // sans video
            'https://www.youtube.com/shorts/',          // sans video
            'javascript:alert(1)',
            'ftp://exemple.org/video.mp4',
            'pas un lien',
        ] as $url) {
            $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => $url]))
                ->assertStatus(422)->assertJsonValidationErrors(['url']);
        }
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'meditation'])
            ->assertStatus(422)->assertJsonValidationErrors(['url']);
        // Lien ordinaire : titre et auteur a saisir (rien a reconnaitre).
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'meditation', 'url' => 'https://exemple.org/page'])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'author']);
        $this->postJson('/api/admin/resolution-resources', $this->resource(['theme' => 'inconnu']))
            ->assertStatus(422)->assertJsonValidationErrors(['theme']);
        $this->postJson('/api/admin/resolution-resources', $this->resource(['status' => 'archive']))
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame(0, ResolutionResource::count());
        $this->assertTrue(YouTube::looksLikeBrokenVideoLink('https://youtu.be/abc'));
        $this->assertFalse(YouTube::looksLikeBrokenVideoLink('https://www.youtube.com/@chaine'));
    }

    public function test_drafts_stay_hidden_until_published(): void
    {
        $this->actAs($this->manager);
        $id = $this->postJson('/api/admin/resolution-resources', $this->resource(['status' => 'draft']))
            ->assertCreated()->assertJsonPath('resource.status', 'draft')->json('resource.id');
        $this->assertNotContains('Méditer la Parole', $this->memberTitles());

        $this->actAs($this->manager);
        $this->putJson("/api/admin/resolution-resources/{$id}", $this->resource(['status' => 'published']))->assertOk();
        $this->assertContains('Méditer la Parole', $this->memberTitles());

        $this->actAs($this->manager);
        $this->putJson("/api/admin/resolution-resources/{$id}", $this->resource(['status' => 'draft', 'title' => 'Méditer encore']))->assertOk();
        $this->assertSame([], $this->memberTitles());

        $this->actAs($this->manager);
        $this->deleteJson("/api/admin/resolution-resources/{$id}")->assertOk();
        $this->assertNull(ResolutionResource::find($id));
        // Operations du compte de gestion : journal de securite, pas le journal ordinaire.
        $this->assertDatabaseHas('security_logs', ['action' => 'resolution_resource.created']);
        $this->assertDatabaseHas('security_logs', ['action' => 'resolution_resource.published']);
        $this->assertDatabaseHas('security_logs', ['action' => 'resolution_resource.deleted']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'resolution_resource.created']);
    }

    public function test_each_video_reaches_the_members_in_the_tab_of_its_theme(): void
    {
        $this->actAs($this->manager);
        $videos = ['meditation' => 'aaaaaaaaaaa', 'priere' => 'bbbbbbbbbbb', 'jeune' => 'ccccccccccc', 'culte' => 'ddddddddddd'];
        foreach ($videos as $theme => $id) {
            $this->postJson('/api/admin/resolution-resources', $this->resource(['theme' => $theme, 'title' => 'Vidéo '.$theme, 'url' => 'https://youtu.be/'.$id]))
                ->assertCreated()->assertJsonPath('resource.theme', $theme)->assertJsonPath('resource.youtube_id', $id);
        }
        // Brouillon dans « prière » : pas chez les membres tant qu'il n'est pas publie.
        $this->postJson('/api/admin/resolution-resources', $this->resource(['theme' => 'priere', 'title' => 'Brouillon prière', 'status' => 'draft']))->assertCreated();

        $this->actAs($this->marie);
        $groups = collect($this->getJson('/api/me/resolutions')->assertOk()->json('resources'))->keyBy('theme');
        // Les quatre onglets, dans l'ordre, chacun avec SA video (lue dans l'application grace a son identifiant).
        $this->assertSame(['meditation', 'priere', 'jeune', 'culte'], $groups->keys()->all());
        foreach ($videos as $theme => $id) {
            $this->assertSame(['Vidéo '.$theme], collect($groups[$theme]['items'])->pluck('title')->all(), $theme);
            $this->assertSame($id, $groups[$theme]['items'][0]['youtube_id']);
            $this->assertSame('youtube', $groups[$theme]['items'][0]['kind']);
        }
        $this->assertSame(ResolutionCatalog::THEMES['priere'], $groups['priere']['label']);
        // Les rubriques des RM pointent vers le bon onglet (theme de chaque rubrique).
        $fields = $this->getJson('/api/me/resolutions')->json('fields');
        $this->assertSame('priere', $fields['priere']['theme']);
        $this->assertSame('culte', $fields['assiduite']['theme']);

        // Changer le theme d'une ressource la deplace d'onglet chez les membres.
        $this->actAs($this->manager);
        $id = ResolutionResource::where('title', 'Vidéo jeune')->value('id');
        $this->putJson("/api/admin/resolution-resources/{$id}", $this->resource(['theme' => 'culte', 'title' => 'Vidéo jeune', 'url' => 'https://youtu.be/ccccccccccc']))->assertOk();
        $this->actAs($this->marie);
        $groups = collect($this->getJson('/api/me/resolutions')->json('resources'))->keyBy('theme');
        $this->assertSame([], $groups['jeune']['items']);
        $this->assertSame(['Vidéo culte', 'Vidéo jeune'], collect($groups['culte']['items'])->pluck('title')->all());
    }

    public function test_editing_publishing_and_deleting_need_their_own_permission(): void
    {
        $pastor = User::create(['phone' => '+14185570010']);
        $pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->actAs($pastor);
        $roleId = $this->postJson('/api/admin/roles', ['name' => 'Rédaction ressources', 'scope_kind' => 'none', 'permission_keys' => ['resources.view', 'resources.edit']])->json('role_id');
        $this->marie->roles()->attach($roleId);
        $published = ResolutionResource::create($this->resource(['title' => 'Déjà publiée', 'youtube_id' => 'dQw4w9WgXcQ', 'status' => 'published', 'position' => 1]));

        $this->actAs($this->marie);
        $this->getJson('/api/admin/resolution-resources')->assertOk()->assertJsonPath('can.publish', false)->assertJsonPath('can.delete', false);
        // Sans « publier » : brouillon par defaut, publication refusee.
        $this->postJson('/api/admin/resolution-resources', $this->resource())->assertCreated()->assertJsonPath('resource.status', 'draft');
        $this->postJson('/api/admin/resolution-resources', $this->resource(['status' => 'published']))->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->putJson("/api/admin/resolution-resources/{$published->id}", $this->resource(['title' => 'Changée']))->assertStatus(422);
        $this->deleteJson("/api/admin/resolution-resources/{$published->id}")->assertForbidden();
        $this->assertSame('Déjà publiée', $published->fresh()->title);
    }

    public function test_title_and_author_left_empty_are_filled_from_youtube_everywhere(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'La foi qui agit 🙏', 'author_name' => 'Vases d’Honneur Chicoutimi'])]);
        $this->actAs($this->manager);
        // Ressource : titre et auteur vides => pre-remplis par le serveur (sans emoji).
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'priere', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => '', 'author' => ''])
            ->assertCreated()->assertJsonPath('resource.title', 'La foi qui agit')->assertJsonPath('resource.author', 'Vases d’Honneur Chicoutimi');
        // Un champ deja rempli est garde.
        $this->postJson('/api/admin/resolution-resources', ['theme' => 'jeune', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Mon titre', 'author' => ''])
            ->assertCreated()->assertJsonPath('resource.title', 'Mon titre')->assertJsonPath('resource.author', 'Vases d’Honneur Chicoutimi');
        // Devoir (DS) : meme logique (titre et orateur).
        $this->postJson('/api/admin/assignments', ['media_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'notify' => false])
            ->assertCreated()->assertJsonPath('assignment.title', 'La foi qui agit')->assertJsonPath('assignment.speaker', 'Vases d’Honneur Chicoutimi');
    }

    public function test_the_video_preview_recognizes_the_link_before_saving(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'La foi qui agit', 'author_name' => 'Vases d’Honneur'])]);
        $this->actAs($this->manager);
        $this->postJson('/api/admin/content/video-preview', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk()
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')->assertJsonPath('title', 'La foi qui agit')->assertJsonPath('embeddable', true);
        $this->postJson('/api/admin/content/video-preview', ['url' => 'https://exemple.org'])->assertStatus(422);
    }

    public function test_the_manager_manages_bible_texts(): void
    {
        // Sans le texte installe par la migration (Actes 20.28) : la rotation ne porte que sur ceux du test.
        DashboardVerse::query()->delete();
        $this->actAs($this->manager);
        $this->postJson('/api/admin/verses', ['label' => 'Parole', 'reference' => 'Jean 3.16', 'status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['text']);
        $first = $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Premier texte donné.', 'reference' => 'Jean 3.16', 'status' => 'draft'])
            ->assertCreated()->json('verse.id');
        $second = $this->postJson('/api/admin/verses', ['label' => 'Parole', 'text' => 'Second texte donné.', 'reference' => 'Psaume 23.1', 'status' => 'published'])
            ->assertCreated()->json('verse.id');
        $this->getJson('/api/admin/verses')->assertOk()->assertJsonPath('can.publish', true)->assertJsonPath('can.delete', true);

        // Le brouillon n'est pas affiche aux membres ; le texte publie l'est.
        $this->actAs($this->marie);
        $this->getJson('/api/dashboard/verse')->assertOk()->assertJsonPath('verse.reference', 'Psaume 23.1');

        $this->actAs($this->manager);
        $this->putJson("/api/admin/verses/{$first}", ['label' => 'Parole', 'text' => 'Premier texte donné.', 'reference' => 'Jean 3.16', 'status' => 'published', 'is_pinned' => true])->assertOk();
        $this->postJson('/api/admin/verses/reorder', ['ids' => [$second, $first]])->assertOk();
        $this->actAs($this->marie);
        $this->getJson('/api/dashboard/verse')->assertOk()->assertJsonPath('verse.reference', 'Jean 3.16');

        $this->actAs($this->manager);
        $this->deleteJson("/api/admin/verses/{$first}")->assertOk();
        $this->actAs($this->marie);
        $this->getJson('/api/dashboard/verse')->assertOk()->assertJsonPath('verse.reference', 'Psaume 23.1');
        // Les membres ne gerent rien.
        $this->postJson('/api/admin/verses', ['label' => 'X', 'text' => 'Texte du membre.', 'reference' => 'Jean 1.1', 'status' => 'draft'])->assertForbidden();
    }

    /** Signal du lecteur apres un delai (le serveur refuse plus de lecture que le temps ecoule). */
    private function beat(int $id, array $payload, int $afterSeconds = 30)
    {
        Carbon::setTestNow(now()->addSeconds($afterSeconds));

        return $this->postJson("/api/me/resources/{$id}/progress", $payload + ['duration' => 120, 'rate' => 1]);
    }

    public function test_resource_videos_are_tracked_and_resume_where_the_member_stopped(): void
    {
        $this->actAs($this->manager);
        $id = $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => 'https://youtu.be/dQw4w9WgXcQ']))->assertCreated()->json('resource.id');
        $draft = $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => 'https://youtu.be/aaaaaaaaaaa', 'title' => 'Brouillon', 'status' => 'draft']))->json('resource.id');
        $link = $this->postJson('/api/admin/resolution-resources', $this->resource(['url' => 'https://exemple.org/page', 'title' => 'Lien']))->json('resource.id');

        $this->actAs($this->marie);
        $item = fn () => collect($this->getJson('/api/me/resolutions')->json('resources'))->flatMap(fn ($g) => $g['items'])->firstWhere('id', $id);
        $this->assertSame(['percent' => 0, 'completed' => false, 'resume_at' => 0], $item()['watch']);

        // Lecture continue : comptee, duree fixee par le premier lecteur ; reprise la ou le membre s'est arrete.
        $this->beat($id, ['segments' => [[0, 20]], 'position' => 20], 15)->assertOk()->assertJson(['percent' => 16]);
        $this->beat($id, ['segments' => [[20, 60]], 'position' => 60])->assertOk()->assertJson(['percent' => 50]);
        $this->assertSame(['percent' => 50, 'completed' => false, 'resume_at' => 60], $item()['watch']);
        // Avancer ne compte pas ; plus de lecture que le temps ecoule : ignoree.
        $this->beat($id, ['segments' => [[60, 120]], 'position' => 120], 5)->assertOk()->assertJson(['percent' => 50]);
        $this->beat($id, ['segments' => [[60, 90]], 'position' => 90])->assertOk();
        $this->beat($id, ['segments' => [[90, 120]], 'position' => 120])->assertOk()->assertJson(['video_completed' => true, 'percent' => 100]);
        $this->assertSame(['percent' => 100, 'completed' => true, 'resume_at' => 0], $item()['watch']);

        // Brouillon ou simple lien : pas de suivi.
        $this->beat($draft, ['segments' => [[0, 10]]])->assertNotFound();
        $this->beat($link, ['segments' => [[0, 10]]])->assertNotFound();

        // Autre video : les lectures de l'ancienne ne valent plus.
        $this->actAs($this->manager);
        $this->putJson("/api/admin/resolution-resources/{$id}", $this->resource(['url' => 'https://youtu.be/bbbbbbbbbbb']))->assertOk();
        $this->actAs($this->marie);
        $this->assertSame(['percent' => 0, 'completed' => false, 'resume_at' => 0], $item()['watch']);
    }
}
