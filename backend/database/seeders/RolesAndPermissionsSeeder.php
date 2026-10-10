<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Roles et permissions de base (installation neuve). En production, les evolutions passent
 * par des migrations : ne pas relancer ce seeder (il reecrit les permissions des roles de base).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // --- Permissions : catalogue par module (App\Support\PermissionCatalog) ---
        foreach (PermissionCatalog::all() as $key => $def) {
            Permission::updateOrCreate(['key' => $key], ['name' => $def[0], 'group' => $def['module_label']]);
        }

        $all = PermissionCatalog::keys();

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
                    'exercises.create', 'reports.view', 'audit.view', 'fiss.review', 'tribes.transfer', 'content.manage',
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
            // Protocole : organise les rendez-vous avec le pasteur apres les cultes.
            'protocole' => [
                'name' => 'Équipe du protocole',
                'description' => 'Consulte les demandes de rendez-vous avec le pasteur et organise les rencontres après les cultes.',
                'scope_kind' => 'none', 'rank' => 30, 'is_system' => true,
                'permissions' => ['appointments.organize', 'exercises.respond'],
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
            $ids = Permission::whereIn('key', self::expand($cfg['permissions']))->pluck('id');
            $role->permissions()->sync($ids);
        }

        // Roles de base devenus obsoletes (absorbes par la hierarchie actuelle).
        Role::whereIn('key', ['gestionnaire', 'assistant_responsable', 'gagneur_ame', 'responsable', 'gad'])
            ->where('is_system', true)->delete();
        Permission::whereIn('key', ['members.view_readonly', 'content.manage'])->delete();
    }

    /**
     * Permissions detaillees issues du decoupage (memes regles que la migration du 2026-10-12) :
     * content.manage devient versets + horaires.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private static function expand(array $keys): array
    {
        foreach (PermissionCatalog::SPLITS as $parent => $children) {
            if (in_array($parent, $keys, true)) {
                $keys = array_merge($keys, $children);
            }
        }

        return array_values(array_unique(array_diff($keys, ['content.manage'])));
    }
}
