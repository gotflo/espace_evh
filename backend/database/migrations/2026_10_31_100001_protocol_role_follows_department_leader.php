<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le role « Equipe du protocole » suit desormais les responsables du departement Protocole
     * (App\Support\DepartmentLeadership) : ceux qui sont deja responsables le recoivent sur ce departement.
     */
    public function up(): void
    {
        $role = DB::table('roles')->where('key', 'protocole')->first();
        $department = DB::table('departments')->where('slug', 'protocole')->first();
        if (! $role || ! $department) {
            return;
        }
        $managers = DB::table('users')->whereNotNull('platform_manager_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (DB::table('department_leaders')->where('department_id', $department->id)->pluck('user_id') as $userId) {
            $scope = ['user_id' => $userId, 'role_id' => $role->id, 'scope_kind' => 'department', 'scope_id' => $department->id];
            if (! in_array((int) $userId, $managers, true) && ! DB::table('role_user')->where($scope)->exists()) {
                DB::table('role_user')->insert($scope + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('key', 'protocole')->first();
        if ($role) {
            DB::table('role_user')->where('role_id', $role->id)->where('scope_kind', 'department')->delete();
        }
    }
};
