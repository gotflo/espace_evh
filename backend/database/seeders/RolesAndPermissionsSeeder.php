<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // --- Permissions (cle => [nom, groupe]) ---
        $permissions = [
            'members.view_all' => ['Voir tous les membres', 'Membres'],
            'members.view_scope' => ['Voir les membres de sa tribu/departement', 'Membres'],
            'members.edit' => ['Modifier les membres', 'Membres'],
            'members.export' => ['Exporter la liste des membres', 'Membres'],
            'tribes.manage' => ['Gerer les tribus', 'Organisation'],
            'departments.manage' => ['Gerer les departements', 'Organisation'],
            'gems.manage' => ['Gerer les GEMs (groupes)', 'Organisation'],
            'roles.assign' => ['Attribuer des roles', 'Roles'],
            'roles.manage' => ['Gerer les roles et permissions', 'Roles'],
            'spiritual.view' => ['Voir le suivi spirituel', 'Suivi spirituel'],
            'spiritual.record' => ['Enregistrer un suivi spirituel', 'Suivi spirituel'],
            'evaluations.manage' => ['Noter les fideles (evaluations)', 'Suivi spirituel'],
            'attendance.view' => ['Voir les presences', 'Presences'],
            'attendance.record' => ['Enregistrer les presences', 'Presences'],
            'exercises.create' => ['Creer des exercices', 'Exercices'],
            'exercises.assign' => ['Assigner des exercices', 'Exercices'],
            'exercises.respond' => ['Repondre aux exercices', 'Exercices'],
            'announcements.publish' => ['Publier des annonces', 'Communication'],
            'events.manage' => ['Creer et gerer les evenements', 'Communication'],
            'requests.handle' => ['Traiter les demandes des fideles', 'Communication'],
            'platform.manage' => ['Gerer les parametres de la plateforme', 'Plateforme'],
        ];

        foreach ($permissions as $key => [$name, $group]) {
            Permission::updateOrCreate(['key' => $key], ['name' => $name, 'group' => $group]);
        }

        $all = array_keys($permissions);

        // Ensemble des permissions de suivi (reutilise par plusieurs niveaux).
        $suivi = [
            'members.view_scope', 'spiritual.view', 'spiritual.record', 'evaluations.manage',
            'attendance.view', 'attendance.record', 'exercises.assign', 'exercises.respond',
            'announcements.publish', 'events.manage', 'requests.handle',
        ];

        // --- Hierarchie de discipulat (cle => config). Voir [[pdvie-inspiration]]. ---
        $roles = [
            // Pasteur Resident (PR) : tous les droits.
            'super_admin' => [
                'name' => 'Pasteur Resident (PR)',
                'description' => 'Acces total a tous les niveaux.',
                'scope_kind' => 'none', 'rank' => 100, 'is_system' => true,
                'permissions' => $all,
            ],
            // Pasteur Assistant (PA) : vue d'ensemble, recoit les rapports des AP.
            'pasteur_assistant' => [
                'name' => 'Pasteur Assistant (PA)',
                'description' => 'Vue d\'ensemble des niveaux inferieurs, actions et objectifs.',
                'scope_kind' => 'none', 'rank' => 85, 'is_system' => true,
                'permissions' => array_merge($suivi, [
                    'members.view_all', 'members.edit', 'members.export',
                    'tribes.manage', 'departments.manage', 'gems.manage', 'roles.assign', 'roles.manage',
                    'exercises.create',
                ]),
            ],
            // Assistant Pasteur (AP) : couvre quelques tribus, produit un rapport pour PA/PR.
            'assistant_pasteur' => [
                'name' => 'Assistant Pasteur (AP)',
                'description' => 'Suit plusieurs tribus, produit un rapport pour le PA/PR.',
                'scope_kind' => 'tribe', 'rank' => 70, 'is_system' => true,
                'permissions' => array_merge($suivi, ['gems.manage']),
            ],
            // Patriarche : responsable d'une tribu (et de ses GEMs).
            'patriarche' => [
                'name' => 'Patriarche',
                'description' => 'Responsable d\'une tribu et de ses GEMs.',
                'scope_kind' => 'tribe', 'rank' => 60, 'is_system' => true,
                'permissions' => array_merge($suivi, ['gems.manage']),
            ],
            // Responsable de departement (axe ministere, ex chorale) : conserve.
            'responsable' => [
                'name' => 'Responsable de departement',
                'description' => 'Responsable d\'un departement / ministere.',
                'scope_kind' => 'department', 'rank' => 50, 'is_system' => true,
                'permissions' => ['members.view_scope', 'spiritual.view', 'attendance.view', 'attendance.record', 'exercises.respond', 'events.manage'],
            ],
            // Responsable GEM (GAD) : mene un GEM (3 a 5 membres) dans sa tribu.
            'gad' => [
                'name' => 'Responsable GEM (GAD)',
                'description' => 'Mene un GEM (groupe de 3 a 5 membres de sa tribu).',
                'scope_kind' => 'gem', 'rank' => 40, 'is_system' => true,
                'permissions' => ['members.view_scope', 'spiritual.view', 'spiritual.record', 'evaluations.manage', 'attendance.view', 'attendance.record', 'exercises.assign', 'exercises.respond', 'requests.handle'],
            ],
            // Administrateur : un membre (fidele) qui peut UNIQUEMENT attribuer des roles.
            'administrateur' => [
                'name' => 'Administrateur',
                'description' => 'Membre pouvant attribuer des roles aux fideles (rien d\'autre).',
                'scope_kind' => 'none', 'rank' => 80, 'is_system' => true,
                'permissions' => ['exercises.respond', 'members.view_all', 'roles.assign'],
            ],
            // Membre.
            'fidele' => [
                'name' => 'Membre',
                'description' => 'Membre de l\'eglise, acces a son espace personnel.',
                'scope_kind' => 'none', 'rank' => 10, 'is_system' => true,
                'permissions' => ['exercises.respond'],
            ],
        ];

        foreach ($roles as $key => $cfg) {
            $role = Role::updateOrCreate(['key' => $key], [
                'name' => $cfg['name'],
                'description' => $cfg['description'],
                'scope_kind' => $cfg['scope_kind'],
                'rank' => $cfg['rank'],
                'is_system' => $cfg['is_system'],
            ]);
            $ids = Permission::whereIn('key', $cfg['permissions'])->pluck('id');
            $role->permissions()->sync($ids);
        }

        // Roles de base devenus obsoletes (absorbes par la nouvelle hierarchie).
        Role::whereIn('key', ['gestionnaire', 'assistant_responsable', 'gagneur_ame'])
            ->where('is_system', true)->delete();
    }
}
