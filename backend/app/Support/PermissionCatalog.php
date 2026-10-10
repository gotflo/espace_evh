<?php

namespace App\Support;

/**
 * Catalogue unique des permissions, rangees par module (onglet) : source de verite pour le seeder,
 * la migration de mise a jour, l'ecran « Rôles » et le compte de gestion principal.
 *
 * Chaque permission : [libelle, nature, confidentielle].
 * - nature : tab (ouvrir l'onglet), view (consulter), view_own, view_others, create, edit, delete,
 *   publish, send, validate, export, settings, manage ;
 * - confidentielle : donne acces aux donnees personnelles des fideles (fiches, FISS, Vertumetre,
 *   resolutions, reponses, rapports, presences, demandes). Le compte de gestion principal ne recoit
 *   JAMAIS une permission confidentielle, meme s'il gere les roles qui les contiennent.
 */
class PermissionCatalog
{
    public const MODULES = [
        'gestion' => ['Tableau de bord de gestion', [
            'platform.manage' => ['Ouvrir le tableau de bord de gestion (chiffres globaux, sans donnée personnelle)', 'tab', false],
            'settings.manage' => ['Modifier les réglages de la plateforme (rappels automatiques, horaires, limites)', 'settings', false],
        ]],
        'comptes' => ['Comptes, rôles et autorisations', [
            'accounts.view' => ['Consulter la liste des comptes (nom, rôles, téléphone masqué)', 'view', false],
            'roles.assign' => ['Attribuer et retirer des rôles aux membres', 'manage', false],
            'roles.manage' => ['Créer, modifier et supprimer des rôles et leurs permissions', 'settings', false],
        ]],
        'membres' => ['Membres et fiches des fidèles', [
            'members.view_scope' => ['Consulter les fiches des membres de sa portée (tribu, GEM, département, fidèle confié)', 'view_others', true],
            'members.view_all' => ['Consulter les fiches de tous les membres (autorité pastorale)', 'view_others', true],
            'members.edit' => ['Modifier le statut et l’appartenance d’un membre', 'edit', true],
            'members.approve' => ['Valider ou rejeter les nouvelles inscriptions', 'validate', true],
            'members.export' => ['Exporter les listes détaillées (membres, responsables) en PDF', 'export', true],
        ]],
        'organisation' => ['Organisation (tribus, départements, GEMs)', [
            'tribes.manage' => ['Créer, modifier et supprimer les tribus', 'manage', false],
            'departments.manage' => ['Créer, modifier les départements et nommer leurs responsables', 'manage', false],
            'departments.approve' => ['Valider les inscriptions aux services de ses départements', 'validate', false],
            'gems.manage' => ['Composer et gérer les GEMs', 'manage', false],
            'tribes.transfer' => ['Valider ou refuser les changements de tribu', 'validate', true],
        ]],
        'suivi' => ['Suivi spirituel, FISS et Vertumètre', [
            'spiritual.view' => ['Consulter le suivi spirituel, les FISS, le Vertumètre, les RM et devoirs des membres', 'view_others', true],
            'spiritual.record' => ['Écrire dans le suivi spirituel d’un membre', 'create', true],
            'evaluations.manage' => ['Consulter et attribuer les notes des fidèles', 'manage', true],
            'fiss.review' => ['Valider ou refuser les demandes de modification (FISS, Vertumètre)', 'validate', true],
        ]],
        'presences' => ['Présences', [
            'attendance.view' => ['Consulter les présences', 'view_others', true],
            'attendance.record' => ['Faire l’appel (enregistrer les présences)', 'create', true],
        ]],
        'exercices' => ['Exercices', [
            'exercises.respond' => ['Faire ses propres exercices (espace membre)', 'view_own', false],
            'exercises.assign' => ['Ouvrir l’onglet Exercices et suivre l’avancement global', 'tab', false],
            'exercises.create' => ['Créer et assigner des exercices', 'create', false],
            'exercises.delete' => ['Supprimer des exercices', 'delete', false],
            'exercises.view_responses' => ['Lire les réponses et le suivi de chaque membre, relancer', 'view_others', true],
        ]],
        'annonces' => ['Annonces', [
            'announcements.publish' => ['Consulter et publier des annonces', 'publish', false],
            'announcements.delete' => ['Supprimer des annonces', 'delete', false],
            'broadcast.all' => ['Diffuser à toute l’église (annonces, événements, exercices)', 'send', false],
        ]],
        'evenements' => ['Événements', [
            'events.manage' => ['Consulter, créer et modifier des événements', 'edit', false],
            'events.delete' => ['Supprimer des événements', 'delete', false],
            'events.participants' => ['Voir la liste des participants d’un événement', 'view_others', false],
        ]],
        'versets' => ['Textes bibliques (versets du tableau de bord)', [
            'verses.view' => ['Consulter la bibliothèque des textes et leur historique', 'tab', false],
            'verses.edit' => ['Ajouter, modifier et ordonner des textes (brouillons)', 'edit', false],
            'verses.publish' => ['Publier, dépublier et mettre en avant un texte', 'publish', false],
            'verses.delete' => ['Supprimer des textes', 'delete', false],
        ]],
        'horaires' => ['Horaires des cultes', [
            'schedule.view' => ['Consulter l’onglet des horaires', 'tab', false],
            'schedule.edit' => ['Ajouter et modifier des horaires', 'edit', false],
            'schedule.delete' => ['Retirer des horaires', 'delete', false],
        ]],
        'ressources' => ['Ressources et liens vidéo', [
            'resources.view' => ['Consulter l’onglet Ressources (y compris les brouillons)', 'tab', false],
            'resources.edit' => ['Ajouter et modifier des ressources (liens, vidéos YouTube)', 'edit', false],
            'resources.publish' => ['Publier ou dépublier une ressource', 'publish', false],
            'resources.delete' => ['Supprimer des ressources', 'delete', false],
        ]],
        'devoirs' => ['Devoirs (DS)', [
            'assignments.publish' => ['Publier et modifier les devoirs (vidéo ou audio)', 'publish', false],
            'assignments.delete' => ['Retirer des devoirs', 'delete', false],
        ]],
        'demandes' => ['Demandes des fidèles', [
            'requests.handle' => ['Lire et traiter les demandes des fidèles', 'view_others', true],
        ]],
        'rapports' => ['Rapports et statistiques', [
            'reports.view' => ['Consulter les rapports de son périmètre (tribus, église)', 'view_others', true],
            'reports.export' => ['Télécharger les rapports en PDF', 'export', true],
        ]],
        'rendezvous' => ['Rendez-vous avec le pasteur', [
            'appointments.host' => ['Recevoir des rendez-vous (pasteur)', 'manage', true],
            'appointments.organize' => ['Organiser les rendez-vous (protocole)', 'manage', true],
        ]],
        'journal' => ['Journal d’activité', [
            'audit.view' => ['Consulter le journal d’activité', 'tab', false],
            'audit.view_details' => ['Voir le détail des valeurs modifiées et les adresses IP', 'view_others', true],
        ]],
    ];

    /**
     * Compatibilite : une permission « parente » deja detenue par un role donne ses nouvelles
     * permissions detaillees (aucun role ne perd ce qu'il faisait avant le decoupage).
     */
    public const SPLITS = [
        'roles.assign' => ['accounts.view'],
        'departments.manage' => ['departments.approve'],
        'exercises.assign' => ['exercises.create', 'exercises.delete', 'exercises.view_responses'],
        'announcements.publish' => ['announcements.delete'],
        'events.manage' => ['events.delete', 'events.participants'],
        'content.manage' => ['verses.view', 'verses.edit', 'verses.publish', 'verses.delete', 'schedule.view', 'schedule.edit', 'schedule.delete'],
        'reports.view' => ['reports.export', 'members.export'],
        'audit.view' => ['audit.view_details'],
    ];

    /** Libelles des natures (affiches comme etiquettes dans l'ecran des roles). */
    public const KINDS = [
        'tab' => 'Onglet', 'view' => 'Consulter', 'view_own' => 'Ses données', 'view_others' => 'Données des autres',
        'create' => 'Créer', 'edit' => 'Modifier', 'delete' => 'Supprimer', 'publish' => 'Publier', 'send' => 'Diffuser',
        'validate' => 'Valider', 'export' => 'Exporter', 'settings' => 'Paramètres', 'manage' => 'Gérer',
    ];

    /** @return array<string, array{0: string, 1: string, 2: bool, module: string, module_label: string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::MODULES as $module => [$label, $permissions]) {
            foreach ($permissions as $key => $def) {
                $out[$key] = $def + ['module' => $module, 'module_label' => $label];
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function isConfidential(string $key): bool
    {
        // Permission inconnue du catalogue : traitee comme confidentielle (refus par defaut).
        return self::all()[$key][2] ?? true;
    }

    /** Permissions de gestion (non confidentielles) hors espace membre : celles du compte de gestion principal. */
    public static function managementKeys(): array
    {
        return array_values(array_filter(self::keys(), fn (string $k) => ! self::isConfidential($k) && $k !== 'exercises.respond'));
    }
}
