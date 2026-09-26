<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileGenderTest extends TestCase
{
    use RefreshDatabase;

    public static function validGenders(): array
    {
        return [['homme'], ['femme']];
    }

    #[DataProvider('validGenders')]
    public function test_profile_accepts_only_the_offered_choices(string $gender): void
    {
        $user = User::create(['phone' => '+14185550103']);
        $user->profile()->create(['gender' => 'autre']);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile', [
            'first_name' => 'Test', 'last_name' => 'Profil', 'gender' => $gender,
        ])->assertOk()->assertJsonPath('profile.gender', $gender);
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'gender' => $gender]);
    }

    public static function invalidGenders(): array
    {
        return [['autre'], [''], [null], ['invalide']];
    }

    #[DataProvider('invalidGenders')]
    public function test_profile_rejects_other_or_unspecified_choices(?string $gender): void
    {
        $user = User::create(['phone' => '+14185550103']);
        $user->profile()->create(['gender' => 'femme']);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile', [
            'first_name' => 'Test', 'last_name' => 'Profil', 'gender' => $gender,
        ])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'gender' => 'femme']);
    }
}
