<div align="center">
  <img src="frontend/public/logo-vh.png" alt="Vases d'Honneur Chicoutimi" width="110" />

  # Espace Vases d'Honneur Chicoutimi

  Plateforme de suivi des membres de l'église Vases d'Honneur Chicoutimi.
  Chaque fidèle a son espace personnel ; les responsables suivent la vie de l'église
  selon leur périmètre (GEM, tribu(s), département, église entière).
</div>

---

## Sommaire

1. [Fonctionnalités](#fonctionnalités)
2. [Pile technique et architecture](#pile-technique-et-architecture)
3. [Installation locale](#installation-locale)
4. [Variables d'environnement](#variables-denvironnement)
5. [Base de données](#base-de-données)
6. [Rôles, permissions et périmètres](#rôles-permissions-et-périmètres)
7. [Règles métier](#règles-métier)
8. [Notifications](#notifications)
9. [Calendrier et rappels](#calendrier-et-rappels)
10. [Rapports et exports PDF](#rapports-et-exports-pdf)
11. [Automatismes (tâches planifiées)](#automatismes-tâches-planifiées)
12. [Journal d'audit](#journal-daudit)
13. [Sécurité et confidentialité](#sécurité-et-confidentialité)
14. [Tests et vérifications](#tests-et-vérifications)
15. [Déploiement](#déploiement)
16. [Documentation complémentaire](#documentation-complémentaire)

## Fonctionnalités

**Pour chaque fidèle**
- Connexion par téléphone avec code à usage unique (SMS), sans mot de passe.
- Profil en onglets (Identité, Famille, Infos personnelles, Vie spirituelle) avec **indicateur de complétion** et rappels.
- **Famille** : conjoint(e) choisi(e) parmi les membres (confirmation par la personne désignée) ou simplement nommé(e)
  s'il/elle n'est pas inscrit(e) ; date de mariage ; enfants (nom, année de naissance, lien facultatif vers leur profil).
- **Ma vie spirituelle** : cartes interactives (FISS, Vertumètre, Assiduité, Ponctualité, Parcours) qui ouvrent le détail.
- **Fiche de santé spirituelle (FISS)** mensuelle, **verrouillée après envoi** ; modification sur demande validée (2 demandes par fiche au maximum).
- **Calendrier** (mois, semaine, liste) : événements, jours fériés, anniversaires, anniversaires de mariage, échéances.
- **Service** : inscription à un département en un clic.
- **Changement de tribu** par demande, validée par les responsables des deux tribus.
- Notifications dans l'application et **push** (téléphone, ordinateur), application installable (PWA).

**Pour les responsables**
- Tableau de bord : membres actifs/inactifs, FISS du mois, profils incomplets, nouveaux inscrits à accueillir.
- Membres (filtres : actifs, inactifs, profil incomplet, FISS manquante, tribu), fiche détaillée avec famille,
  complétion, historique complet des FISS.
- **Validations** : demandes de modification de FISS et de changement de tribu (refus motivé obligatoire).
- **Rapports** : indicateurs clés, courbes (vie spirituelle, FISS, assiduité, Vertumètre), nouveaux membres,
  comparaison des tribus, listes (FISS manquantes, inactifs, nouveaux), onglet Membres filtrable, **exports PDF**.
- Présences, notes (Vertumètre), exercices, annonces et événements ciblés (une ou plusieurs tribus, GEM, départements).
- **Journal d'audit** en lecture seule.

## Pile technique et architecture

| Partie | Technologies |
|--------|--------------|
| API | Laravel 13, PHP 8.3, Sanctum (jetons), Eloquent |
| Base de données | MySQL en production, SQLite en développement |
| Application | React 19, TypeScript, Vite, React Router, PWA (service worker) |
| PDF | pdfmake (généré sur l'appareil, chargé seulement à l'export) |
| Push | Web Push (VAPID) implémenté avec l'extension OpenSSL de PHP, sans dépendance |
| Hébergement | Hostinger : Laravel sert l'API **et** l'application React |

```
Navigateur ──https──> Laravel (public/)
                        ├─ /api/*      API (Sanctum, permissions, périmètres)
                        ├─ /storage/*  images (photos, annonces, événements)
                        ├─ /assets/*   build React (JS, CSS, noms horodatés)
                        └─ le reste    index.html (le routeur React prend le relais)
```

```
evh_platform/
├── backend/
│   ├── app/
│   │   ├── Console/Commands/     app:tick (automatismes), fiss:remind, user:make-admin
│   │   ├── Http/Controllers/Api/ Auth, Profile, My* (espace du fidèle), Admin/* (gestion)
│   │   ├── Http/Middleware/      EnsurePermission, TrackActivity, TriggerAutomation, SecurityHeaders
│   │   ├── Models/               User, Profile, Role, Tribe, Gem, Department, Event, Announcement,
│   │   │                         SpiritualHealthForm, FissEditRequest, TribeChangeRequest, FamilyLink,
│   │   │                         PublicationScope, AuditLog, UserNotification…
│   │   ├── Services/             Notifier, FissService, TribeChangeService, FamilyService,
│   │   │                         ReportService, ActivityService, CalendarService, Push/
│   │   └── Support/              MemberScope, Audience, Recipients, Audit, ProfileCompletion,
│   │                             Blessings (versets), Holidays, GemRules
│   ├── database/migrations/      schéma et migrations de données (réversibles)
│   ├── database/seeders/         rôles/permissions, tribus, départements
│   └── tests/Feature/            tests de bout en bout de l'API
├── frontend/
│   ├── src/api, auth/            client HTTP (toasts automatiques), session
│   ├── src/components/           composants (charts, MultiSelect, AudiencePicker, profile/…)
│   ├── src/pages/ (+ admin/)     écrans
│   ├── src/reports/pdf.ts        mise en page des PDF
│   └── public/                   manifest, service worker, icônes, photos de connexion
└── docs/                         architecture, IA, procédures de mise à jour
```

## Installation locale

Prérequis : PHP 8.3 (extensions openssl, pdo_sqlite ou pdo_mysql, mbstring), Composer, Node 20.

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed            # rôles et permissions, 12 tribus, départements officiels
php artisan storage:link
php artisan user:make-admin "+14185550123"   # premier Pasteur Résident
php artisan serve              # http://localhost:8000
```

```bash
cd frontend
npm install
npm run dev                    # http://localhost:5173 (proxy /api et /storage vers :8000)
```

En local, `SMS_DRIVER=log` écrit le code de connexion dans `storage/logs/laravel.log`
(et `EXPOSE_OTP=true` l'affiche à l'écran de connexion).

## Variables d'environnement

Modèle complet : `backend/.env.production.example`. Les principales :

| Variable | Rôle |
|----------|------|
| `APP_URL`, `FRONTEND_URL` | URL publique (même domaine pour l'API et l'application) |
| `APP_TIMEZONE` | `America/Toronto` : heures des événements, rappels, anniversaires |
| `DB_*` | connexion MySQL |
| `SANCTUM_EXPIRATION` | durée de validité des sessions (minutes) |
| `SMS_DRIVER`, `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` | envoi des codes de connexion |
| `EXPOSE_OTP` | phase de test uniquement : affiche le code à l'écran (`false` en production réelle) |
| `WEBPUSH_ENABLED` | active les notifications push (défaut `true`) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | clés push (facultatives : générées automatiquement dans `storage/app/webpush-vapid.json`) |
| `AUTO_TICK` | déclenche les automatismes depuis l'application si le cron manque (défaut `true`) |

Aucun secret n'est versionné : `.env`, bases SQLite et clés VAPID sont exclus par `.gitignore`.

## Base de données

Tables principales :

| Domaine | Tables |
|---------|--------|
| Membres | `users` (téléphone, `activity_status`, `last_seen_at`), `profiles` (identité, tribu, GEM, famille, `completion`), `spiritual_profiles` |
| Organisation | `tribes`, `gems`, `departments`, `department_leaders`, `department_profile` |
| Rôles | `roles`, `permissions`, `permission_role`, `role_user` (avec `scope_kind` / `scope_id`) |
| Suivi | `spiritual_health_forms` (FISS, `locked_at`), `fiss_edit_requests`, `evaluations`, `attendances`, `spiritual_entries` |
| Demandes | `tribe_change_requests`, `tribe_change_approvals`, `member_requests` |
| Famille | `family_links` (conjoint / enfant, statut `pending` / `confirmed` / `declined`) |
| Publication | `announcements`, `events`, `exercises`, `publication_scopes` (portée multiple : église, tribus, GEM, départements) |
| Notifications | `user_notifications` (priorité, lien), `push_subscriptions`, `notification_dispatches` (anti-doublon) |
| Traçabilité | `audit_logs` (qui, quoi, avant/après, contexte, IP) |

Toutes les migrations de cette version ont une méthode `down()` testée (retour arrière possible).

## Rôles, permissions et périmètres

| Rôle | Périmètre | Essentiel |
|------|-----------|-----------|
| Pasteur Résident (PR) | église | toutes les permissions |
| Pasteur Assistant (PA) | église | suivi complet, rapports, validations, diffusion à toute l'église |
| Administrateur | église | attribution et gestion des rôles |
| Assistant Pasteur (AP) | **une ou plusieurs tribus assignées** | suivi, rapports, validations **uniquement sur ses tribus** |
| Patriarche | sa tribu | suivi, rapports, validation des FISS et changements de tribu |
| Garde (responsable de GEM) | son GEM (dans sa tribu) | présences, notes, suivi, demandes |
| Communication | église | annonces et événements pour toute l'église |
| Accompagnateur d'un fidèle | un fidèle | suivi de ce fidèle uniquement |
| Membre | soi-même | son espace |

- Un rôle peut être attribué plusieurs fois avec des périmètres différents (ex. un AP sur 3 tribus).
- Les **responsables de département** sont désignés dans Organisation (table `department_leaders`) et reçoivent
  automatiquement les droits utiles sur leur département (suivi des membres, répétitions, événements).
- Le rôle « Responsable » a été supprimé : ses titulaires sont devenus responsables de département, sans perte de droits.
- Chaque requête est vérifiée **côté serveur** : permission (`permission:xxx`) puis périmètre (`MemberScope`, `Audience`).
  Masquer un bouton dans l'interface n'est jamais considéré comme une protection.

## Règles métier

- **Inactivité** : un membre devient inactif après **3 mois** sans connexion, sans FISS et sans présence.
  Recalcul quotidien, réactivation automatique dès qu'il revient ; chaque bascule est tracée. Un responsable
  peut forcer le statut (actif, inactif, automatique). Les inactifs n'apparaissent pas dans les feuilles de présence.
- **FISS** : une par mois, verrouillée à l'envoi. Demande de modification motivée → validée ou refusée (motif obligatoire)
  par le patriarche / l'AP de la tribu → fiche modifiable une seule fois pendant 7 jours → reverrouillée.
  Maximum **2 demandes par fiche**, limite appliquée par le serveur. Historique complet dans l'audit.
- **Score de vie spirituelle** : moyenne des composantes **renseignées** (méditation, prière, jeûne /20, sanctification).
  Une donnée manquante n'est jamais comptée comme 0 ; un membre sans fiche n'entre pas dans la moyenne.
- **FISS manquante** : rappels au membre (le 20, puis les derniers jours), puis récapitulatif au patriarche
  (à défaut l'AP, à défaut l'autorité pastorale) des membres actifs de sa tribu sans fiche le mois précédent.
- **Tribu** : choisie une seule fois à l'inscription. Ensuite, changement uniquement par demande approuvée par les
  responsables des deux tribus (ou directement par l'autorité pastorale, tracé).
- **Famille** : aucun lien n'est créé automatiquement. Le conjoint désigné doit confirmer ; les homonymes sont
  départagés par la tribu et la photo ; un refus supprime le lien. Changer de situation matrimoniale retire le lien des deux côtés.
- **GEM** : le Garde d'un GEM appartient obligatoirement à la tribu du GEM.

## Notifications

Service unique `App\Services\Notifier` :

- **Dans l'application** (cloche, page `/notifications`) avec **priorité** (basse, normale, haute) et **lien** vers l'écran concerné.
- **Push** (Web Push VAPID) envoyé après la réponse HTTP ; les abonnements expirés sont nettoyés.
- **Anti-doublon** : chaque envoi automatique a une clé unique dans `notification_dispatches` ; en cas d'échec la clé est
  libérée pour un nouvel essai.
- Événements notifiés : annonces, événements (création, modification, annulation, rappels veille et 1 h avant),
  exercices, FISS (rappels, demandes, décisions), changements de tribu, liens familiaux, profil incomplet,
  anniversaires et anniversaires de mariage (aux responsables, avec un verset), inactivité, nouveaux inscrits, rôles.

## Calendrier et rappels

- Événements ponctuels ou **récurrents** (jour, semaine, 2 semaines, mois), portée multiple, événements personnels privés.
- Jours fériés du Québec/Canada et fêtes chrétiennes calculés chaque année.
- **Anniversaires** (jour/mois du profil ; le 29 février est souhaité le 28 les années non bissextiles) et
  **anniversaires de mariage**, sur le fuseau `APP_TIMEZONE`.
- Message chaleureux avec un verset biblique, envoyé une seule fois par personne et par an.
- Abonnement iCal personnel (Google Agenda, iPhone, Outlook).

## Rapports et exports PDF

`/admin/rapports` (permission `reports.view`) :

- Portée : **toute l'église** (autorité pastorale), **mes tribus**, ou **une tribu** — le serveur refuse toute
  portée hors du périmètre de l'utilisateur.
- Période : 3, 6 ou 12 mois ; données mises en cache 10 minutes.
- Indicateurs : membres, actifs/inactifs, score de vie spirituelle, taux de FISS, assiduité, Vertumètre,
  nouveaux membres, complétion des profils, événements et participations.
- Graphiques accessibles (tableau de données sous chaque graphique, info-bulles au toucher).
- **PDF** : rapport complet (A4) et liste des membres filtrée (A4 paysage), générés sur l'appareil
  à partir des seules données autorisées ; chaque export est tracé dans l'audit.

## Automatismes (tâches planifiées)

Une seule commande idempotente, planifiée toutes les 5 minutes : `php artisan app:tick`

| Étape (`--only=`) | Rôle |
|-------------------|------|
| `activity` | recalcul quotidien actif/inactif |
| `event-reminders` | rappels la veille et 1 h avant |
| `birthdays`, `weddings` | vœux aux membres, récapitulatif aux responsables |
| `tasks` | échéances d'exercices |
| `fiss` | rappels FISS, récapitulatif des FISS manquantes, reverrouillage des modifications expirées |
| `profiles` | recalcul quotidien de la complétion, rappels de profil incomplet |
| `followups` | relances des demandes sans réponse |
| `prune` | nettoyage (anciennes notifications, clés d'envoi) |

Cron recommandé (toutes les minutes) : `php artisan schedule:run`. Sans cron, l'application déclenche
elle-même `app:tick` au plus toutes les 5 minutes quand quelqu'un l'utilise (`AUTO_TICK`).

## Journal d'audit

`/admin/journal` (permission `audit.view`) : FISS, changements de tribu, statuts d'activité, famille, profils,
rôles, départements, publications, notes, exports PDF. Chaque entrée : auteur, membre concerné, valeurs avant/après,
contexte, IP, date. **Aucune route ne permet de modifier ou supprimer** une entrée.

## Sécurité et confidentialité

- Jetons Sanctum à durée limitée ; codes OTP hachés, à usage unique, tentatives limitées.
- Limites de requêtes nommées (connexion, demandes, recherche de membres).
- Permissions et périmètres vérifiés sur chaque route ; tests automatisés dédiés (AP limité à ses tribus, etc.).
- En-têtes de sécurité, HTTPS forcé en production, anti-escalade des rôles.
- Données spirituelles et familiales visibles uniquement des responsables du périmètre ; PDF marqués « confidentiel ».
- Les photos de l'écran de connexion ne montrent aucun visage identifiable.

## Tests et vérifications

```bash
cd backend && php artisan test                 # tests de l'API (règles, permissions, automatismes)
cd frontend && npx tsc -b && npx eslint src    # types et qualité
cd frontend && node scripts/check-api-routes.mjs <chemin/vers/php>   # chaque appel du front existe côté API
cd frontend && npm run build
```

## Déploiement

Procédure pas à pas de cette version (fichiers, migrations, variables, cron, retour arrière) :
[`docs/MISE-A-JOUR-EVOLUTION-PLATEFORME.md`](docs/MISE-A-JOUR-EVOLUTION-PLATEFORME.md).

En résumé : sauvegarde de la base → envoi des fichiers `backend/` modifiés → `php artisan migrate --force`
→ `php artisan config:clear && php artisan route:clear` → build React copié dans `public/` → vérifications.
Ne **pas** relancer le seeder des rôles en production (il écraserait les réglages faits depuis l'écran Rôles).

## Documentation complémentaire

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) : modèle de données et choix techniques.
- [`docs/IA-ARCHITECTURE.md`](docs/IA-ARCHITECTURE.md) : proposition d'architecture pour de futures fonctions d'IA (non implémentées).
- `docs/MISE-A-JOUR-*.md` : procédures des mises à jour successives.
