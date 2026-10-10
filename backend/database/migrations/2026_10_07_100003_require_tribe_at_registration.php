<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tribu devient obligatoire a l'inscription. Les membres deja inscrits SANS tribu
 * retrouvent l'ecran d'inscription a leur prochaine ouverture de l'application : ils
 * choisissent leur tribu, puis tout redevient normal (aucune donnee n'est perdue).
 * En attendant, comme toute inscription inachevee, ils n'apparaissent plus chez les responsables.
 *
 * Exemptes : les pasteurs (PR, PA), les Assistants Pasteurs (qui suivent plusieurs tribus) et
 * l'administrateur.
 * Chaque compte rouvert est trace dans le journal d'audit ; le retour arriere s'appuie
 * sur ces traces pour ne retablir que ces comptes-la.
 */
return new class extends Migration
{
    private const EXEMPT_ROLES = ['super_admin', 'pasteur_assistant', 'assistant_pasteur', 'administrateur'];

    private const ACTION = 'profile.registration_reopened';

    public function up(): void
    {
        $exempt = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.key', self::EXEMPT_ROLES)->pluck('role_user.user_id')->unique()->all();

        $profiles = DB::table('profiles')->where('is_completed', true)->whereNull('tribe_id')
            ->when($exempt, fn ($q) => $q->whereNotIn('user_id', $exempt))
            ->get(['id', 'user_id']);

        $now = now();
        foreach ($profiles as $p) {
            DB::table('profiles')->where('id', $p->id)->update(['is_completed' => false]);
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'profile',
                'subject_id' => $p->id,
                'member_user_id' => $p->user_id,
                'old_values' => json_encode(['is_completed' => true]),
                'new_values' => json_encode(['is_completed' => false]),
                'context' => json_encode(['reason' => 'tribu obligatoire']),
                'ip' => null,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('audit_logs')->where('action', self::ACTION)->where('subject_type', 'profile')->pluck('subject_id');
        DB::table('profiles')->whereIn('id', $ids)->whereNull('tribe_id')->update(['is_completed' => true]);
        DB::table('audit_logs')->where('action', self::ACTION)->delete();
    }
};
