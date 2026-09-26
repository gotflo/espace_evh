# Mise à jour : évolution complète de la plateforme

Rapports et PDF, validations (FISS, changement de tribu), périmètre strict des Assistants Pasteurs,
publication multi-tribus, inactivité automatique, famille et mariages, complétion des profils,
journal d'audit, rôle Garde, départements, fond de connexion.

> Cette version inclut aussi, si elles n'ont pas encore été mises en ligne, les évolutions précédentes
> (calendrier, notifications push, services, nouveaux inscrits). `php artisan migrate` n'exécute que
> les migrations qui manquent : la procédure est la même dans les deux cas.

---

## 1. Avant de commencer (5 min)

1. **Sauvegarder la base** : hPanel → Bases de données → phpMyAdmin → Exporter (format SQL, rapide).
   Conserver le fichier daté (`evh-avant-evolution-AAAAMMJJ.sql`).
2. **Sauvegarder le dossier Laravel** du serveur (au minimum `app/`, `config/`, `routes/`, `database/`, `.env`,
   `storage/app/webpush-vapid.json`).
3. Choisir un moment calme (les migrations prennent quelques secondes).

## 2. Dépendances

- **Composer (PHP)** : aucune nouvelle dépendance. Le dossier `vendor/` du serveur reste tel quel.
- **npm (build React, en local seulement)** : `pdfmake` (+ `@types/pdfmake` en développement). Exécuter
  `npm install` dans `frontend/` avant le build. Rien à installer sur le serveur.
- PHP ≥ 8.3 avec l'extension `openssl` (déjà requise pour le push).

## 3. Fichiers à envoyer sur le serveur (dossier Laravel)

Le plus simple et le plus sûr : **remplacer entièrement** les dossiers `app/`, `routes/`, `config/`,
`database/migrations/`, `database/seeders/`, `resources/openssl/` et le fichier `bootstrap/app.php`
par ceux du dépôt. Détail pour contrôle :

### Créés
```
app/Console/Commands/AutomationTick.php
app/Http/Controllers/Api/Admin/AudienceController.php
app/Http/Controllers/Api/Admin/AuditLogController.php
app/Http/Controllers/Api/Admin/NewMemberController.php
app/Http/Controllers/Api/Admin/ReportController.php
app/Http/Controllers/Api/Admin/ValidationController.php
app/Http/Controllers/Api/CalendarController.php
app/Http/Controllers/Api/MyFamilyController.php
app/Http/Controllers/Api/MyNotificationController.php
app/Http/Controllers/Api/MyServiceController.php
app/Http/Controllers/Api/MyTribeChangeController.php
app/Http/Middleware/TrackActivity.php
app/Http/Middleware/TriggerAutomation.php
app/Models/AuditLog.php
app/Models/Concerns/HasPublicationScopes.php
app/Models/FamilyLink.php
app/Models/FissEditRequest.php
app/Models/PublicationScope.php
app/Models/PushSubscription.php
app/Models/TribeChangeApproval.php
app/Models/TribeChangeRequest.php
app/Models/UserNotification.php
app/Services/ActivityService.php
app/Services/CalendarService.php
app/Services/FamilyService.php
app/Services/FissService.php
app/Services/Notifier.php
app/Services/Push/WebPush.php
app/Services/ReportService.php
app/Services/TribeChangeService.php
app/Support/Audit.php
app/Support/Blessings.php
app/Support/GemRules.php
app/Support/Holidays.php
app/Support/ProfileCompletion.php
app/Support/Recipients.php
database/migrations/2026_09_25_100001_create_user_notifications_table.php
database/migrations/2026_09_25_100002_create_push_subscriptions_table.php
database/migrations/2026_09_25_100003_add_recurrence_to_events.php
database/migrations/2026_09_25_100004_add_welcome_and_service_tracking.php
database/migrations/2026_09_26_100001_add_broadcast_and_member_scoped_roles.php
database/migrations/2026_09_27_100001_create_audit_logs_table.php
database/migrations/2026_09_27_100002_create_publication_scopes_table.php
database/migrations/2026_09_27_100003_add_activity_tracking_to_users.php
database/migrations/2026_09_27_100004_add_fiss_locking_and_edit_requests.php
database/migrations/2026_09_27_100005_create_tribe_change_requests.php
database/migrations/2026_09_27_100006_create_family_links.php
database/migrations/2026_09_27_100007_restructure_roles_and_departments.php
resources/openssl/openssl.cnf
```
(Les fichiers `tests/` ne sont pas nécessaires sur le serveur.)

### Modifiés
```
app/Console/Commands/FissRemind.php
app/Http/Controllers/Api/Admin/AnnouncementController.php
app/Http/Controllers/Api/Admin/AttendanceController.php
app/Http/Controllers/Api/Admin/EvaluationController.php
app/Http/Controllers/Api/Admin/EventController.php
app/Http/Controllers/Api/Admin/ExerciseController.php
app/Http/Controllers/Api/Admin/FissController.php
app/Http/Controllers/Api/Admin/GemController.php
app/Http/Controllers/Api/Admin/MemberController.php
app/Http/Controllers/Api/Admin/OrgController.php
app/Http/Controllers/Api/Admin/RequestController.php
app/Http/Controllers/Api/Admin/RoleController.php
app/Http/Controllers/Api/Admin/SpiritualController.php
app/Http/Controllers/Api/Admin/StatsController.php
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/MyEventController.php
app/Http/Controllers/Api/MyExerciseController.php
app/Http/Controllers/Api/MyFissController.php
app/Http/Controllers/Api/MyOverviewController.php
app/Http/Controllers/Api/MyRequestController.php
app/Http/Controllers/Api/ProfileController.php
app/Models/Announcement.php
app/Models/Department.php
app/Models/Event.php
app/Models/EventParticipation.php
app/Models/Exercise.php
app/Models/Gem.php
app/Models/Profile.php
app/Models/SpiritualHealthForm.php
app/Models/User.php
app/Providers/AppServiceProvider.php
app/Support/Audience.php
app/Support/LeaderRole.php
app/Support/MemberScope.php
bootstrap/app.php
config/app.php
config/services.php
database/seeders/DepartmentsSeeder.php
database/seeders/RolesAndPermissionsSeeder.php
routes/api.php
routes/console.php
```
(`.env.production.example` et `phpunit.xml` sont modifiés dans le dépôt mais ne s'envoient pas.)

### Supprimés
Aucun fichier du backend. Côté application, `src/components/TargetField.tsx` est supprimé du code source
(remplacé par le sélecteur d'audience) : il disparaît naturellement du build.

### À ne jamais écraser sur le serveur
- `.env`
- `storage/app/webpush-vapid.json` (clés push : le supprimer obligerait chaque appareil à réactiver les notifications)
- `storage/app/public/` (photos, images d'annonces et d'événements)

## 4. Variables d'environnement (`.env` du serveur)

Vérifier / ajouter :

```ini
APP_TIMEZONE=America/Toronto
# Facultatif (valeurs par défaut) :
# WEBPUSH_ENABLED=true
# AUTO_TICK=true
# VAPID_PUBLIC_KEY= / VAPID_PRIVATE_KEY= / VAPID_SUBJECT=mailto:...   (sinon générées automatiquement)
```

Aucune autre variable nouvelle. `EXPOSE_OTP` doit repasser à `false` dès que l'envoi réel de SMS est configuré.

## 5. Base de données

```sh
cd /chemin/du/dossier/qui/contient/artisan
php artisan migrate:status          # liste les migrations « Pending »
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

Ce que font les migrations de cette version (toutes réversibles) :

| Migration | Effet |
|-----------|-------|
| `2026_09_27_100001_create_audit_logs_table` | journal d'audit |
| `2026_09_27_100002_create_publication_scopes_table` | portée multiple des annonces, événements, exercices ; **reprend** les anciennes cibles puis supprime `target_type`/`target_id` ; ajoute `events.is_personal` |
| `2026_09_27_100003_add_activity_tracking_to_users` | `activity_status` (tous « actif » au départ, recalculé au premier passage de `app:tick`), `activity_changed_at`, `last_seen_at` ; priorité des notifications |
| `2026_09_27_100004_add_fiss_locking_and_edit_requests` | verrouillage des FISS (les fiches existantes sont verrouillées), demandes de modification |
| `2026_09_27_100005_create_tribe_change_requests` | demandes de changement de tribu et approbations |
| `2026_09_27_100006_create_family_links` | liens familiaux, conjoint non inscrit, date de mariage, enfants, taux de complétion |
| `2026_09_27_100007_restructure_roles_and_departments` | GAD → **Garde** ; rôle **Responsable supprimé** (ses titulaires deviennent responsables de leur département, tracé dans l'audit) ; nouvelles permissions données au PA, AP et Patriarche ; départements : Ecodim 1, Ecodim 2, Eden 1, Eden 2, Wedding Planner, Gestion de cultes ajoutés/renommés, **Coach Bloom supprimé** (membres détachés, tracé) |

**Ne pas lancer** `php artisan db:seed --class=RolesAndPermissionsSeeder` en production : les migrations font
déjà les changements nécessaires, et le seeder écraserait les permissions ajustées depuis l'écran Rôles.

## 6. Application (build React)

En local :

```sh
cd frontend
npm install
npm run build
```

Puis envoyer **le contenu** de `frontend/dist/` dans le dossier `public/` du Laravel sur le serveur
(remplacer `index.html`, `sw.js`, `manifest.webmanifest`, le dossier `assets/`, et ajouter le nouveau dossier `login/`).
Ne pas toucher à `public/index.php`, `public/.htaccess`, `public/storage`.

Les anciens fichiers de `public/assets/` peuvent rester (noms horodatés) ; les supprimer libère de la place.

## 7. Tâche planifiée (cron)

hPanel → Avancé → Tâches Cron, **toutes les minutes** :

```sh
/usr/bin/php /chemin/du/dossier/artisan schedule:run >> /dev/null 2>&1
```

Puis un premier passage manuel pour vérifier :

```sh
php artisan app:tick
```

Au premier passage du jour, la complétion de tous les profils est recalculée et le statut actif/inactif mis à jour.

## 8. Notifications push

Rien à configurer si `storage/app/webpush-vapid.json` existe déjà (conserver ce fichier). Sinon il est créé au
premier usage. Sur iPhone, le push nécessite l'application installée sur l'écran d'accueil (iOS 16.4+).

## 9. Vérifications après mise en ligne (10 min)

1. Connexion, tableau de bord : les tuiles « FISS du mois » et « Profils incomplets » s'affichent (responsables).
2. `Rapports` : choisir une tribu, 6 mois, télécharger le PDF du rapport et la liste des membres.
3. Un Assistant Pasteur ne voit **que** ses tribus (membres, rapports, publication).
4. `Ma fiche (FISS)` : une fiche envoyée est verrouillée ; le bouton « Demander une modification » apparaît.
5. `Validations` : les demandes arrivent chez le patriarche / l'AP ; un refus exige un motif.
6. `Mon profil → Famille` : marié(e) → recherche du conjoint ; la personne désignée reçoit une demande à confirmer.
7. `Organisation` : chaque département affiche ses responsables ; Coach Bloom n'existe plus.
8. `Journal` (Pasteur Résident) : les actions ci-dessus y apparaissent.
9. Écran de connexion : fond photo animé.

## 10. Retour arrière (si nécessaire)

1. Remettre les fichiers sauvegardés à l'étape 1 (`app/`, `config/`, `routes/`, `database/`, `bootstrap/app.php`)
   et l'ancien contenu de `public/`.
2. Annuler les migrations de cette version :
   ```sh
   php artisan migrate:rollback --step=7
   ```
   (`--step=12` si les migrations 2026_09_25 et 2026_09_26 ont été appliquées dans la même opération ;
   `php artisan migrate:status` indique les lots.)
3. En cas de doute, restaurer la sauvegarde SQL de l'étape 1 dans phpMyAdmin (Importer), puis
   `php artisan config:clear && php artisan route:clear`.

Note : le retour arrière restaure la structure (dont les anciennes cibles de publication et GAD) mais ne recrée pas
le rôle Responsable ; les données saisies entre-temps dans les nouvelles tables (demandes, liens familiaux, audit)
sont perdues. La sauvegarde SQL de l'étape 1 est donc la voie de retour complète.
