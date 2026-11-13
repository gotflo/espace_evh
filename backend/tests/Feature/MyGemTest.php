<?php

namespace Tests\Feature;

use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\PlatformManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menu « Mon GEM » : chaque fidele voit qui compose SON GEM (nom et photo seulement) et qui en est le Garde.
 * Rien ne permet d'ouvrir la fiche de quelqu'un : aucun identifiant, numero ni courriel dans la reponse.
 */
class MyGemTest extends TestCase
{
    use RefreshDatabase;

    private Tribe $juda;

    private Gem $bethel;

    private Gem $silo;

    private User $garde;

    private User $marie;

    private User $zoe;

    private User $other;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->juda = Tribe::create(['name' => 'Juda', 'slug' => 'juda']);
        $this->bethel = Gem::create(['name' => 'Béthel', 'tribe_id' => $this->juda->id, 'is_active' => true]);
        $this->silo = Gem::create(['name' => 'GEM Silo', 'tribe_id' => $this->juda->id, 'is_active' => true]);

        $this->zoe = $this->person('Zoé', 'Zacharie', $this->bethel);
        $this->marie = $this->person('Marie', 'Fidèle', $this->bethel);
        $this->garde = $this->person('Gaston', 'Garde', $this->bethel);
        $this->garde->roles()->attach(Role::where('key', 'garde')->value('id'), ['scope_kind' => 'gem', 'scope_id' => $this->bethel->id]);
        $this->bethel->forceFill(['leader_user_id' => $this->garde->id])->save();
        $this->other = $this->person('Olga', 'Ailleurs', $this->silo);
    }

    public function test_a_member_sees_the_members_of_her_gem_with_the_garde_marked_and_nothing_else(): void
    {
        Sanctum::actingAs($this->marie->fresh());
        $res = $this->getJson('/api/me/gem')->assertOk()
            ->assertJsonPath('gem.label', 'GEM Béthel')->assertJsonPath('gem.tribe', 'Juda');

        // Le Garde en tete, puis les autres par prenom ; personne d'un autre GEM.
        $this->assertSame([
            ['name' => 'Gaston Garde', 'photo_url' => null, 'is_garde' => true, 'is_me' => false],
            ['name' => 'Marie Fidèle', 'photo_url' => null, 'is_garde' => false, 'is_me' => true],
            ['name' => 'Zoé Zacharie', 'photo_url' => null, 'is_garde' => false, 'is_me' => false],
        ], $res->json('gem.members'));
        $this->assertSame(['label', 'tribe', 'members'], array_keys($res->json('gem')));

        // Rien qui mene a une fiche : ni identifiant, ni numero, ni courriel.
        $body = $res->getContent();
        foreach ([$this->garde, $this->zoe, $this->other] as $u) {
            $this->assertStringNotContainsString($u->phone, $body);
        }
        $this->assertStringNotContainsString('@exemple.test', $body);
        $this->assertStringNotContainsString('user_id', $body);
        $this->assertStringNotContainsString('Olga', $body);
    }

    public function test_a_plain_member_still_cannot_open_the_file_of_someone_in_her_gem(): void
    {
        Sanctum::actingAs($this->marie->fresh());
        $this->getJson("/api/admin/members/{$this->zoe->id}")->assertForbidden();
        $this->getJson("/api/admin/members/{$this->garde->id}")->assertForbidden();
    }

    public function test_the_garde_sees_himself_marked_and_each_gem_stays_separate(): void
    {
        Sanctum::actingAs($this->garde->fresh());
        $members = $this->getJson('/api/me/gem')->assertOk()->json('gem.members');
        $this->assertSame(['name' => 'Gaston Garde', 'photo_url' => null, 'is_garde' => true, 'is_me' => true], $members[0]);
        $this->assertCount(3, $members);

        // Autre GEM, sans Garde : sa propre liste, personne de marque ; nom deja prefixe : pas de « GEM GEM ».
        Sanctum::actingAs($this->other->fresh());
        $res = $this->getJson('/api/me/gem')->assertOk()->assertJsonPath('gem.label', 'GEM Silo');
        $this->assertSame([['name' => 'Olga Ailleurs', 'photo_url' => null, 'is_garde' => false, 'is_me' => true]], $res->json('gem.members'));
    }

    public function test_the_garde_is_listed_even_when_his_own_profile_is_not_placed_in_the_gem(): void
    {
        Profile::where('user_id', $this->garde->id)->update(['gem_id' => null]);
        Sanctum::actingAs($this->marie->fresh());
        $members = $this->getJson('/api/me/gem')->assertOk()->json('gem.members');
        $this->assertSame(['Gaston Garde', 'Marie Fidèle', 'Zoé Zacharie'], array_column($members, 'name'));
        $this->assertTrue($members[0]['is_garde']);
    }

    public function test_an_unfinished_profile_is_not_listed_and_a_member_without_gem_gets_nothing(): void
    {
        Profile::where('user_id', $this->zoe->id)->update(['is_completed' => false]);
        Sanctum::actingAs($this->marie->fresh());
        $this->assertSame(['Gaston Garde', 'Marie Fidèle'], array_column($this->getJson('/api/me/gem')->assertOk()->json('gem.members'), 'name'));

        Sanctum::actingAs($this->person('Sans', 'Groupe', null)->fresh());
        $this->getJson('/api/me/gem')->assertOk()->assertExactJson(['gem' => null]);
    }

    public function test_it_needs_a_session_and_the_management_account_has_no_gem_page(): void
    {
        $this->getJson('/api/me/gem')->assertUnauthorized();

        Sanctum::actingAs(PlatformManager::activate('+22893471703')[0]);
        $this->getJson('/api/me/gem')->assertForbidden();
    }

    private function person(string $first, string $last, ?Gem $gem): User
    {
        $n = ++$this->n;
        $user = User::create(['phone' => '+1418557'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'last_login_at' => now()]);
        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'femme',
            'email' => Str::lower(Str::ascii($first)).'.'.Str::lower(Str::ascii($last)).'@exemple.test',
            'is_completed' => true, 'tribe_id' => $this->juda->id, 'gem_id' => $gem?->id]);
        $user->roles()->attach(Role::where('key', 'fidele')->value('id'));

        return $user;
    }
}
