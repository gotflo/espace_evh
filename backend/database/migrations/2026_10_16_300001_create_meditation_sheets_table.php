<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meditation : fiches personnelles « Meditation de la Parole de Dieu » (cinq rubriques), datees a
 * l'envoi par le serveur. Lues par le membre, le patriarche de sa tribu et les pasteurs (PR, PA) :
 * permission confidentielle meditations.view donnee aux roles patriarche et pasteur_assistant
 * (le Pasteur Resident a toutes les permissions). Additif et reversible.
 */
return new class extends Migration
{
    private const PERMISSION = 'meditations.view';

    private const ROLES = ['super_admin', 'pasteur_assistant', 'patriarche'];

    public function up(): void
    {
        Schema::create('meditation_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('verse');
            $table->text('observation');
            $table->text('rhema');
            $table->text('action');
            $table->text('prayer');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
        });

        $def = PermissionCatalog::all()[self::PERMISSION];
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::PERMISSION, 'name' => $def[0], 'group' => $def['module_label'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $permission = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
        foreach (DB::table('roles')->whereIn('key', self::ROLES)->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        if ($permission = DB::table('permissions')->where('key', self::PERMISSION)->value('id')) {
            DB::table('permission_role')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
        Schema::dropIfExists('meditation_sheets');
    }
};
