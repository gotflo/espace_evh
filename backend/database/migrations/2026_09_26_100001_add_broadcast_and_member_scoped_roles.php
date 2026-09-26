<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * - broadcast.all : diffuser annonces / evenements / exercices a toute l'eglise.
     * - Role « Accompagnateur d'un fidele » (portee : un fidele precis) : permet d'agir sur ce fidele.
     * - Role « Communication (toute l'église) » : publier pour toute l'eglise.
     * Les roles qui diffusaient deja a tous (voir tous les membres + publier) gardent ce droit.
     */
    public function up(): void
    {
        $now = now();
        $permissions = [
            'broadcast.all' => ["Diffuser à toute l'église (annonces, événements, exercices)", 'Communication'],
        ];
        foreach ($permissions as $key => [$name, $group]) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert(['key' => $key, 'name' => $name, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $perm = fn (string $key) => DB::table('permissions')->where('key', $key)->value('id');
        $attach = function (int $roleId, array $keys) use ($perm) {
            foreach ($keys as $key) {
                $pid = $perm($key);
                if ($pid && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $pid])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
        };

        // Continuite : qui voyait tout et publiait diffusait deja a toute l'eglise.
        $viewAll = $perm('members.view_all');
        $publish = DB::table('permissions')->whereIn('key', ['announcements.publish', 'events.manage', 'exercises.assign'])->pluck('id');
        if ($viewAll) {
            $roleIds = DB::table('permission_role')->where('permission_id', $viewAll)->pluck('role_id')
                ->filter(fn ($rid) => DB::table('permission_role')->where('role_id', $rid)->whereIn('permission_id', $publish)->exists());
            foreach ($roleIds as $rid) {
                $attach($rid, ['broadcast.all']);
            }
        }

        $roles = [
            'accompagnateur' => [
                'name' => "Accompagnateur d'un fidèle",
                'description' => "Peut agir sur le fidèle désigné : suivi spirituel, notes, demandes, statut. À attribuer par exemple à un AP.",
                'scope_kind' => 'member', 'rank' => 35,
                'permissions' => ['members.view_scope', 'members.edit', 'spiritual.view', 'spiritual.record', 'evaluations.manage', 'requests.handle', 'exercises.respond'],
            ],
            'communication' => [
                'name' => "Communication (toute l'église)",
                'description' => "Publie des annonces et crée des événements qui atteignent toute l'église.",
                'scope_kind' => 'none', 'rank' => 45,
                'permissions' => ['announcements.publish', 'events.manage', 'broadcast.all', 'exercises.respond'],
            ],
        ];
        foreach ($roles as $key => $cfg) {
            $id = DB::table('roles')->where('key', $key)->value('id');
            if (! $id) {
                $id = DB::table('roles')->insertGetId([
                    'key' => $key, 'name' => $cfg['name'], 'description' => $cfg['description'],
                    'scope_kind' => $cfg['scope_kind'], 'rank' => $cfg['rank'], 'is_system' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $attach($id, $cfg['permissions']);
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('key', ['accompagnateur', 'communication'])->pluck('id');
        DB::table('role_user')->whereIn('role_id', $roleIds)->delete();
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        $permIds = DB::table('permissions')->whereIn('key', ['broadcast.all'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
