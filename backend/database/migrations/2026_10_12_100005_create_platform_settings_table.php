<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglages de la plateforme (rappels automatiques, horaires d'envoi, limites) : une ligne par reglage
 * modifie ; sans ligne, la valeur par defaut s'applique (comportement identique a avant). Permission
 * settings.manage, donnee aux roles qui gerent les roles (et au super administrateur ; le compte de
 * gestion la recoit par le catalogue). Additif et reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $id = DB::table('permissions')->where('key', 'settings.manage')->value('id') ?? DB::table('permissions')->insertGetId([
            'key' => 'settings.manage', 'name' => 'Modifier les réglages de la plateforme (rappels automatiques, horaires, limites)',
            'group' => 'Tableau de bord de gestion', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $rolesManage = DB::table('permissions')->where('key', 'roles.manage')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $rolesManage)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
        }
        if ($superAdmin = DB::table('roles')->where('key', 'super_admin')->value('id')) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        $id = DB::table('permissions')->where('key', 'settings.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
