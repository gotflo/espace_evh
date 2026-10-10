<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'activite en lecture seule (permission audit.view). Aucune route de modification.
 * Les valeurs modifiees, le contexte et l'adresse IP (donnees personnelles) demandent audit.view_details.
 * Les operations du compte de gestion principal n'y figurent pas (journal de securite prive).
 */
class AuditLogController extends Controller
{
    public const LABELS = [
        'fiss.created' => 'FISS enregistrée',
        'fiss.locked' => 'FISS verrouillée',
        'fiss.unlocked' => 'FISS déverrouillée',
        'fiss.modified' => 'FISS modifiée',
        'fiss.edit_requested' => 'Demande de modification de FISS',
        'fiss.edit_approved' => 'Modification de FISS approuvée',
        'fiss.edit_rejected' => 'Modification de FISS refusée',
        'fiss.edit_cancelled' => 'Demande de modification annulée',
        'tribe_change.requested' => 'Changement de tribu demandé',
        'tribe_change.approved' => 'Changement de tribu approuvé',
        'tribe_change.rejected' => 'Changement de tribu refusé',
        'tribe_change.cancelled' => 'Demande de changement de tribu annulée',
        'tribe.changed' => 'Tribu modifiée',
        'member.inactivated' => 'Membre devenu inactif',
        'member.reactivated' => 'Membre réactivé',
        'member.status_forced' => "Statut d'activité forcé",
        'member.belonging_updated' => 'Appartenance modifiée',
        'member.welcomed' => 'Nouvel inscrit accueilli',
        'member.blocked' => 'Compte bloqué (console de supervision)',
        'member.unblocked' => 'Compte débloqué (console de supervision)',
        'member.sessions_revoked' => 'Sessions fermées (console de supervision)',
        'member.deleted' => 'Compte supprimé',
        'member.deletion_requested' => 'Suppression d\'un membre demandée',
        'member.deletion_rejected' => 'Demande de suppression refusée',
        'phone.unblocked' => 'Numéro débloqué pour une inscription',
        'profile.updated' => 'Profil modifié',
        'family.spouse_designated' => 'Conjoint(e) désigné(e)',
        'family.spouse_name_set' => 'Nom du conjoint renseigné',
        'family.spouse_unlinked' => 'Lien conjugal retiré',
        'family.link_confirmed' => 'Lien familial confirmé',
        'family.link_declined' => 'Lien familial refusé',
        'family.children_updated' => 'Enfants modifiés',
        'role.assigned' => 'Rôle attribué',
        'role.revoked' => 'Rôle retiré',
        'role.removed' => 'Rôle supprimé',
        'role.created' => 'Rôle créé',
        'role.updated' => 'Rôle ou permissions modifiés',
        'role.deleted' => 'Rôle supprimé',
        'department.deleted' => 'Département supprimé',
        'service_join.requested' => 'Inscription à un service demandée',
        'service_join.approved' => 'Inscription à un service validée',
        'service_join.rejected' => 'Inscription à un service refusée',
        'service_join.cancelled' => 'Demande d’inscription à un service retirée',
        'department.leaders_updated' => 'Responsables de département modifiés',
        'announcement.published' => 'Annonce publiée',
        'announcement.deleted' => 'Annonce supprimée',
        'event.created' => 'Événement créé',
        'event.updated' => 'Événement modifié',
        'event.deleted' => 'Événement supprimé',
        'exercise.created' => 'Exercice créé',
        'exercise.deleted' => 'Exercice supprimé',
        'evaluation.created' => 'Note ajoutée',
        'evaluation.deleted' => 'Note supprimée',
        'report.exported' => 'Rapport exporté (PDF)',
        'leaders.exported' => 'Liste des responsables exportée (PDF)',
        'settings.updated' => 'Réglages de la plateforme modifiés',
        'service_join.auto_approved' => 'Inscription au service validée automatiquement',
        'resolutions.auto_renewed' => 'Résolutions reprises automatiquement',
        'leader_report.submitted' => 'Rapport mensuel envoyé',
        'leader_report.updated' => 'Rapport mensuel corrigé',
        'leader_report.exported' => 'Rapport mensuel exporté (PDF)',
        'gem_report.submitted' => 'Rapport de GEM envoyé',
        'gem_report.updated' => 'Rapport de GEM corrigé',
        'verse.created' => 'Verset du tableau de bord ajouté',
        'verse.updated' => 'Verset du tableau de bord modifié',
        'verse.deleted' => 'Verset du tableau de bord supprimé',
        'verse.reordered' => 'Ordre des versets modifié',
        'service.created' => 'Horaire de culte ajouté',
        'service.updated' => 'Horaire de culte modifié',
        'service.deleted' => 'Horaire de culte retiré',
        'assignment.created' => 'Devoir (DS) publié',
        'assignment.updated' => 'Devoir (DS) modifié',
        'assignment.deleted' => 'Devoir (DS) retiré',
        'resolution_resource.created' => 'Ressource RM ajoutée',
        'resolution_resource.updated' => 'Ressource RM modifiée',
        'resolution_resource.deleted' => 'Ressource RM retirée',
        'resolution_resource.published' => 'Ressource publiée',
        'resolution_resource.unpublished' => 'Ressource dépubliée',
        'profile.registration_reopened' => 'Inscription à compléter (tribu obligatoire)',
        'vertumetre.created' => 'Vertumètre enregistré',
        'vertumetre.locked' => 'Vertumètre verrouillé',
        'appointment.requested' => 'Rendez-vous demandé',
        'appointment.confirmed' => 'Rendez-vous confirmé',
        'appointment.moved' => 'Rendez-vous déplacé',
        'appointment.cancelled' => 'Rendez-vous annulé',
        'appointment.revoked' => 'Rendez-vous révoqué',
        'appointment.done' => 'Rendez-vous fait',
        'appointment.no_show' => 'Absent au rendez-vous',
        'appointment.availability' => 'Disponibilités du pasteur modifiées',
        'appointment.window_added' => 'Plage de rendez-vous ajoutée',
        'appointment.blocked' => 'Rendez-vous : séance ou créneau bloqué',
        'appointment.unblocked' => 'Rendez-vous : de nouveau disponible',
        'vertumetre.unlocked' => 'Vertumètre déverrouillé',
        'vertumetre.modified' => 'Vertumètre modifié',
        'vertumetre.edit_requested' => 'Demande de modification du Vertumètre',
        'vertumetre.edit_approved' => 'Modification du Vertumètre approuvée',
        'vertumetre.edit_rejected' => 'Modification du Vertumètre refusée',
        'vertumetre.edit_cancelled' => 'Demande de modification du Vertumètre annulée',
    ];

    /**
     * Actions qui concernent la vie ou les donnees personnelles d'un fidele : visibles seulement avec
     * audit.view_details (sans elle, le journal ne montre que les operations de gestion).
     */
    public const PERSONAL_PREFIXES = [
        'fiss.', 'vertumetre.', 'profile.', 'family.', 'evaluation.', 'appointment.', 'tribe_change.',
        'tribe.changed', 'member.', 'phone.', 'leader_report.', 'gem_report.', 'report.', 'service_join.', 'resolutions.',
    ];

    public static function isPersonal(string $action): bool
    {
        foreach (self::PERSONAL_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'member_id' => ['nullable', 'integer'],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'before' => ['nullable', 'integer'],
        ]);

        $details = $request->user()->hasPermission('audit.view_details');
        $query = AuditLog::ordinary()->with('actor.profile:id,user_id,first_name,last_name', 'member.profile:id,user_id,first_name,last_name')->orderByDesc('id');
        if (! $details) {
            foreach (self::PERSONAL_PREFIXES as $prefix) {
                Like::whereNot($query, 'action', Like::escape($prefix).'%');
            }
        }
        if (! empty($data['action'])) {
            Like::where($query, 'action', Like::escape($data['action']).'%');
        }
        foreach (['member_id' => 'member_user_id', 'actor_id' => 'user_id'] as $param => $column) {
            if (! empty($data[$param])) {
                $query->where($column, $data[$param]);
            }
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', $data['to'].' 23:59:59');
        }
        if (! empty($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        $items = $query->limit(51)->get();

        return response()->json([
            'logs' => $items->take(50)->map(fn (AuditLog $l) => $details ? [
                'id' => $l->id,
                'action' => $l->action,
                'label' => self::LABELS[$l->action] ?? $l->action,
                'actor' => $l->actor?->profile?->full_name ?: ($l->user_id ? 'Utilisateur #'.$l->user_id : 'Système'),
                'member' => $l->member?->profile?->full_name,
                'member_user_id' => $l->member_user_id,
                'subject' => $l->subject_type ? $l->subject_type.' #'.$l->subject_id : null,
                'old' => $l->old_values,
                'new' => $l->new_values,
                'context' => $l->context,
                'ip' => $l->ip,
                'created_at' => $l->created_at?->toIso8601String(),
            ] : [
                'id' => $l->id,
                'action' => $l->action,
                'label' => self::LABELS[$l->action] ?? $l->action,
                'actor' => $l->actor?->profile?->full_name ?: ($l->user_id ? 'Utilisateur #'.$l->user_id : 'Système'),
                'member' => $l->member?->profile?->full_name,
                'member_user_id' => $l->member_user_id,
                'subject' => $l->subject_type ? $l->subject_type.' #'.$l->subject_id : null,
                'old' => null,
                'new' => null,
                'context' => null,
                'ip' => null,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values(),
            'can_view_details' => $details,
            'has_more' => $items->count() > 50,
            'actions' => collect(self::LABELS)->reject(fn ($label, $key) => ! $details && self::isPersonal($key))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }
}
