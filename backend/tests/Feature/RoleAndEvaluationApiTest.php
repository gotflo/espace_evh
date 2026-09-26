<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAndEvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): User
    {
        $admin = User::create(['phone' => '+14185550101']);
        $role = Role::create(['key' => 'super_admin', 'name' => 'Administrateur', 'rank' => 100]);
        $admin->roles()->attach($role);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_role_assignment_is_persisted_and_removed_by_assignment_id(): void
    {
        $admin = $this->signInAdmin();
        $member = User::create(['phone' => '+14185550102']);
        $role = Role::create(['key' => 'test_member', 'name' => 'Membre', 'scope_kind' => 'none']);
        $url = "/api/admin/members/{$member->id}/roles";
        $this->postJson($url, ['role_key' => $role->key, 'scope_id' => null])->assertOk();
        $this->assertDatabaseHas('role_user', ['user_id' => $member->id, 'role_id' => $role->id, 'assigned_by' => $admin->id]);
        $this->postJson($url, ['role_key' => $role->key])->assertOk();
        $this->assertSame(1, DB::table('role_user')->where('user_id', $member->id)->count());
        $assignment = DB::table('role_user')->where('user_id', $member->id)->value('id');
        $this->deleteJson("{$url}/{$assignment}")->assertOk();
        $this->assertDatabaseMissing('role_user', ['id' => $assignment]);
    }

    public function test_custom_role_can_be_created_updated_and_deleted(): void
    {
        $this->signInAdmin();
        $body = ['name' => 'Rôle de vérification', 'scope_kind' => 'none', 'permission_keys' => []];
        $id = $this->postJson('/api/admin/roles', $body)->assertOk()->json('role_id');
        $this->getJson('/api/admin/roles')->assertOk()->assertJsonFragment(['name' => $body['name']]);
        $body['name'] = 'Rôle modifié';
        $this->putJson("/api/admin/roles/{$id}", $body)->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $id, 'name' => 'Rôle modifié']);
        $this->deleteJson("/api/admin/roles/{$id}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $id]);
    }

    public function test_evaluation_can_be_saved_listed_and_deleted(): void
    {
        $this->signInAdmin();
        $member = User::create(['phone' => '+14185550102']);
        $url = "/api/admin/members/{$member->id}/evaluations";
        $this->postJson($url, [
            'type' => 'meditation_perso', 'score' => 16, 'evaluated_on' => now()->toDateString(),
            'title' => 'Méditation', 'comment' => 'Vérification',
        ])->assertCreated();
        $id = $this->getJson($url)->assertOk()->assertJsonPath('evaluations.0.title', 'Méditation')->json('evaluations.0.id');
        $this->deleteJson("/api/admin/evaluations/{$id}")->assertOk();
        $this->assertDatabaseMissing('evaluations', ['id' => $id]);
    }

    public function test_role_assignment_still_requires_authentication_and_permission(): void
    {
        $member = User::create(['phone' => '+14185550102']);
        $url = "/api/admin/members/{$member->id}/roles";
        $this->postJson($url, [])->assertUnauthorized();
        Sanctum::actingAs($member);
        $this->postJson($url, [])->assertForbidden();
    }
}
