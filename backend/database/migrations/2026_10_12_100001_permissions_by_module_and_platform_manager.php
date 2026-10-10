<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions par module et compte de gestion principal (additif et reversible) :
 * - permissions detaillees (consulter / creer / modifier / supprimer / publier / exporter...) rangees par
 *   module ; chaque role garde exactement ce qu'il faisait (une permission « parente » donne ses
 *   nouvelles permissions detaillees, voir PermissionCatalog::SPLITS) ;
 * - content.manage est remplace par les permissions des versets et des horaires (memes roles) ;
 *   les devoirs (DS) et les ressources restent reserves au Pasteur Resident (super_admin) ;
 * - users.platform_manager_at : compte de gestion principal active (numero reserve + OTP + code d'activation) ;
 * - security_logs : journal de securite prive (jamais expose dans l'application) ;
 * - manager_activation_codes : codes d'activation a usage unique (seule l'empreinte est stockee) ;
 * - resolution_resources.status : brouillon / publie (les ressources existantes restent publiees).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('platform_manager_at')->nullable()->after('approval_status');
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('level', 10)->default('info'); // info | warning | alert
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('member_user_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index('user_id');
        });

        Schema::create('manager_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('resolution_resources', function (Blueprint $table) {
            $table->string('status', 12)->default('published')->after('note'); // draft | published
        });

        $now = now();
        $existing = DB::table('permissions')->pluck('id', 'key');

        // Catalogue : permissions nouvelles creees, libelles et modules mis a jour.
        foreach (PermissionCatalog::all() as $key => $def) {
            if (isset($existing[$key])) {
                DB::table('permissions')->where('id', $existing[$key])->update(['name' => $def[0], 'group' => $def['module_label'], 'updated_at' => $now]);
            } else {
                DB::table('permissions')->insert(['key' => $key, 'name' => $def[0], 'group' => $def['module_label'], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $ids = DB::table('permissions')->pluck('id', 'key');

        // Decoupage : les roles qui avaient la permission parente recoivent les permissions detaillees.
        foreach (PermissionCatalog::SPLITS as $parent => $children) {
            if (! isset($ids[$parent])) {
                continue;
            }
            $roleIds = DB::table('permission_role')->where('permission_id', $ids[$parent])->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($children as $child) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$child]]);
                }
            }
        }

        // Pasteur Resident : toutes les permissions (il les a deja toutes par son role ; affichage coherent).
        if ($superAdmin = DB::table('roles')->where('key', 'super_admin')->value('id')) {
            foreach (PermissionCatalog::keys() as $key) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $superAdmin, 'permission_id' => $ids[$key]]);
            }
        }

        // content.manage est remplace par les permissions detaillees ci-dessus.
        if (isset($ids['content.manage'])) {
            DB::table('permission_role')->where('permission_id', $ids['content.manage'])->delete();
            DB::table('permissions')->where('id', $ids['content.manage'])->delete();
        }
    }

    public function down(): void
    {
        $now = now();
        $ids = DB::table('permissions')->pluck('id', 'key');

        // content.manage retrouve les roles qui pouvaient modifier les versets.
        $contentId = DB::table('permissions')->insertGetId([
            'key' => 'content.manage', 'name' => 'Gérer les versets du tableau de bord', 'group' => 'Communication',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if (isset($ids['verses.edit'])) {
            foreach (DB::table('permission_role')->where('permission_id', $ids['verses.edit'])->pluck('role_id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $contentId]);
            }
        }

        $added = [
            'accounts.view', 'exercises.delete', 'exercises.view_responses', 'announcements.delete', 'events.delete',
            'events.participants', 'verses.view', 'verses.edit', 'verses.publish', 'verses.delete', 'schedule.view',
            'schedule.edit', 'schedule.delete', 'resources.view', 'resources.edit', 'resources.publish', 'resources.delete',
            'assignments.publish', 'assignments.delete', 'reports.export', 'audit.view_details',
        ];
        $addedIds = DB::table('permissions')->whereIn('key', $added)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $addedIds)->delete();
        DB::table('permissions')->whereIn('id', $addedIds)->delete();

        Schema::table('resolution_resources', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::dropIfExists('manager_activation_codes');
        Schema::dropIfExists('security_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('platform_manager_at'));
    }
};
