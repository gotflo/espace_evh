<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglages de la plateforme : reserves au Pasteur Resident (super administrateur) et au compte de gestion.
 * La permission settings.manage est retiree des autres roles (elle reste attribuable depuis l'ecran « Roles »).
 * Les roles qui l'avaient sont traces pour un retour arriere exact.
 */
return new class extends Migration
{
    private const ACTION = 'permission.settings_restricted';

    public function up(): void
    {
        $permission = DB::table('permissions')->where('key', 'settings.manage')->value('id');
        if (! $permission) {
            return;
        }
        $superAdmin = DB::table('roles')->where('key', 'super_admin')->value('id');
        $roles = DB::table('permission_role')->where('permission_id', $permission)
            ->when($superAdmin, fn ($q) => $q->where('role_id', '!=', $superAdmin))->pluck('role_id');
        foreach ($roles as $roleId) {
            DB::table('audit_logs')->insert([
                'action' => self::ACTION, 'subject_type' => 'role', 'subject_id' => $roleId,
                'old_values' => json_encode(['permission' => 'settings.manage']), 'created_at' => now(),
            ]);
        }
        DB::table('permission_role')->where('permission_id', $permission)->whereIn('role_id', $roles)->delete();
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('key', 'settings.manage')->value('id');
        if (! $permission) {
            return;
        }
        foreach (DB::table('audit_logs')->where('action', self::ACTION)->pluck('subject_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission]);
        }
        DB::table('audit_logs')->where('action', self::ACTION)->delete();
    }
};
