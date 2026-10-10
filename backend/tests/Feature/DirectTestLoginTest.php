<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Connexion directe des essais locaux (TEST_DIRECT_LOGIN) : sans code SMS en local seulement ; l'option
 * est ignoree dans tout autre environnement (production comprise).
 */
class DirectTestLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.sms.driver' => 'log', 'app.expose_otp' => false]);
        $user = User::create(['phone' => '+14185599901']);
        Profile::create(['user_id' => $user->id, 'first_name' => 'Degaulle', 'last_name' => 'K', 'gender' => 'homme', 'is_completed' => true,
            'tribe_id' => Tribe::create(['name' => 'Ruben', 'slug' => 'ruben'])->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));
    }

    /** Nouveau compte en attente de validation, profil pas encore envoye. */
    private function pendingUser(string $phone): User
    {
        $user = User::create(['phone' => $phone]);
        $user->forceFill(['approval_status' => User::PENDING])->save();

        return $user->fresh();
    }

    public function test_the_option_is_ignored_outside_the_local_environment(): void
    {
        foreach (['production', 'testing', 'staging'] as $env) {
            $this->app['env'] = $env;
            config(['app.test_direct_login' => true]);
            $this->postJson('/api/auth/request-otp', ['phone' => '418 559 9901', 'country' => 'CA'])
                ->assertOk()->assertJsonMissingPath('token');
            Carbon::setTestNow(now()->addMinute());
        }
        Carbon::setTestNow();
    }

    public function test_locally_with_the_option_the_number_signs_in_directly(): void
    {
        $this->app['env'] = 'local';
        config(['app.test_direct_login' => true]);
        $this->postJson('/api/auth/request-otp', ['phone' => '418 559 9901', 'country' => 'CA'])
            ->assertOk()->assertJsonPath('user.phone', '+14185599901')->assertJsonPath('profile_completed', true)
            ->assertJsonPath('is_platform_manager', false);
        $this->assertDatabaseMissing('otp_codes', ['phone' => '+14185599901']);

        // Sans l'option, meme en local : etape du code habituelle.
        config(['app.test_direct_login' => false]);
        $this->postJson('/api/auth/request-otp', ['phone' => '418 559 9901', 'country' => 'CA'])->assertOk()->assertJsonMissingPath('token');
    }

    public function test_locally_a_new_registration_is_approved_at_once_and_never_elsewhere(): void
    {
        $tribe = Tribe::first();
        $pastor = User::create(['phone' => '+14185550100']);
        $pastor->roles()->attach(Role::where('key', 'super_admin')->value('id'));

        // Production : l'inscription attend le pasteur, comme toujours.
        $this->app['env'] = 'production';
        config(['app.test_direct_login' => true]);
        $prod = $this->pendingUser('+14185550001');
        Profile::create(['user_id' => $prod->id]);
        Sanctum::actingAs($prod);
        $this->putJson('/api/profile', ['first_name' => 'Prod', 'last_name' => 'Attente', 'gender' => 'homme', 'tribe_id' => $tribe->id])
            ->assertOk()->assertJsonPath('membership.status', 'pending');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('membership.status', 'pending');

        // Local avec l'option : validee aussitot, profil termine, espace ouvert.
        $this->app['env'] = 'local';
        $this->app['auth']->forgetGuards();
        $local = $this->pendingUser('+14185550002');
        Profile::create(['user_id' => $local->id]);
        Sanctum::actingAs($local);
        $this->putJson('/api/profile', ['first_name' => 'Local', 'last_name' => 'Essai', 'gender' => 'homme', 'tribe_id' => $tribe->id])
            ->assertOk()->assertJsonPath('membership.status', 'approved')->assertJsonPath('profile.is_completed', true);
        $this->assertSame(User::APPROVED, $local->fresh()->approval_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.approved', 'member_user_id' => $local->id, 'user_id' => $pastor->id]);

        // Inscription deja en attente (envoyee avant) : validee a la prochaine verification de l'ecran d'attente.
        $this->app['auth']->forgetGuards();
        $prod->refresh();
        Sanctum::actingAs($prod);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('membership.status', 'approved')->assertJsonPath('profile_completed', true);
    }
}
