<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nouveaux comptes valides par le pasteur, et absence d'une semaine.
 *
 * - approval_status : approved (par defaut : tous les comptes existants), pending (inscription en attente
 *   du pasteur), rejected. Un compte en attente garde un profil « non termine » (is_completed = false) :
 *   il n'apparait donc dans aucune liste, statistique, audience ou relance tant qu'il n'est pas valide.
 * - Les inscriptions commencees mais jamais envoyees (aucun matricule) passent par la validation.
 * - absence_notified_at : dernier courriel « plus d'une semaine sans connexion » (un par absence).
 * - Permission members.approve (le Pasteur Resident l'a d'office ; il peut la confier a un autre role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status', 12)->default('approved')->after('blocked_reason')->index();
            $table->timestamp('approval_requested_at')->nullable()->after('approval_status');
            $table->timestamp('approval_decided_at')->nullable()->after('approval_requested_at');
            $table->unsignedBigInteger('approval_decided_by')->nullable()->after('approval_decided_at');
            $table->timestamp('absence_notified_at')->nullable()->after('approval_decided_by');
        });

        // Inscriptions jamais terminees, de simples membres : elles seront validees par le pasteur.
        $staffRoles = DB::table('roles')->where('key', '!=', 'fidele')->pluck('id');
        $neverSubmitted = DB::table('profiles')->where('is_completed', false)
            ->where(fn ($q) => $q->whereNull('matricule')->orWhere('matricule', ''))->pluck('user_id');
        $withStaffRole = DB::table('role_user')->whereIn('role_id', $staffRoles)->pluck('user_id');
        DB::table('users')->whereIn('id', $neverSubmitted)->whereNotIn('id', $withStaffRole)->update(['approval_status' => 'pending']);

        $now = now();
        DB::table('permissions')->updateOrInsert(['key' => 'members.approve'],
            ['name' => 'Valider les nouvelles inscriptions', 'group' => 'Membres', 'created_at' => $now, 'updated_at' => $now]);
        $permission = DB::table('permissions')->where('key', 'members.approve')->value('id');
        $superAdmin = DB::table('roles')->where('key', 'super_admin')->value('id');
        if ($permission && $superAdmin) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $superAdmin]);
        }
    }

    public function down(): void
    {
        // Un compte encore en attente retrouve l'acces (comportement d'avant).
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_requested_at', 'approval_decided_at', 'approval_decided_by', 'absence_notified_at']);
        });
        $permission = DB::table('permissions')->where('key', 'members.approve')->value('id');
        if ($permission) {
            DB::table('permission_role')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
    }
};
