<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versets du tableau de bord, geres par le Pasteur Resident et le Pasteur Assistant :
 * brouillon / publie, periode d'affichage facultative, ordre de rotation, mise en avant.
 * Le verset actuel (Actes 20.28) est repris comme premier texte publie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_verses', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);                 // ex. « Notre appel », « Parole du mois »
            $table->text('text');
            $table->string('reference', 80);             // ex. « Actes 20.28 »
            $table->string('message', 500)->nullable();  // mot d'accompagnement facultatif
            $table->string('status', 12)->default('draft'); // draft | published
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('position')->default(0); // ordre de rotation
            $table->boolean('is_pinned')->default(false);    // remplace la rotation pendant sa periode
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        $now = now();
        DB::table('dashboard_verses')->insert([
            'label' => 'Notre appel',
            'text' => "Prenez donc garde à vous-mêmes, et à tout le troupeau au sein duquel le Saint-Esprit vous a établis évêques, pour paître l'Église de Dieu, qu'il s'est acquise par son propre sang.",
            'reference' => 'Actes 20.28',
            'status' => 'published',
            'position' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')->where('key', 'content.manage')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'content.manage', 'name' => 'Gérer les versets du tableau de bord', 'group' => 'Communication',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        foreach (DB::table('roles')->whereIn('key', ['super_admin', 'pasteur_assistant'])->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'content.manage')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::dropIfExists('dashboard_verses');
    }
};
