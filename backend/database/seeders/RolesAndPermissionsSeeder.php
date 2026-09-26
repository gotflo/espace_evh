<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles et permissions de base (installation neuve). En production, les evolutions passent
 * par des migrations : ne pas relancer ce seeder (il reecrit les permissions des roles de base).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // --- Permissions (cle => [nom, groupe]) ---
        $permissions = [
            'members.view_all' => ['Voir et gérer tous les membres (autorité pastorale)', 'Membres'],
            'members.view_scope' => ['Voir les membres de sa portée (tribus, GEM, département)', 'Membres'],
            'members.edit' => ['Modifier les membres', 'Membres'],
            'members.export' => ['Exporter la liste des membres', 'Membres'],
            'tribes.manage' => ['Gérer les tribus', 'Organisation'],
            'tribes.transfer' => ['Valider les changements de tribu', 'Organisation'],
            'departments.manage' => ['Gérer les départements', 'Organisation'],
            'gems.manage' => ['Gérer les GEMs (groupes)', 'Organisation'],
            'roles.assign' => ['Attribuer des rôles', 'Rôles'],
            'roles.manage' => ['Gérer les rôles et permissions', 'Rôles'],
            'spiritual.view' => ['Voir le suivi spirituel', 'Suivi spirituel'],
            'spiritual.record' => ['Enregistrer un suivi spirituel', 'Suivi spirituel'],
            'evaluations.manage' => ['Noter les fidèles (évaluations)', 'Suivi spirituel'],
            'fiss.review' => ['Traiter les demandes de modification de FISS', 'Suivi spirituel'],
            'attendance.view' => ['Voir les présences', 'Présences'],
            'attendance.record' => ['Enregistrer les présences', 'Présences'],
            'exercises.create' => ['Créer des exercices', 'Exercices'],
            'exercises.assign' => ['Assigner des exercices', 'Exercices'],
            'exercises.respond' => ['Répondre aux exercices', 'Exercices'],
            'announcements.publish' => ['Publier des annonces', 'Communication'],
            'events.manage' => ['Créer et gérer les événements', 'Communication'],
            'broadcast.all' => ["Diffuser à toute l'église (annonces, événements, exercices)", 'Communication'],
            'requests.handle' => ['Traiter les demandes des fidèles', 'Communication'],
            'reports.view' => ['Voir les rapports et statistiques de son périmètre', 'Rapports'],
            'audit.view' => ["Consulter le journal d'audit", 'Rapports'],
            'platform.manage' => ['Gérer les paramètres de la plateforme', 'Plateforme'],
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

        // --- Hierarchie de discipulat. La responsabilite d'un departement n'est pas un role :
        // elle se definit sur le departement (Organisation) et donne des droits equivalents.
        $roles = [
            // Pasteur Resident (PR) : tous les droits.
            'super_admin' => [
                'name' => 'Pasteur Résident (PR)',
                'description' => 'Accès total à tous les niveaux.',
                'scope_kind' => 'none', 'rank' => 100, 'is_system' => true,
                'permissions' => $all,
            ],
            // Pasteur Assistant (PA) : vue d'ensemble, recoit les rapports des AP.
            'pasteur_assistant' => [
                'name' => 'Pasteur Assistant (PA)',
                'description' => "Vue d'ensemble de l'église : membres, rapports, validations.",
                'scope_kind' => 'none', 'rank' => 85, 'is_system' => true,
                'permissions' => array_merge($suivi, [
                    'members.view_all', 'members.edit', 'members.export', 'broadcast.all',
                    'tribes.manage', 'departments.manage', 'gems.manage', 'roles.assign', 'roles.manage',
                    'exercises.create', 'reports.view', 'audit.view', 'fiss.review', 'tribes.transfer',
                ]),
            ],
            // Assistant Pasteur (AP) : une ou plusieurs tribus (une attribution par tribu).
            'assistant_pasteur' => [
                'name' => 'Assistant Pasteur (AP)',
                'description' => 'Suit une ou plusieurs tribus (uniquement celles-ci) et en produit les rapports.',
                'scope_kind' => 'tribe', 'rank' => 70, 'is_system' => true,
                'permissions' => array_merge($suivi, ['gems.manage', 'reports.view', 'fiss.review', 'tribes.transfer']),
            ],
            // Patriarche : responsable d'une tribu (et de ses GEMs).
            'patriarche' => [
                'name' => 'Patriarche',
                'description' => "Responsable d'une tribu et de ses GEMs ; valide les demandes de ses membres.",
                'scope_kind' => 'tribe', 'rank' => 60, 'is_system' => true,
                'permissions' => array_merge($suivi, ['gems.manage', 'reports.view', 'fiss.review', 'tribes.transfer']),
            ],
            // Garde : mene un GEM (3 a 5 membres) dans sa tribu.
            'garde' => [
                'name' => 'Garde (responsable de GEM)',
                'description' => 'Mène un GEM (groupe de 3 à 5 membres de sa tribu).',
                'scope_kind' => 'gem', 'rank' => 40, 'is_system' => true,
                'permissions' => ['members.view_scope', 'spiritual.view', 'spiritual.record', 'evaluations.manage', 'attendance.view', 'attendance.record', 'exercises.assign', 'exercises.respond', 'requests.handle'],
            ],
            // Administrateur : un membre qui gere les roles (creer, modifier, attribuer).
            'administrateur' => [
                'name' => 'Administrateur',
                'description' => 'Membre pouvant gérer les rôles : créer, modifier et attribuer aux fidèles.',
                'scope_kind' => 'none', 'rank' => 80, 'is_system' => true,
                'permissions' => ['exercises.respond', 'members.view_all', 'roles.assign', 'roles.manage'],
            ],
            // Accompagnateur : agit sur UN fidele precis (portee = ce fidele).
            'accompagnateur' => [
                'name' => "Accompagnateur d'un fidèle",
                'description' => 'Peut agir sur le fidèle désigné : suivi spirituel, notes, demandes, statut.',
                'scope_kind' => 'member', 'rank' => 35, 'is_system' => true,
                'permissions' => ['members.view_scope', 'members.edit', 'spiritual.view', 'spiritual.record', 'evaluations.manage', 'requests.handle', 'exercises.respond'],
            ],
            // Communication : publie pour toute l'eglise.
            'communication' => [
                'name' => "Communication (toute l'église)",
                'description' => "Publie des annonces et crée des événements qui atteignent toute l'église.",
                'scope_kind' => 'none', 'rank' => 45, 'is_system' => true,
                'permissions' => ['announcements.publish', 'events.manage', 'broadcast.all', 'exercises.respond'],
            ],
            // Membre.
            'fidele' => [
                'name' => 'Membre',
                'description' => "Membre de l'église, accès à son espace personnel.",
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

        // Roles de base devenus obsoletes (absorbes par la hierarchie actuelle).
        Role::whereIn('key', ['gestionnaire', 'assistant_responsable', 'gagneur_ame', 'responsable', 'gad'])
            ->where('is_system', true)->delete();
        Permission::whereIn('key', ['members.view_readonly'])->delete();
    }
}
