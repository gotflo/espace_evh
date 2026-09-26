<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Journal d'audit en lecture seule (permission audit.view). Aucune route de modification. */
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
        'department.deleted' => 'Département supprimé',
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
    ];

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

        $query = AuditLog::with('actor.profile:id,user_id,first_name,last_name', 'member.profile:id,user_id,first_name,last_name')->orderByDesc('id');
        if (! empty($data['action'])) {
            \App\Support\Like::where($query, 'action', \App\Support\Like::escape($data['action']).'%');
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
            'logs' => $items->take(50)->map(fn (AuditLog $l) => [
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
            ])->values(),
            'has_more' => $items->count() > 50,
            'actions' => collect(self::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }
}
