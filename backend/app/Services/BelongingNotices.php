<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Gem;
use App\Models\Tribe;
use App\Models\User;
use App\Support\Recipients;

/**
 * Appartenance changee directement par un responsable (fiche du membre, page GEMs) : les personnes concernees sont
 * prevenues comme lorsque le changement vient du membre (changement de tribu valide, inscription a un service acceptee).
 * - nouvelle tribu : le membre, et les responsables de la tribu qui l'accueille ;
 * - nouveau GEM : le membre, et le Garde du GEM ;
 * - nouveaux departements : le membre (« Bienvenue au service »).
 * L'auteur du changement n'est jamais notifie de son propre geste.
 */
class BelongingNotices
{
    /**
     * @param  array{tribe_id: int|null, gem_id: int|null, department_ids: array<int>}  $before
     * @param  array{tribe_id: int|null, gem_id: int|null, department_ids: array<int>}  $after
     */
    public static function after(User $member, array $before, array $after, User $actor): void
    {
        $name = $member->profile?->full_name ?: (string) $member->phone;
        $notMe = fn (array $ids) => array_values(array_diff($ids, [$actor->id, $member->id]));

        if ((int) $after['tribe_id'] !== (int) $before['tribe_id'] && $after['tribe_id']) {
            $tribe = Tribe::find($after['tribe_id']);
            $from = $before['tribe_id'] ? Tribe::find($before['tribe_id'])?->name : null;
            Notifier::send([$member->id], 'tribe_change', "Bienvenue dans la tribu {$tribe?->name} !",
                'Votre tribu a été mise à jour par un responsable.', '/mon-profil');
            Notifier::send($notMe(Recipients::tribeLeadersFor((int) $after['tribe_id'], 'members.view_scope')), 'member',
                "Nouveau membre dans la tribu : {$name}", $from ? "Arrivé(e) de la tribu {$from}." : 'Rattaché(e) à la tribu.',
                '/admin/membres/'.$member->id);
        }

        if ((int) $after['gem_id'] !== (int) $before['gem_id'] && $after['gem_id']) {
            self::joinedGem($member, Gem::find($after['gem_id']), $actor);
        }

        $joined = array_diff($after['department_ids'], $before['department_ids']);
        foreach (Department::whereIn('id', $joined)->get() as $department) {
            Notifier::send([$member->id], 'service', "Bienvenue au service {$department->name}",
                'Vous recevrez désormais les annonces et les événements de ce département.', '/servir');
        }
    }

    /** Membre place dans un GEM : lui, et le Garde de ce GEM. */
    public static function joinedGem(User $member, ?Gem $gem, User $actor): void
    {
        if (! $gem) {
            return;
        }
        $name = $member->profile?->full_name ?: (string) $member->phone;
        Notifier::send([$member->id], 'member', "Vous faites partie du GEM {$gem->name}",
            $gem->leader?->profile?->full_name ? "Votre Garde : {$gem->leader->profile->full_name}." : null, '/mon-profil');
        if ($gem->leader_user_id && ! in_array((int) $gem->leader_user_id, [$actor->id, $member->id], true)) {
            Notifier::send([(int) $gem->leader_user_id], 'member', "Nouveau membre dans votre GEM : {$name}",
                "{$name} rejoint le GEM {$gem->name}.", '/admin/membres/'.$member->id);
        }
    }
}
