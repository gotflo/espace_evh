# Plateforme « Mon compte » - Vases d'Honneur Chicoutimi

Document d'architecture (v1). Sert de référence avant développement.
À valider par le pasteur / porteur du projet.

## 1. Objectif

Un espace membre où chaque fidèle a un compte, se connecte par téléphone (code
OTP), complète son profil, et voit un tableau de bord adapté à son rôle. Les
responsables suivent la vie spirituelle des fidèles de leur tribu / département.
La plateforme est modulable : rôles, tribus, départements et exercices se
configurent depuis l'interface, sans toucher au code.

## 2. Socle technique

- Frontend : React (application déployée en statique sur Hostinger, sous-domaine).
- Backend : Laravel (PHP 8) + MySQL, sur l'hébergement mutualisé Hostinger.
- Auth : OTP par SMS au démarrage (fournisseur type Twilio). Bascule WhatsApp possible plus tard.
- Stockage photos : disque Hostinger via Laravel.
- Temps réel : rafraîchissement intelligent (websockets non dispo en mutualisé).

## 3. Modèle de données (entités principales)

### Identité et profil
- **users** : identité de connexion. `id`, `phone` (unique), `phone_verified_at`, `is_active`, timestamps.
- **profiles** : 1 pour 1 avec user. `user_id`, `first_name`, `last_name`,
  `birth_date`, `photo_path`, `gender`, `tribe_id` (nullable), `department_id`
  (nullable), `joined_at`, `notes`.

### Organisation de l'église (configurable par l'admin)
- **tribes** (tribus) : `id`, `name`, `patriarch_user_id` (le patriarche), `description`.
- **departments** (départements) : `id`, `name`, `leader_user_id` (le responsable), `description`.

### Rôles et permissions (le coeur de la modularité)
- **roles** : `id`, `key`, `name`, `description`, `is_system`. Ex : super_admin,
  gestionnaire, pasteur, assistant_pasteur, responsable, assistant_responsable,
  gagneur_ame, patriarche, fidele.
- **permissions** : `id`, `key`, `name`. Actions fines. Ex : `users.manage`,
  `roles.assign`, `members.view_all`, `members.view_own_scope`,
  `spiritual.record`, `exercises.create`, `exercises.assign`.
- **role_permission** : quelles permissions dans quel rôle (pivot).
- **role_user** : quels rôles pour quel fidèle, avec portée optionnelle
  (`scope_tribe_id` / `scope_department_id`) pour les rôles limités à une tribu
  ou un département. Ex : un patriarche a le rôle « patriarche » limité à SA tribu.

### Suivi spirituel
- **spiritual_entries** : journal du parcours. `id`, `member_user_id`,
  `author_user_id` (qui a écrit), `type` (conversion, bapteme, priere, jeune,
  visite, etc.), `date`, `note`.
- **milestones** : étapes franchies. `member_user_id`, `milestone_key`
  (baptise, rempli_esprit, etc.), `reached_at`.

### Activité (actif / inactif)
- **attendances** : présence. `member_user_id`, `date`, `event`, `present`.
- Statut actif = présence ou activité dans les N dernières semaines (calculé, paramétrable).

### Exercices spirituels
- **exercises** : `id`, `title`, `content`, `type` (verset, quiz, reflexion),
  `created_by`, `is_random`, `scheduled_at`, `scope` (global / tribu / département).
- **exercise_assignments** : `exercise_id`, `assigned_to_user_id` ou groupe, `due_date`.
- **exercise_responses** : `exercise_id`, `user_id`, `response`, `completed_at`.

## 4. Système de rôles (résumé)

Deux natures de rôles, gérées par le même mécanisme :

1. Rôles de plateforme (droits d'accès) :
   - **super_admin** (le pasteur) : tous les droits, assigne/retire n'importe quel rôle.
   - **gestionnaire** : mêmes droits que le pasteur ou légèrement réduits (configurable).

2. Rôles de fonction dans l'église (avec portée) :
   - **patriarche** : voit et suit les fidèles de SA tribu.
   - **responsable / assistant_responsable** : voient et suivent leur département.
   - **assistant_pasteur, gagneur_ame** : droits intermédiaires à définir.
   - **fidele** : voit et gère son propre espace.

Principe : un fidèle peut cumuler plusieurs rôles. Chaque rôle porte des
permissions. Les permissions « voir les membres » existent en deux versions :
toutes (`members.view_all`, pour pasteur/gestionnaire) ou limitées à sa portée
(`members.view_own_scope`, pour patriarche/responsable). Le tableau de bord se
compose selon les rôles et permissions de la personne connectée.

## 5. Feuille de route par phases

- Phase 0 : fondations. Projet Laravel + React, base MySQL, migrations, déploiement Hostinger, page de connexion vide.
- Phase 1 : Auth OTP SMS + page « compléter mon profil » (nom, prénoms, date de naissance, photo, tribu, département).
- Phase 2 : Rôles et permissions configurables, attribution des rôles par l'admin, tableaux de bord par rôle.
- Phase 3 : Suivi spirituel (journal, milestones) + statut actif/inactif + listes exhaustives filtrables.
- Phase 4 : Exercices spirituels (création, aléatoire, programmés, réponses).
- Phase 5+ : améliorations, statistiques, notifications, etc.

## 6. Ce que le porteur du projet doit fournir

- Compte fournisseur SMS (ex : Twilio) pour l'OTP.
- Accès MySQL Hostinger (identifiants base de données).
- Sous-domaine dédié (ex : espace.vasesdhonneurchicoutimi.org), gratuit, à créer dans hPanel au moment du déploiement.
- Validation de ce document (surtout la liste des rôles et leurs droits).
