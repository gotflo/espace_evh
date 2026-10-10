<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Tribe;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Courriel saisi dans le formulaire d'inscription : enregistre (meme forme que depuis le bandeau), donc le
 * bandeau « Recevez vos rappels par courriel » ne s'affiche plus ; adresse invalide refusee.
 */
class RegistrationEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tribe $tribe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tribe = Tribe::create(['name' => 'Ruben', 'slug' => 'ruben']);
        $this->user = User::create(['phone' => '+14185550002']);
        $this->user->forceFill(['approval_status' => User::PENDING])->save();
        Profile::create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user->fresh());
    }

    private function register(array $extra)
    {
        return $this->putJson('/api/profile', ['first_name' => 'Marie', 'last_name' => 'Essai', 'gender' => 'femme', 'tribe_id' => $this->tribe->id] + $extra);
    }

    public function test_the_email_given_at_registration_is_saved_and_normalized(): void
    {
        $this->register(['email' => '  Marie.Essai@Gmail.COM '])->assertOk()->assertJsonPath('profile.email', 'marie.essai@gmail.com');
        $this->assertSame('marie.essai@gmail.com', $this->user->profile()->value('email'));
        // Le compte connecte voit son adresse : le bandeau d'invitation (profil sans courriel) ne s'affiche pas.
        $this->getJson('/api/me')->assertOk()->assertJsonPath('profile.email', 'marie.essai@gmail.com');
    }

    public function test_an_invalid_email_is_refused_and_an_empty_one_stays_empty(): void
    {
        $this->register(['email' => 'marie@'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->register(['email' => ''])->assertOk()->assertJsonPath('profile.email', null);
    }
}
