<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Roles et departements :
     * 1. Le role « GAD » devient « GARDE » (meme role, meme portee GEM).
     * 2. Le role independant « Responsable (de departement) » est supprime : la responsabilite
     *    d'un departement devient une propriete du departement (table department_leaders,
     *    plusieurs responsables possibles) qui donne les memes droits. Les titulaires actuels
     *    sont repris automatiquement.
     * 3. L'AP ne voit plus toute l'eglise : la permission de consultation globale est supprimee.
     * 4. Nouvelles permissions : rapports, journal d'audit, validation des demandes de
     *    modification de FISS, validation des changements de tribu.
     * 5. Liste des departements corrigee (Coach Bloom retire).
     */
    public function up(): void
    {
        $now = now();
        $audit = fn (string $action, array $new = [], array $context = []) => DB::table('audit_logs')->insert([
            'user_id' => null, 'action' => $action, 'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'context' => json_encode($context + ['source' => 'migration'], JSON_UNESCAPED_UNICODE), 'created_at' => $now,
        ]);

        // --- 1. GAD -> GARDE
        DB::table('roles')->where('key', 'gad')->update([
            'key' => 'garde',
            'name' => 'Garde (responsable de GEM)',
            'description' => 'Mène un GEM (groupe de 3 à 5 membres de sa tribu).',
            'updated_at' => $now,
        ]);

        // --- 2. Responsables de departement
        Schema::create('department_leaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
            $table->index('user_id');
        });

        $pairs = [];
        foreach (DB::table('departments')->whereNotNull('leader_user_id')->get(['id', 'leader_user_id']) as $d) {
            $pairs[$d->id.'-'.$d->leader_user_id] = [$d->id, $d->leader_user_id];
        }
        $responsable = DB::table('roles')->where('key', 'responsable')->value('id');
        if ($responsable) {
            foreach (DB::table('role_user')->where('role_id', $responsable)->where('scope_kind', 'department')->get() as $ru) {
                if ($ru->scope_id && DB::table('departments')->where('id', $ru->scope_id)->exists()) {
                    $pairs[$ru->scope_id.'-'.$ru->user_id] = [$ru->scope_id, $ru->user_id];
                }
            }
        }
        foreach ($pairs as [$deptId, $userId]) {
            DB::table('department_leaders')->insert(['department_id' => $deptId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($responsable) {
            $holders = DB::table('role_user')->where('role_id', $responsable)->get(['user_id', 'scope_kind', 'scope_id']);
            $audit('role.removed', ['role' => 'responsable'], ['holders' => $holders, 'transferred_to' => 'department_leaders']);
            DB::table('role_user')->where('role_id', $responsable)->delete();
            DB::table('permission_role')->where('role_id', $responsable)->delete();
            DB::table('roles')->where('id', $responsable)->delete();
        }
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leader_user_id');
        });

        // --- 3. Plus de consultation globale pour l'AP
        $readonly = DB::table('permissions')->where('key', 'members.view_readonly')->value('id');
        if ($readonly) {
            DB::table('permission_role')->where('permission_id', $readonly)->delete();
            DB::table('permissions')->where('id', $readonly)->delete();
        }

        // --- 4. Nouvelles permissions
        $newPermissions = [
            'reports.view' => ['Voir les rapports et statistiques de son périmètre', 'Rapports'],
            'audit.view' => ["Consulter le journal d'audit", 'Rapports'],
            'fiss.review' => ['Traiter les demandes de modification de FISS', 'Suivi spirituel'],
            'tribes.transfer' => ['Valider les changements de tribu', 'Organisation'],
        ];
        foreach ($newPermissions as $key => [$name, $group]) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert(['key' => $key, 'name' => $name, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $grant = [
            'pasteur_assistant' => ['reports.view', 'audit.view', 'fiss.review', 'tribes.transfer'],
            'assistant_pasteur' => ['reports.view', 'fiss.review', 'tribes.transfer'],
            'patriarche' => ['reports.view', 'fiss.review', 'tribes.transfer'],
        ];
        foreach ($grant as $roleKey => $keys) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $pid) {
                if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $pid])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
        }

        // --- 5. Departements
        $rename = [
            'ecodime' => 'Ecodim 1',
            'gestion-des-cultes' => 'Gestion de cultes',
            'evangelisation' => 'Évangélisation',
            'integration' => 'Intégration',
            'bapteme' => 'Baptême',
        ];
        foreach ($rename as $slug => $name) {
            DB::table('departments')->where('slug', $slug)->update(['name' => $name, 'updated_at' => $now]);
        }
        foreach (['Ecodim 2', 'Eden 1', 'Eden 2', 'Wedding Planner'] as $name) {
            $slug = Str::slug($name);
            if (! DB::table('departments')->where('slug', $slug)->orWhere('name', $name)->exists()) {
                DB::table('departments')->insert(['name' => $name, 'slug' => $slug, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $coach = DB::table('departments')->whereRaw('LOWER(name) = ?', ['coach bloom'])->orWhere('slug', 'coach-bloom')->get();
        foreach ($coach as $dept) {
            $members = DB::table('department_profile')->where('department_id', $dept->id)->pluck('profile_id');
            $audit('department.deleted', ['id' => $dept->id, 'name' => $dept->name], ['member_profile_ids' => $members]);
            DB::table('departments')->where('id', $dept->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('leader_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
        foreach (DB::table('department_leaders')->orderBy('id')->get() as $l) {
            DB::table('departments')->where('id', $l->department_id)->whereNull('leader_user_id')->update(['leader_user_id' => $l->user_id]);
        }
        Schema::dropIfExists('department_leaders');

        DB::table('roles')->where('key', 'garde')->update(['key' => 'gad', 'name' => 'Responsable GEM (GAD)']);
        $ids = DB::table('permissions')->whereIn('key', ['reports.view', 'audit.view', 'fiss.review', 'tribes.transfer'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        // Le role « responsable » et la consultation globale de l'AP ne sont pas recrees : relancer
        // RolesAndPermissionsSeeder d'une version anterieure si un retour complet est necessaire.
    }
};
