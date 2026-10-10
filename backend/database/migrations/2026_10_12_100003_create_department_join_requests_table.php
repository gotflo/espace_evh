<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription a un service (departement) validee par son responsable : la demande attend sa decision
 * (avant : integration immediate). Permission departments.approve, donnee aux roles qui gerent les
 * departements ; les responsables de departement la recoivent par leur responsabilite. Additif et reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        $now = now();
        $id = DB::table('permissions')->where('key', 'departments.approve')->value('id') ?? DB::table('permissions')->insertGetId([
            'key' => 'departments.approve', 'name' => 'Valider les inscriptions aux services de ses départements',
            'group' => 'Organisation (tribus, départements, GEMs)', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $manage = DB::table('permissions')->where('key', 'departments.manage')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $manage)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
        }
        if ($superAdmin = DB::table('roles')->where('key', 'super_admin')->value('id')) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_join_requests');
        $id = DB::table('permissions')->where('key', 'departments.approve')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
