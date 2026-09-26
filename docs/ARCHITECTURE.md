# Architecture — Espace Vases d'Honneur Chicoutimi

Document de référence (v2). Complète le [README](../README.md) avec les choix de conception.

## 1. Principes

- **Un seul domaine** : Laravel sert l'API (`/api`) et l'application React (SPA, PWA installable).
- **Le serveur fait foi** : chaque droit est vérifié côté API (permission + périmètre). L'interface ne fait que
  refléter ces droits ; masquer un bouton n'est jamais une protection.
- **Hébergement mutualisé** (Hostinger) : pas de websockets ni de worker permanent. Rafraîchissement intelligent
  côté client, automatismes idempotents lancés par cron (`schedule:run`) ou, à défaut, par l'application.
- **Traçabilité** : toute action sensible écrit une entrée non modifiable dans `audit_logs`.

## 2. Modèle de données

### Identité
- `users` : téléphone (unique), `activity_status` (`active`/`inactive`), `activity_changed_at`,
  `activity_override` (forçage manuel), `last_seen_at`, `last_login_at`.
- `profiles` (1–1) : identité, `tribe_id`, `gem_id`, date d'anniversaire (jour/mois), situation matrimoniale,
  `spouse_name` (conjoint non inscrit), `wedding_day`/`wedding_month`, `has_children`, `completion` (0–100, stocké pour filtrer vite).
- `spiritual_profiles` : conversion, baptêmes, dons, etc.
- `family_links` : `user_id` → `relative_user_id` ou `relative_name` (non inscrit), `relation` (`spouse`/`child`), `status`
  (`pending`/`confirmed`/`declined`), `birth_year` pour les enfants. Un lien conjugal n'existe que confirmé par les deux.

### Organisation
- `tribes` (12), `gems` (groupes rattachés à une tribu), `departments` (+ `tracks_rehearsal`).
- `department_profile` : appartenance aux départements ; `department_leaders` : responsables (5 max par département).

### Rôles
- `roles` (`key`, `rank`, `scope_kind` : `none`/`tribe`/`gem`/`department`/`member`), `permissions`, `permission_role`.
- `role_user` avec `scope_kind`/`scope_id` : un même rôle peut être attribué plusieurs fois avec des portées
  différentes (ex. Assistant Pasteur sur plusieurs tribus).
- Anti-escalade : on ne peut attribuer qu'un rôle de rang inférieur au sien.

### Suivi
- `spiritual_health_forms` (FISS mensuelle) : composantes notées, `submitted_at`, `locked_at`, `edit_count`.
- `fiss_edit_requests` : motif, statut, décideur, `decision_comment`, `unlock_expires_at` (fenêtre de 7 jours), `used_at` ; 2 par fiche au maximum.
- `evaluations` (Vertumètre), `attendances` (cultes et répétitions), `spiritual_entries`, `milestones`.

### Demandes
- `tribe_change_requests` + `tribe_change_approvals` (côté tribu d'origine / d'arrivée).
- `member_requests` (demandes adressées aux responsables).

### Publication
- `announcements`, `events` (récurrence, `is_personal`), `exercises`.
- `publication_scopes` (polymorphe) : une publication peut viser l'église entière ou plusieurs tribus, GEM,
  départements. Remplace les anciennes colonnes `target_type`/`target_id` (données migrées).

### Notifications et audit
- `user_notifications` (type, titre, corps, lien, `priority`, `read_at`), `push_subscriptions`.
- `notification_dispatches` : clé unique par envoi automatique (idempotence).
- `audit_logs` : `user_id` (auteur), `member_user_id`, `action`, `subject_type`/`subject_id`,
  `old_values`/`new_values`/`context` (JSON), `ip`, `created_at`. Aucune route d'écriture.

## 3. Périmètres

| Support | Rôle |
|---------|------|
| `MemberScope` | restreint toute requête de membres au périmètre de l'utilisateur (tribus, GEM, départements dirigés, fidèles accompagnés) |
| `Audience` | options de publication autorisées et résolution des destinataires d'une publication |
| `Recipients` | responsables d'un membre (patriarche → Assistant Pasteur → autorité pastorale) pour les notifications |
| `ReportService::resolveScope` | portée d'un rapport (`church`, `mine`, `tribe:ID`) refusée si hors périmètre |

`members.view_all` représente l'autorité pastorale (vision de toute l'église). L'Assistant Pasteur n'a **pas**
cette permission : il ne voit que ses tribus assignées.

## 4. Services

| Service | Responsabilité |
|---------|----------------|
| `Notifier` | notification in-app + push, priorité, anti-doublon, libération de la clé en cas d'échec |
| `FissService` | verrouillage, demandes de modification, décisions, expiration, audit |
| `TribeChangeService` | demandes, approbations des deux côtés, application du changement |
| `FamilyService` | désignation/confirmation du conjoint, enfants, suggestions sans lien automatique |
| `ActivityService` | calcul actif/inactif (3 mois), réactivation, trace |
| `ReportService` | indicateurs, séries mensuelles, comparaison des tribus, listes (cache 10 min) |
| `CalendarService` | occurrences d'événements, anniversaires, mariages, fériés, droits d'édition |

## 5. Frontend

- `api/client.ts` : jeton, erreurs lisibles, **toasts automatiques** sur les actions (POST/PUT/PATCH/DELETE).
- Écrans chargés à la demande (`lazy`) ; pdfmake chargé uniquement lors d'un export.
- Graphiques SVG maison (`components/charts.tsx`) : palette validée pour le daltonisme, tableau de données associé,
  mêmes fonctions pour l'écran et le PDF.
- Service worker : cache des fichiers `/assets/` (immuables), réception des push.
