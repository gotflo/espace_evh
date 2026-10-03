<?php

namespace App\Services\Monitoring;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Actions d'administration sur un compte membre depuis la console : blocage, deblocage,
 * fermeture des sessions et suppression (avec le resume de ce qui disparait ou reste).
 * Les consequences suivent les contraintes de la base : suppression en cascade des donnees
 * personnelles, auteur efface (null) sur ce que la personne a cree pour les autres.
 */
class UserAdmin
{
    /** Donnees personnelles supprimees avec le compte (cascade). [table, colonne, libelle] */
    private const DELETED = [
        ['profiles', 'user_id', 'Profil (identité, appartenance)'],
        ['spiritual_profiles', 'user_id', 'Profil spirituel'],
        ['spiritual_health_forms', 'user_id', 'Fiches de santé spirituelle (FISS)'],
        ['fiss_edit_requests', 'user_id', 'Demandes de modification de FISS'],
        ['attendances', 'member_user_id', 'Présences enregistrées'],
        ['evaluations', 'user_id', 'Notes reçues (Vertumètre)'],
        ['spiritual_entries', 'member_user_id', 'Entrées du journal spirituel'],
        ['milestones', 'member_user_id', 'Étapes spirituelles'],
        ['exercise_responses', 'user_id', 'Réponses aux exercices'],
        ['exercise_video_views', 'user_id', 'Suivi des vidéos'],
        ['event_participations', 'user_id', 'Réponses aux événements'],
        ['member_requests', 'user_id', 'Demandes adressées aux responsables'],
        ['tribe_change_requests', 'user_id', 'Demandes de changement de tribu'],
        ['family_links', 'user_id', 'Liens familiaux déclarés'],
        ['user_notifications', 'user_id', 'Notifications reçues'],
        ['announcement_user', 'user_id', 'Annonces reçues'],
        ['push_subscriptions', 'user_id', 'Appareils abonnés aux notifications'],
        ['role_user', 'user_id', 'Rôles attribués'],
        ['department_leaders', 'user_id', 'Responsabilités de département'],
        ['personal_access_tokens', 'tokenable_id', 'Sessions ouvertes'],
    ];

    /** Elements crees par la personne, conserves pour les autres avec un auteur efface. */
    private const KEPT = [
        ['announcements', 'created_by', 'Annonces publiées'],
        ['events', 'created_by', 'Événements créés (hors agenda personnel)'],
        ['exercises', 'created_by', 'Exercices créés'],
        ['evaluations', 'created_by', 'Notes attribuées à d\'autres membres'],
        ['spiritual_entries', 'author_user_id', 'Entrées rédigées pour d\'autres membres'],
        ['attendances', 'recorded_by', 'Présences saisies pour d\'autres membres'],
        ['leader_reports', 'author_user_id', 'Rapports mensuels rédigés'],
        ['gem_weekly_reports', 'author_user_id', 'Rapports de GEM rédigés'],
        ['audit_logs', 'user_id', 'Entrées du journal d\'audit (auteur)'],
        ['audit_logs', 'member_user_id', 'Entrées du journal d\'audit (membre concerné)'],
        ['family_links', 'relative_user_id', 'Liens familiaux déclarés par d\'autres (deviennent un nom libre)'],
    ];

    /** Fonctions occupees, liberees par la suppression. */
    private const ROLES_HELD = [
        ['tribes', 'patriarch_user_id', 'Patriarche d\'une tribu'],
        ['gems', 'leader_user_id', 'Garde d\'un GEM'],
    ];

    /** @return array<string, mixed> */
    public static function impact(User $user): array
    {
        $count = function (string $table, string $column, ?callable $extra = null) use ($user): int {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }
            $q = DB::table($table)->where($column, $user->id);
            if ($table === 'personal_access_tokens') {
                $q->where('tokenable_type', User::class);
            }
            if ($extra) {
                $extra($q);
            }

            return $q->count();
        };

        $deleted = [];
        foreach (self::DELETED as [$table, $column, $label]) {
            if ($n = $count($table, $column)) {
                $deleted[] = ['label' => $label, 'count' => $n];
            }
        }
        $personal = $count('events', 'created_by', fn ($q) => $q->where('is_personal', true));
        if ($personal) {
            $deleted[] = ['label' => 'Agenda personnel', 'count' => $personal];
        }
        $kept = [];
        foreach (self::KEPT as [$table, $column, $label]) {
            $n = $table === 'events'
                ? $count($table, $column, fn ($q) => $q->where('is_personal', false))
                : ($table === 'evaluations' || $table === 'spiritual_entries' || $table === 'attendances'
                    ? $count($table, $column, fn ($q) => $q->where($table === 'evaluations' ? 'user_id' : 'member_user_id', '!=', $user->id))
                    : $count($table, $column));
            if ($n) {
                $kept[] = ['label' => $label, 'count' => $n];
            }
        }
        $held = [];
        foreach (self::ROLES_HELD as [$table, $column, $label]) {
            if ($n = $count($table, $column)) {
                $held[] = ['label' => $label, 'count' => $n];
            }
        }
        $memberScoped = DB::table('role_user')->where('scope_kind', 'member')->where('scope_id', $user->id)->count();
        if ($memberScoped) {
            $held[] = ['label' => 'Accompagnements de ce membre confiés à d\'autres responsables (retirés)', 'count' => $memberScoped];
        }

        $isSuperAdmin = $user->roles()->where('key', User::SUPER_ADMIN)->exists();

        return [
            'deleted' => $deleted,
            'kept' => $kept,
            'released' => $held,
            'warnings' => array_values(array_filter([
                $isSuperAdmin ? 'Ce compte a le rôle super administrateur de l\'église.' : null,
                $held ? 'Les fonctions occupées deviendront vacantes : pensez à désigner un remplaçant.' : null,
                'La suppression est définitive : la personne pourra se réinscrire avec son numéro, mais repartira d\'un compte vide.',
            ])),
            'is_super_admin' => $isSuperAdmin,
        ];
    }

    public static function revokeSessions(User $user): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
    }

    public static function block(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason) {
            $user->forceFill(['blocked_at' => now(), 'blocked_reason' => mb_substr($reason, 0, 255)])->save();
            self::revokeSessions($user);
            // Plus aucune notification sur ses appareils tant que le compte est bloque.
            DB::table('push_subscriptions')->where('user_id', $user->id)->delete();
        });
    }

    public static function unblock(User $user): void
    {
        $user->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
    }

    public static function delete(User $user): void
    {
        $photo = $user->profile?->photo_path ?? null;
        DB::transaction(function () use ($user) {
            self::revokeSessions($user);
            DB::table('role_user')->where('scope_kind', 'member')->where('scope_id', $user->id)->delete();
            // Agenda personnel : visible par la personne seule, il disparait avec elle.
            \App\Models\Event::where('is_personal', true)->where('created_by', $user->id)->get()->each->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            // Mesures de supervision : on ne garde plus de lien vers la personne.
            DB::table('monitor_events')->where('user_id', $user->id)->update(['user_id' => null]);
            DB::table('monitor_requests')->where('user_id', $user->id)->update(['user_id' => null]);
            DB::table('monitor_user_days')->where('user_id', $user->id)->delete();
            $user->delete();
        });
        if (is_string($photo) && $photo !== '') {
            try {
                Storage::disk('public')->delete($photo);
            } catch (\Throwable) {
                // fichier deja absent
            }
        }
    }
}
