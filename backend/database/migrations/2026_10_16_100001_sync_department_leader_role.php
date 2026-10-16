<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le role « Responsable de departement » et les responsables nommes dans Organisation ne font
     * plus qu'un (App\Support\DepartmentLeadership) :
     * 1. le role existant a portee « departement » (cree par les pasteurs) devient le role de base
     *    responsable_departement : son nom et ses permissions sont gardes, il ne peut plus etre supprime
     *    ni changer de portee. A defaut, il est cree ;
     * 2. chaque titulaire du role sur un departement en devient responsable (et membre) ;
     * 3. chaque responsable nomme dans Organisation recoit le role sur son departement.
     */
    public function up(): void
    {
        $now = now();
        $managers = DB::table('users')->whereNotNull('platform_manager_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // --- 1. Le role
        $role = DB::table('roles')->where('key', 'responsable_departement')->first();
        if (! $role) {
            // Parmi les roles « Responsable… » a portee departement : celui qui a le plus de titulaires.
            $role = DB::table('roles')->where('scope_kind', 'department')->whereRaw('LOWER(name) LIKE ?', ['responsable%'])->get()
                ->sortByDesc(fn ($r) => DB::table('role_user')->where('role_id', $r->id)->count())->first();
        }
        if ($role) {
            DB::table('roles')->where('id', $role->id)->update(['key' => 'responsable_departement', 'is_system' => true, 'updated_at' => $now]);
            $roleId = (int) $role->id;
        } else {
            $roleId = DB::table('roles')->insertGetId([
                'key' => 'responsable_departement',
                'name' => 'Responsable de département',
                'description' => 'Responsable d’un département : suit ses membres, valide les inscriptions, fait l’appel des répétitions et publie ses événements.',
                'scope_kind' => 'department', 'rank' => 30, 'is_system' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $permissions = DB::table('permissions')->whereIn('key', [
                'members.view_scope', 'spiritual.view', 'attendance.view', 'attendance.record',
                'events.manage', 'events.delete', 'events.participants', 'exercises.respond', 'departments.approve',
            ])->pluck('id');
            foreach ($permissions as $permissionId) {
                DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        // --- 2. Titulaires du role -> responsables (et membres) du departement
        $leadersAdded = [];
        $assignments = DB::table('role_user')->where('role_id', $roleId)->where('scope_kind', 'department')->get();
        foreach ($assignments as $ru) {
            if (! $ru->scope_id || ! DB::table('departments')->where('id', $ru->scope_id)->exists()) {
                DB::table('role_user')->where('id', $ru->id)->delete(); // departement disparu

                continue;
            }
            if (in_array((int) $ru->user_id, $managers, true)) {
                continue;
            }
            $profileId = DB::table('profiles')->where('user_id', $ru->user_id)->value('id');
            if ($profileId && ! DB::table('department_profile')->where(['department_id' => $ru->scope_id, 'profile_id' => $profileId])->exists()) {
                DB::table('department_profile')->insert(['department_id' => $ru->scope_id, 'profile_id' => $profileId, 'created_at' => $now, 'updated_at' => $now]);
            }
            if (! DB::table('department_leaders')->where(['department_id' => $ru->scope_id, 'user_id' => $ru->user_id])->exists()) {
                DB::table('department_leaders')->insert(['department_id' => $ru->scope_id, 'user_id' => $ru->user_id, 'created_at' => $now, 'updated_at' => $now]);
                $leadersAdded[] = ['department_id' => (int) $ru->scope_id, 'user_id' => (int) $ru->user_id];
            }
        }

        // --- 3. Responsables d'Organisation -> role sur leur departement
        $rolesAdded = [];
        foreach (DB::table('department_leaders')->get() as $l) {
            if (in_array((int) $l->user_id, $managers, true)) {
                continue;
            }
            $scope = ['user_id' => $l->user_id, 'role_id' => $roleId, 'scope_kind' => 'department', 'scope_id' => $l->department_id];
            if (! DB::table('role_user')->where($scope)->exists()) {
                DB::table('role_user')->insert($scope + ['assigned_by' => null, 'created_at' => $now, 'updated_at' => $now]);
                $rolesAdded[] = ['department_id' => (int) $l->department_id, 'user_id' => (int) $l->user_id];
            }
        }

        DB::table('audit_logs')->insert([
            'user_id' => null, 'action' => 'department.leaders_synced',
            'new_values' => json_encode(['leaders_added' => $leadersAdded, 'roles_added' => $rolesAdded], JSON_UNESCAPED_UNICODE),
            'context' => json_encode(['source' => 'migration', 'role_id' => $roleId], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Les nominations restent : seul le role redevient personnalise (supprimable).
        DB::table('roles')->where('key', 'responsable_departement')->update(['is_system' => false, 'updated_at' => now()]);
    }
};
