<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Synchronise l'attribution d'un role de responsable a la portee d'une entite
 * (ex : nommer un GAD sur un GEM lui donne le role 'gad' scope 'gem' = gemId).
 * Le changement est pris en compte au prochain chargement du compte du responsable.
 */
class LeaderRole
{
    public static function sync(string $roleKey, string $scopeKind, int $scopeId, ?int $userId, int $assignedBy): void
    {
        $role = Role::where('key', $roleKey)->first();
        if (! $role) {
            return;
        }

        // Retire ce role+portee des anciens titulaires (sauf le nouveau).
        DB::table('role_user')
            ->where('role_id', $role->id)->where('scope_kind', $scopeKind)->where('scope_id', $scopeId)
            ->when($userId, fn ($q) => $q->where('user_id', '!=', $userId))
            ->delete();

        if (! $userId) {
            return;
        }

        $exists = DB::table('role_user')->where([
            'user_id' => $userId, 'role_id' => $role->id, 'scope_kind' => $scopeKind, 'scope_id' => $scopeId,
        ])->exists();

        if (! $exists) {
            DB::table('role_user')->insert([
                'user_id' => $userId, 'role_id' => $role->id,
                'scope_kind' => $scopeKind, 'scope_id' => $scopeId,
                'assigned_by' => $assignedBy, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
