<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\Appearance;
use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Apparence modifiable sans code (image de l'accueil, photos de l'ecran de connexion, textes) et
 * reduction des images envoyees (photo de profil, annonces, images existantes).
 */
class AppearanceAndImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $pastor;

    private User $marie;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->pastor = $this->member('+14185580010', 'Pasteur');
        $this->pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));
        $this->marie = $this->member('+14185580000', 'Marie');
    }

    private function member(string $phone, string $first): User
    {
        $user = User::create(['phone' => $phone, 'last_login_at' => now(), 'activity_status' => 'active']);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'T', 'gender' => 'femme', 'is_completed' => true,
            'tribe_id' => Tribe::value('id'), 'matricule' => 'VHC'.substr($phone, -4)]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    /** Chemin disque d'une URL /storage/... */
    private function stored(string $url): string
    {
        return substr($url, strlen('/storage/'));
    }

    private function dimensions(string $path): array
    {
        return getimagesizefromstring(Storage::disk('public')->get($path));
    }

    public function test_public_appearance_gives_the_original_images_and_texts_without_login(): void
    {
        $r = $this->getJson('/api/appearance')->assertOk();
        $this->assertSame('/verset/fond-800.jpg', $r->json('home.small'));
        $this->assertSame('62% 42%', $r->json('home.position'));
        $this->assertCount(4, $r->json('login'));
        $this->assertSame('/login/bible-1600.jpg', $r->json('login.0.large'));
        $this->assertSame('', $r->json('texts.login_notice'));
        $this->assertSame('Bienvenue dans votre famille spirituelle', $r->json('texts.welcome_title'));
        // Rien de personnel ni de technique.
        $this->assertSame(['home', 'login', 'texts'], array_keys($r->json()));
    }

    public function test_each_rate_limit_has_its_own_counter(): void
    {
        // L'ecran de connexion lit l'apparence : ces lectures ne doivent pas consommer les essais de connexion
        // de la meme adresse (avant : un seul compteur pour toutes les limites d'une IP ou d'un compte).
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/appearance')->assertOk();
        }
        $this->assertNotSame(429, $this->postJson('/api/auth/request-otp', ['phone' => '+14185580000'])->status());
        // Compte connecte : la limite generale (150) n'epuise pas une limite de route (20 envois d'image).
        $this->actAs($this->pastor);
        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/admin/appearance')->assertOk();
        }
        $this->post('/api/admin/appearance/home', ['image' => UploadedFile::fake()->image('a.jpg', 1200, 800), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_members_cannot_change_the_appearance(): void
    {
        $this->actAs($this->marie);
        $this->getJson('/api/admin/appearance')->assertForbidden();
        $this->post('/api/admin/appearance/home', ['image' => UploadedFile::fake()->image('a.jpg', 1200, 800), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/admin/appearance/login')->assertForbidden();
        $this->putJson('/api/admin/appearance/login', ['items' => [['id' => 'bible', 'x' => 1, 'y' => 1]]])->assertForbidden();
        $this->assertNull(\DB::table('platform_settings')->where('key', Appearance::KEY)->first());
        // Les textes passent par les reglages, reserves eux aussi.
        $this->putJson('/api/admin/settings', ['values' => ['texts.login_notice' => 'Pirate']])->assertForbidden();
    }

    public function test_home_image_is_reduced_in_two_sizes_reframed_replaced_and_reset(): void
    {
        $this->actAs($this->pastor);
        $this->post('/api/admin/appearance/home', ['image' => UploadedFile::fake()->image('fond.png', 3200, 1800), 'x' => 30, 'y' => 70],
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('home_changed', true);

        $home = $this->getJson('/api/appearance')->json('home');
        $this->assertSame('30% 70%', $home['position']);
        $this->assertStringStartsWith('/storage/appearance/', $home['large']);
        $this->assertStringEndsWith('.jpg', $home['large']);
        [$w, $h] = $this->dimensions($this->stored($home['large']));
        $this->assertSame([1600, 900], [$w, $h]);
        $this->assertSame([800, 1600], $home['widths']);
        [$w] = $this->dimensions($this->stored($home['small']));
        $this->assertSame(800, $w);

        // Recadrage seul : memes fichiers.
        $this->putJson('/api/admin/appearance/home', ['x' => 10, 'y' => 20])->assertOk();
        $this->assertSame('10% 20%', $this->getJson('/api/appearance')->json('home.position'));
        $this->assertSame($home['large'], $this->getJson('/api/appearance')->json('home.large'));
        $this->putJson('/api/admin/appearance/home', ['x' => 101, 'y' => 20])->assertStatus(422);
        $this->putJson('/api/admin/appearance/home', ['x' => '10;drop', 'y' => 20])->assertStatus(422);

        // Nouvelle image : l'ancienne est supprimee du disque.
        $this->post('/api/admin/appearance/home', ['image' => UploadedFile::fake()->image('b.jpg', 1000, 600), 'x' => 50, 'y' => 50],
            ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($this->stored($home['large']));
        Storage::disk('public')->assertMissing($this->stored($home['small']));
        $second = $this->getJson('/api/appearance')->json('home');
        // Jamais agrandie.
        $this->assertSame(1000, $this->dimensions($this->stored($second['large']))[0]);
        // Largeurs reelles annoncees au navigateur (srcset).
        $this->assertSame([800, 1000], $second['widths']);
        $this->assertSame([800, 1600], $this->getJson('/api/appearance')->json('login.0.widths'));

        // Retour a l'origine : fichiers supprimes.
        $this->deleteJson('/api/admin/appearance/home')->assertOk()->assertJsonPath('home_changed', false);
        Storage::disk('public')->assertMissing($this->stored($second['large']));
        $this->assertSame('/verset/fond-1600.jpg', $this->getJson('/api/appearance')->json('home.large'));
        $this->assertGreaterThanOrEqual(4, \App\Models\AuditLog::where('action', 'like', 'appearance.%')->count());
    }

    public function test_uploads_must_be_real_images_of_a_reasonable_size(): void
    {
        $this->actAs($this->pastor);
        $send = fn (UploadedFile $f) => $this->post('/api/admin/appearance/home', ['image' => $f, 'x' => 50, 'y' => 50], ['Accept' => 'application/json']);
        $send(UploadedFile::fake()->create('script.php', 10, 'application/x-php'))->assertStatus(422);
        $send(UploadedFile::fake()->createWithContent('faux.jpg', '<?php echo 1; ?>'))->assertStatus(422);
        $send(UploadedFile::fake()->create('vecteur.svg', 5, 'image/svg+xml'))->assertStatus(422);
        $send(UploadedFile::fake()->image('petite.jpg', 120, 80))->assertStatus(422)->assertJsonPath('errors.image.0', 'Cette image est trop petite : choisissez une photo d’au moins 400 × 200 pixels.');
        $send(UploadedFile::fake()->image('lourde.jpg', 1200, 800)->size(9000))->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles('appearance'));
    }

    public function test_login_photos_can_be_added_ordered_reframed_removed_and_reset(): void
    {
        $this->actAs($this->pastor);
        $this->post('/api/admin/appearance/login', ['image' => UploadedFile::fake()->image('culte.jpg', 2000, 1300), 'x' => 40, 'y' => 60],
            ['Accept' => 'application/json'])->assertOk();
        $list = $this->getJson('/api/admin/appearance')->json('login');
        $this->assertCount(5, $list);
        $new = $list[4];
        $this->assertFalse($new['builtin']);

        // Nouvelle photo en premier, « bible » retiree, « table » recadree.
        $items = [['id' => $new['id'], 'x' => 40, 'y' => 60], ['id' => 'louange', 'x' => 50, 'y' => 30], ['id' => 'table', 'x' => 5, 'y' => 95], ['id' => 'fete', 'x' => 50, 'y' => 35]];
        $this->putJson('/api/admin/appearance/login', ['items' => $items])->assertOk();
        $public = $this->getJson('/api/appearance')->json('login');
        $this->assertSame([$new['id'], 'louange', 'table', 'fete'], array_column($public, 'id'));
        $this->assertSame('5% 95%', $public[2]['position']);

        // Id inconnu, doublon, liste vide : refuses.
        $this->putJson('/api/admin/appearance/login', ['items' => [['id' => '../../.env', 'x' => 1, 'y' => 1]]])->assertStatus(422);
        $this->putJson('/api/admin/appearance/login', ['items' => [['id' => 'fete', 'x' => 1, 'y' => 1], ['id' => 'fete', 'x' => 1, 'y' => 1]]])->assertStatus(422);
        $this->putJson('/api/admin/appearance/login', ['items' => []])->assertStatus(422);

        // Retrait de la photo envoyee : fichier supprime.
        $this->putJson('/api/admin/appearance/login', ['items' => array_slice($items, 1)])->assertOk();
        Storage::disk('public')->assertMissing($this->stored($new['large']));

        // Limite de 8 photos.
        for ($i = count(array_slice($items, 1)); $i < Appearance::MAX_LOGIN_PHOTOS; $i++) {
            $this->post('/api/admin/appearance/login', ['image' => UploadedFile::fake()->image("p{$i}.jpg", 800, 600), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertOk();
        }
        $this->post('/api/admin/appearance/login', ['image' => UploadedFile::fake()->image('trop.jpg', 800, 600), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertStatus(422);

        // Retour aux photos d'origine : toutes les photos envoyees disparaissent.
        $this->deleteJson('/api/admin/appearance/login')->assertOk()->assertJsonPath('login_changed', false);
        $this->assertSame([], Storage::disk('public')->allFiles('appearance'));
        $this->assertSame(['bible', 'louange', 'table', 'fete'], array_column($this->getJson('/api/appearance')->json('login'), 'id'));
    }

    public function test_the_platform_manager_manages_the_appearance(): void
    {
        [$manager] = PlatformManager::activate((string) PlatformManager::phone());
        $this->actAs($manager);
        $this->getJson('/api/admin/appearance')->assertOk();
        $this->post('/api/admin/appearance/home', ['image' => UploadedFile::fake()->image('a.jpg', 1200, 800), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/admin/settings', ['values' => ['texts.login_notice' => 'Culte spécial dimanche à 10 h']])->assertOk();
        $this->assertSame('Culte spécial dimanche à 10 h', $this->getJson('/api/appearance')->json('texts.login_notice'));
    }

    public function test_texts_are_cleaned_bounded_and_restorable(): void
    {
        $this->actAs($this->pastor);
        $this->putJson('/api/admin/settings', ['values' => [
            'texts.welcome_title' => "  Bienvenue\u{200B} chez   vous \n ",
            'texts.pending_message' => "Ligne 1\r\n\r\n\r\n\r\nLigne 2 <script>alert(1)</script>",
        ]])->assertOk();
        $texts = $this->getJson('/api/appearance')->json('texts');
        $this->assertSame('Bienvenue chez vous', $texts['welcome_title']);
        // Les sauts de ligne sont limites ; le HTML reste du texte (affiche tel quel par React, jamais interprete).
        $this->assertSame("Ligne 1\n\nLigne 2 <script>alert(1)</script>", $texts['pending_message']);

        $this->putJson('/api/admin/settings', ['values' => ['texts.welcome_title' => str_repeat('a', 121)]])->assertStatus(422);
        // Un titre efface (espaces seulement) revient au texte d'origine : jamais de titre vide.
        $this->putJson('/api/admin/settings', ['values' => ['texts.welcome_title' => '   ']])->assertOk();
        $this->assertSame('Bienvenue dans votre famille spirituelle', PlatformSettings::get('texts.welcome_title'));
        // Seulement des caracteres invisibles : refuse (texte vide apres nettoyage).
        $this->putJson('/api/admin/settings', ['values' => ['texts.welcome_title' => "\u{200B}\u{202E}"]])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['texts.welcome_title' => ['tableau']]])->assertStatus(422);
        // Le message de connexion peut etre vide (rien n'est affiche).
        $this->putJson('/api/admin/settings', ['values' => ['texts.login_notice' => 'Info']])->assertOk();
        $this->putJson('/api/admin/settings', ['values' => ['texts.login_notice' => '']])->assertOk();
        $this->assertSame('', PlatformSettings::get('texts.login_notice'));
        // Retablir.
        $this->putJson('/api/admin/settings', ['values' => ['texts.welcome_title' => null]])->assertOk();
        $this->assertSame('Bienvenue dans votre famille spirituelle', $this->getJson('/api/appearance')->json('texts.welcome_title'));
    }

    public function test_profile_photo_is_reduced_and_the_old_one_kept_if_the_new_one_is_unreadable(): void
    {
        $this->actAs($this->marie);
        $send = fn (UploadedFile $f) => $this->post('/api/profile', ['first_name' => 'Marie', 'last_name' => 'T', 'gender' => 'femme', 'photo' => $f], ['Accept' => 'application/json']);
        $send(UploadedFile::fake()->image('grande.png', 3000, 2000))->assertOk();
        $path = Profile::where('user_id', $this->marie->id)->value('photo_path');
        $this->assertStringStartsWith('photos/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        [$w, $h] = $this->dimensions($path);
        $this->assertSame([640, 427], [$w, $h]);

        // Fichier deguise en image : refuse, l'ancienne photo reste en place.
        $send(UploadedFile::fake()->createWithContent('faux.jpg', 'pas une image'))->assertStatus(422);
        $this->assertSame($path, Profile::where('user_id', $this->marie->id)->value('photo_path'));
        Storage::disk('public')->assertExists($path);

        // Nouvelle photo : l'ancienne est supprimee.
        $send(UploadedFile::fake()->image('carre.jpg', 512, 512))->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    /** PNG de quelques octets annoncant 20000 x 20000 pixels (seul l'en-tete est valide). */
    private function decompressionBomb(): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0", 64))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('bombe.png', $png);
    }

    public function test_huge_pixel_images_are_refused_before_being_opened(): void
    {
        $this->actAs($this->marie);
        $this->post('/api/profile', ['first_name' => 'Marie', 'last_name' => 'T', 'gender' => 'femme', 'photo' => $this->decompressionBomb()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.photo.0', 'Cette image est trop grande (plus de 50 millions de pixels) : réduisez-la ou choisissez une autre photo.');
        $this->assertNull(Profile::where('user_id', $this->marie->id)->value('photo_path'));
        $this->actAs($this->pastor);
        $this->post('/api/admin/appearance/home', ['image' => $this->decompressionBomb(), 'x' => 50, 'y' => 50], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_announcement_images_are_reduced(): void
    {
        $this->actAs($this->pastor);
        $this->post('/api/admin/announcements', ['title' => 'Image', 'body' => 'Texte', 'category' => 'info', 'image' => UploadedFile::fake()->image('affiche.jpg', 4000, 3000)],
            ['Accept' => 'application/json'])->assertSuccessful();
        $path = Announcement::latest('id')->value('image_path');
        $this->assertSame(1600, $this->dimensions($path)[0]);
    }

    public function test_existing_heavy_images_are_reduced_by_the_command(): void
    {
        $disk = Storage::disk('public');
        $big = UploadedFile::fake()->image('big.png', 2600, 2600);
        $disk->putFileAs('photos', $big, 'ancienne.png');
        $small = UploadedFile::fake()->image('small.jpg', 300, 300);
        $disk->putFileAs('photos', $small, 'legere.jpg');
        Profile::where('user_id', $this->marie->id)->update(['photo_path' => 'photos/ancienne.png']);
        Profile::where('user_id', $this->pastor->id)->update(['photo_path' => 'photos/legere.jpg']);
        $before = Profile::where('user_id', $this->marie->id)->value('updated_at');

        Artisan::call('app:images-optimize', ['--dry-run' => true]);
        $disk->assertExists('photos/ancienne.png');

        Artisan::call('app:images-optimize');
        $new = Profile::where('user_id', $this->marie->id)->value('photo_path');
        $this->assertNotSame('photos/ancienne.png', $new);
        $disk->assertMissing('photos/ancienne.png');
        $this->assertSame([640, 640], array_slice($this->dimensions($new), 0, 2));
        $this->assertEquals($before, Profile::where('user_id', $this->marie->id)->value('updated_at'));
        // Image deja legere : intacte.
        $this->assertSame('photos/legere.jpg', Profile::where('user_id', $this->pastor->id)->value('photo_path'));
        $disk->assertExists('photos/legere.jpg');
    }
}
