<div align="center">
  <img src="frontend/public/logo-vh.png" alt="Vases d'Honneur Chicoutimi" width="110" />

  # My vasesdhonneur

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
12. [Tenue en charge](#tenue-en-charge)
13. [Journal d'audit](#journal-daudit)
14. [Agent de supervision](#agent-de-supervision)
15. [Sécurité et confidentialité](#sécurité-et-confidentialité)
16. [Tests et vérifications](#tests-et-vérifications)
17. [Déploiement](#déploiement)
18. [Hébergement Hostinger](#hébergement-hostinger)
19. [Maintenance, sauvegarde et restauration](#maintenance-sauvegarde-et-restauration)
20. [Limites connues](#limites-connues)
21. [Documentation complémentaire](#documentation-complémentaire)

## Fonctionnalités

**Pour chaque fidèle**
- Connexion par téléphone avec code à usage unique (SMS), sans mot de passe.
- Profil en onglets (Identité, Famille, Infos personnelles, Vie spirituelle) avec **indicateur de complétion** et rappels.
- **Famille** : conjoint(e) choisi(e) parmi les membres (confirmation par la personne désignée) ou simplement nommé(e)
  s'il/elle n'est pas inscrit(e) ; date de mariage ; enfants (nom, année de naissance, lien facultatif vers leur profil).
- **Ma vie spirituelle** : cartes interactives (FISS, Vertumètre, Assiduité, Ponctualité, Parcours) qui ouvrent le détail.
- **Fiche de santé spirituelle (FISS)** mensuelle, **verrouillée après envoi** ; modification sur demande validée (2 demandes par fiche au maximum).
- **Calendrier** (mois, semaine, liste) : événements, jours fériés, anniversaires, anniversaires de mariage, échéances.
- **Horaires des cultes** (mercredi, Samedi des miracles et dimanche) dans le calendrier et sur le tableau de bord, avec le programme
  la veille au soir et un rappel 30 minutes avant chaque rendez-vous.
- **« Content de vous revoir »** après quelques jours d'absence : nouveautés depuis la dernière visite.
- **Choix des notifications** reçues sur le téléphone, par catégorie ; brouillons conservés hors ligne.
- **Mes exercices** : vidéos YouTube lues dans l'application (reprise là où on s'est arrêté), lectures et méditations,
  avec date limite et rappels nommant précisément l'exercice.
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
- Présences, notes (Vertumètre), annonces et événements ciblés (une ou plusieurs tribus, GEM, départements).
- **Exercices vidéo** : un lien YouTube, des consignes, une réponse écrite facultative, une date et heure limites ;
  fermeture automatique ; **suivi** par fidèle (pourcentage réellement regardé, avances rapides détectées, réponse).
- **Journal d'audit** en lecture seule.
- **Versets du tableau de bord** (PR et PA) : bibliothèque de textes bibliques, brouillon, programmation,
  rotation quotidienne, mise en avant, aperçu et historique.
- **Rapport mensuel automatique** : chiffres clés du mois écoulé envoyés le 1er à chaque responsable.
- **Rapports mensuels des responsables** : le patriarche (pour sa tribu) et le responsable de département
  remplissent chaque mois un **questionnaire guidé** (rencontres d'échanges, activités menées, GEMs, santé
  spirituelle, mois à venir, âmes gagnées et intégrées avec leurs **noms listés automatiquement**) ;
  l'AP de la tribu et les pasteurs voient les rapports reçus et ceux qui manquent, et les exportent en **PDF**.
- **Rapport hebdomadaire des Gardes** : présence de chaque membre du GEM au culte du dimanche et à la rencontre
  du GEM, envoyé automatiquement au patriarche et à l'AP de la tribu.
- **Responsables** : annuaire toujours à jour des AP, patriarches, responsables de département et Gardes avec leur
  appartenance (pasteurs : toute l'église ; AP et patriarches : leurs tribus) ; la **fiche d'un Garde** montre comment
  il mène son GEM (rapports, présences, FISS des membres, activité de suivi).

**Supervision** (console séparée, projet evh_monitoring sur son propre sous-domaine)
- La plateforme embarque un **agent** : mesure de chaque requête, erreurs et journaux, erreurs des navigateurs,
  appels Twilio et push, automatismes ; blocage des comptes ; API de contrôle signée `/api/agent/*` utilisée par la
  console. Voir [Agent de supervision](#agent-de-supervision).

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
(et `EXPOSE_OTP=true` l'affiche à l'écran de connexion). En production, `SMS_DRIVER=twilio_verify`
envoie et valide les codes avec un service Twilio Verify.

### Connecter Twilio Verify

1. Dans Twilio Console, ouvre **Identity → Verify → Overview**, crée un service et copie son SID (`VA...`).
2. Dans **API keys & auth tokens**, crée une clé API standard avec accès à Verify. Renseigne côté serveur `TWILIO_ACCOUNT_SID`,
   `TWILIO_API_KEY_SID`, `TWILIO_API_KEY_SECRET` et `TWILIO_VERIFY_SERVICE_SID`. Garde le secret de la clé
   dans le `.env` du serveur; ne le colle pas dans le dépôt ni dans un message.
3. Mets `SMS_DRIVER=twilio_verify`, puis vide le cache de configuration Laravel (`php artisan config:clear`
   ou `php artisan config:cache` après avoir modifié le `.env`).
4. Avec un compte d'essai, commence par un numéro de téléphone que Twilio a vérifié sur ce compte. Twilio
   limite les destinataires pendant l'essai; il faudra mettre le compte à niveau avant d'envoyer des OTP
   aux numéros des membres.
5. Vérifie sans envoyer de SMS : `php artisan app:sms-check` (identifiants, nom du service, longueur du code : 6).
   Pour envoyer un vrai code de test : `php artisan app:sms-check +1418XXXXXXX`.

Le SMS est envoyé en français avec le nom du service Verify. Un numéro refusé par Twilio (ligne fixe, pays non
activé, numéro non vérifié en phase d'essai) donne un message clair à l'écran ; une panne ou une mauvaise
configuration donne « momentanément indisponible » et une ligne dans les journaux (sans le numéro complet).

## Variables d'environnement

Modèle complet : `backend/.env.production.example`. Les principales :

| Variable | Rôle |
|----------|------|
| `APP_URL`, `FRONTEND_URL` | URL publique (même domaine pour l'API et l'application) |
| `APP_TIMEZONE` | `America/Toronto` : heures des événements, rappels, anniversaires |
| `DB_*` | connexion MySQL |
| `SANCTUM_EXPIRATION` | durée de validité des sessions (minutes) |
| `SMS_DRIVER`, `TWILIO_ACCOUNT_SID`, `TWILIO_API_KEY_SID`, `TWILIO_API_KEY_SECRET`, `TWILIO_VERIFY_SERVICE_SID` | envoi et validation des codes OTP avec Twilio Verify |
| `EXPOSE_OTP` | phase de test uniquement : affiche le code à l'écran (`false` en production réelle) |
| `WEBPUSH_ENABLED` | active les notifications push (défaut `true`) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | clés push (facultatives : générées automatiquement dans `storage/app/webpush-vapid.json`) |
| `AUTO_TICK` | déclenche les automatismes depuis l'application si le cron manque (défaut `true`) |
| `MONITOR_AGENT_SECRET` | secret partagé avec la console de supervision (vide : API de contrôle fermée) |
| `MONITOR_*` | collecte et agent de supervision : voir [`docs/AGENT-SUPERVISION.md`](docs/AGENT-SUPERVISION.md) |

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
| Publication | `announcements`, `events` (dont `remind_all` : rendez-vous réguliers), `exercises` (vidéo YouTube, `closes_at`), `publication_scopes` (portée multiple : église, tribus, GEM, départements) |
| Exercices | `exercise_responses`, `exercise_video_views` (passages regardés, avances rapides, terminé) |
| Notifications | `user_notifications` (priorité, lien), `push_subscriptions`, `notification_dispatches` (anti-doublon) |
| Rapports des responsables | `leader_reports` (mensuel : tribu ou département, réponses, âmes figées à l'envoi), `gem_weekly_reports` (hebdomadaire : présences du GEM) |
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
| Garde (responsable de GEM) | son GEM (dans sa tribu) | présences, notes, suivi, demandes, rapport hebdomadaire — **sans les FISS** |
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

### Rapports mensuels des responsables

`/admin/rapports-mensuels` — aucun nouveau rôle ni nouvelle permission :

- **Qui remplit** : le patriarche de la tribu (rôle Patriarche sur cette tribu) ; les responsables du département
  (désignés dans Organisation). Un seul rapport par tribu / département et par mois, partagé entre co-responsables.
- **Quand** : le rapport d'un mois s'ouvre le 25 de ce mois et reste modifiable jusqu'à la fin du mois suivant,
  puis il est figé. Brouillon gardé sur l'appareil et sur le serveur (on peut finir sur un autre appareil).
- **Questionnaire** : défini à un seul endroit, `App\Support\LeaderReportCatalog` (questions, choix, conditions) ;
  l'application l'affiche et le serveur valide avec la même définition. Pour modifier une question, c'est ce fichier.
- **Âmes gagnées et intégrées** : tribu = nouveaux inscrits du mois dans la tribu (« accueilli » ou « à accueillir ») ;
  département = membres ayant rejoint le département dans le mois. Liste figée à l'envoi ; des noms de personnes
  pas encore inscrites peuvent être ajoutés à la main.
- **Qui lit** : les auteurs, l'AP de la tribu, les autorités pastorales (permission `reports.view`). Les rapports
  de département ne sont lus que par les pasteurs (PR, PA). Un brouillon n'est visible que de ses auteurs.
- **Notifications** : à l'envoi (AP de la tribu, pasteurs) ; rappel aux auteurs du 1er au 4, relance du 5 au 10.
- **PDF** : un rapport envoyé s'exporte en PDF depuis sa page (généré sur l'appareil, export tracé dans l'audit).

### Rapport hebdomadaire des Gardes et annuaire des responsables

- `/admin/rapport-gem` (Garde) : une semaine va du lundi au dimanche ; son rapport se remplit à partir du dimanche et
  reste modifiable jusqu'au samedi suivant. Présence de chaque membre au culte du dimanche et à la rencontre du GEM,
  plus un mot facultatif. Les présences déjà pointées dans Présences sont précochées. À l'envoi : notification au
  patriarche et à l'AP de la tribu.
- `/admin/responsables` : annuaire lu dans les rôles et l'organisation (aucune saisie). Autorité pastorale : AP,
  patriarches, responsables de département, Gardes ; AP et patriarches : patriarches et Gardes de leurs tribus.
  Un Garde, un responsable de département ou un membre n'y ont pas accès (contrôle serveur).
- Fiche d'un Garde (`/admin/responsables?gem=ID`) : rapports envoyés sur les semaines attendues (depuis sa nomination,
  8 au plus), taux de présence, FISS des membres, activité de suivi des 60 derniers jours. Visible du Garde lui-même,
  des responsables de sa tribu et des pasteurs.

## Automatismes (tâches planifiées)

Une seule commande idempotente, planifiée toutes les 5 minutes : `php artisan app:tick`

| Étape (`--only=`) | Rôle |
|-------------------|------|
| `activity` | recalcul quotidien actif/inactif |
| `event-reminders` | rappels la veille et 1 h avant ; cultes : rappel à tous 30 min avant chaque rendez-vous |
| `service-digest` | la veille dès 18 h : programme des cultes du lendemain, un seul message |
| `birthdays`, `weddings` | vœux aux membres, récapitulatif aux responsables |
| `tasks` | exercices non terminés : relance à J+2, la veille et 3 h avant la fermeture (8 h – 21 h) ; bilan à l'auteur à la fermeture |
| `fiss` | rappels FISS, récapitulatif des FISS manquantes, reverrouillage des modifications expirées |
| `profiles` | recalcul quotidien de la complétion, rappels de profil incomplet |
| `leader-reports` | du 1er au 10 : rappel puis relance aux patriarches et responsables de département sans rapport envoyé |
| `gem-reports` | dimanche dès 18 h (ou lundi) : rappel aux Gardes sans rapport de la semaine ; relance mardi ou mercredi |
| `followups` | relances des demandes sans réponse |
| `prune` | nettoyage (anciennes notifications, clés d'envoi) |

Cron recommandé (toutes les minutes) : `php artisan schedule:run`. Il lance aussi `app:push-outbox` (chaque minute).
Sans cron, l'application déclenche elle-même `app:tick` au plus
toutes les 5 minutes quand quelqu'un l'utilise (`AUTO_TICK`).

## Tenue en charge

Conçue pour 200 à 500 membres connectés en même temps sur un hébergement mutualisé :

- **Un seul appel léger** toutes les ~45 s par appareil (`/api/me/pulse`) : compteur de notifications et signature
  des données ; chaque écran ne se recharge que si ce qui l'intéresse a changé. Rien n'est demandé quand
  l'application est en arrière-plan ; les appareils sont décalés aléatoirement.
- **Aucun écran ne ralentit quand l'église grandit** : le nombre de requêtes SQL de chaque écran est identique
  avec 60 ou 400 membres (test automatique `LoadProfileTest`), aucun écran ne dépasse 1,5 s même sans cache.
- **Push en parallèle** (lots de 20) : 500 appareils en quelques secondes, appareils expirés supprimés.
- Écritures limitées : dernière utilisation des sessions toutes les 5 min, dernière activité toutes les 10 min.
- **Doublons simultanés** (double clic, réseau qui renvoie) : contraintes d'unicité en base + verrous sur les règles
  sensibles ; réponse 409 propre au lieu d'une erreur serveur ; base saturée : 503 avec nouvelle tentative.
- **Jamais de page blanche** : écran de secours, rechargement automatique après une mise en ligne, nouvelles tentatives
  automatiques des lectures, délai maximal par requête.
- En production : `php artisan config:cache`, `route:cache`, `event:cache` et le cron (voir Déploiement).

## Journal d'audit

`/admin/journal` (permission `audit.view`) : FISS, changements de tribu, statuts d'activité, famille, profils,
rôles, départements, publications, notes, exports PDF, rapports mensuels (envoi, corrections, export) et hebdomadaires des Gardes. Chaque entrée : auteur, membre concerné, valeurs avant/après,
contexte, IP, date. **Aucune route ne permet de modifier ou supprimer** une entrée.

Les actions décidées dans la console de supervision (blocage, suppression...) y apparaissent aussi, avec la mention
« console de supervision » et le nom de la personne ; la console garde en plus son propre journal d'audit.

## Agent de supervision

La console de supervision et d'administration est un **projet séparé** (dépôt `evh_monitoring`, sous-domaine
distinct, base et connexion propres). La plateforme n'en contient que l'agent :

- **Collecte** (tables `monitor_*`) : compteurs de requêtes par tranche de 5 minutes avec des routes génériques,
  requêtes notables (erreurs, refus, limites, lenteurs), journaux et exceptions (niveau `MONITOR_LOG_LEVEL` et plus),
  requêtes SQL lentes sans leurs valeurs, erreurs des navigateurs (`POST /api/monitor/client-errors`), connexions et
  codes erronés, appels Twilio Verify et envois push, passages des automatismes. Identifiant `X-Request-Id` sur
  chaque réponse. Masquage systématique : jamais de code, de jeton, de numéro complet ni de contenu privé.
- **API de contrôle** `/api/agent/*` : état détaillé, paquets installés, fichiers journaux masqués, bloquer,
  débloquer, fermer les sessions, supprimer un compte, lancer les automatismes, renvoyer les notifications,
  diagnostic SMS, recharger la configuration, alerte dans l'application. Chaque requête est signée (HMAC-SHA256,
  secret partagé `MONITOR_AGENT_SECRET`, horodatage, anti-rejeu) ; sans secret, l'API est fermée.
- **Comptes bloqués** : plus de code envoyé, sessions fermées, toute requête refusée.
- Nettoyage de sécurité : mesures supprimées au-delà de `MONITOR_AGENT_MAX_DAYS` (120 jours).

Détail : [`docs/AGENT-SUPERVISION.md`](docs/AGENT-SUPERVISION.md).

## Sécurité et confidentialité

- Jetons Sanctum à durée limitée ; codes OTP hachés, à usage unique, tentatives limitées.
- Limites de requêtes nommées (connexion, demandes, recherche de membres).
- Permissions et périmètres vérifiés sur chaque route ; tests automatisés dédiés (AP limité à ses tribus, etc.).
- En-têtes de sécurité, HTTPS forcé en production, anti-escalade des rôles.
- Données spirituelles et familiales visibles uniquement des responsables du périmètre ; PDF marqués « confidentiel ».
- **FISS d'un membre** (contenu, fiche du mois remplie ou non, historique, taux) : réservées aux pasteurs et aux
  responsables de **sa tribu** (patriarche, AP). Un Garde, un responsable de département ou un accompagnateur suivent
  le membre sans voir ses fiches (`User::canViewFissOf`, appliqué à toutes les routes ; matrice de tests `RoleAccessMatrixTest`).
- Les photos de l'écran de connexion ne montrent aucun visage identifiable.

**Injections** (audit complet, tests `InjectionTest`) :

- **SQL** : toutes les requêtes passent par Eloquent / le constructeur de requêtes avec paramètres liés ; aucun texte
  saisi n'est concaténé dans du SQL ; les recherches neutralisent `%` et `_` (`App\Support\Like`).
- **XSS** : React échappe tout texte affiché, aucun HTML brut n'est injecté ; **politique de sécurité du contenu (CSP)**
  dans le build (scripts de l'application et du lecteur YouTube uniquement, aucune connexion vers un autre site).
- **Liens** : les vidéos sont réduites à leur identifiant YouTube et relues depuis youtube-nocookie.com ; le lien d'une
  notification push ne peut ouvrir qu'une page de l'application.
- **Fichiers** : images uniquement (JPEG, PNG, WebP, GIF ; SVG refusé), taille limitée, nom aléatoire.
- **Affectation de masse** : champs autorisés listés modèle par modèle ; un champ inconnu est ignoré.
- **iCal** : aucun retour à la ligne brut dans le flux d'agenda (pas d'injection de lignes).
- Aucune exécution de commande système ni désérialisation de données reçues.

## Tests et vérifications

```bash
cd backend && php artisan test                 # tests de l'API (règles, permissions, automatismes, concurrence, injections, pannes)
cd backend && php artisan test --group=benchmark   # mesures de volume 500 → 5 000 membres (quelques minutes)
cd backend && DB_CONNECTION=mysql DB_DATABASE=evh_bench BENCH_SIZES=2,50,100,250,500,1000,2000,5000 php artisan test --group=benchmark   # idem sur MySQL
cd frontend && npx tsc -b && npx eslint src    # types et qualité
cd frontend && node scripts/check-api-routes.mjs <chemin/vers/php>   # chaque appel du front existe côté API
cd frontend && node scripts/check-accents.mjs  # aucun texte affiché sans ses accents
cd frontend && npm run build
```

Test de charge (utilisateurs simultanés), sur une copie de test, après `php artisan app:load-test-users 500` :

```bash
node scripts/load/charge.mjs --base https://copie-de-test --tokens load-test-tokens.txt --mode realiste   # aussi : pic, capacite, endurance
```

Sans installation (Node 18+) ; `scripts/load/k6-scenario.js` fait le même test avec k6. Supprimer ensuite les comptes
de test : `php artisan app:load-test-users --delete` (`--force` sur un serveur en production). Marche à suivre
complète : [`docs/A-FAIRE-DE-VOTRE-COTE.md`](docs/A-FAIRE-DE-VOTRE-COTE.md), étape 6.

## Déploiement

**Mise en ligne d'octobre 2026 (paquets complets, Twilio compris)** :
[`docs/MISE-EN-LIGNE-HOSTINGER-2026-10.md`](docs/MISE-EN-LIGNE-HOSTINGER-2026-10.md).

Procédure précédente (20 septembre 2026) :
[`docs/MISE-EN-LIGNE-HOSTINGER.md`](docs/MISE-EN-LIGNE-HOSTINGER.md) (paquets prêts dans `deployment/`).

Détail par évolution (fichiers, migrations, variables, cron, retour arrière) :
- [`docs/MISE-A-JOUR-EVOLUTION-PLATEFORME.md`](docs/MISE-A-JOUR-EVOLUTION-PLATEFORME.md) : rapports, validations, périmètres, famille, audit ;
- [`docs/MISE-A-JOUR-VIDEOS-CULTES-CHARGE.md`](docs/MISE-A-JOUR-VIDEOS-CULTES-CHARGE.md) : exercices vidéo, horaires des cultes, tenue en charge, sécurité ;
- [`docs/MISE-A-JOUR-AUDIT-ROBUSTESSE.md`](docs/MISE-A-JOUR-AUDIT-ROBUSTESSE.md) : audit, versets administrables, hébergement, robustesse ;
- [`docs/MISE-A-JOUR-PUSH.md`](docs/MISE-A-JOUR-PUSH.md) : notifications push fiables (boîte d'envoi, test par appareil, diagnostic) ;
- [`docs/MISE-A-JOUR-RAPPORTS-MENSUELS.md`](docs/MISE-A-JOUR-RAPPORTS-MENSUELS.md) : rapports mensuels (patriarche, responsable de département) et hebdomadaires (Gardes), annuaire des responsables, Samedi des miracles, nom de l'application.

En résumé : sauvegarde de la base → envoi des fichiers `backend/` modifiés → `php artisan migrate --force`
→ `php artisan config:cache && php artisan route:cache && php artisan event:cache` → build React copié dans `public/` → vérifications.
Ne **pas** relancer le seeder des rôles en production (il écraserait les réglages faits depuis l'écran Rôles).

## Hébergement Hostinger

Hébergement mutualisé constaté (hPanel) : 1 cœur CPU, 2 Go de RAM, 40 workers PHP, 20 Go de disque, PHP 8.3 avec
OPcache, cron disponible. Pas de Redis ni de worker permanent supposés : l'application n'en dépend pas.

- **Cron** (indispensable) : `* * * * * /usr/bin/php …/public_html/espace/artisan schedule:run` — déjà en place.
- **Caches Laravel** après chaque mise en ligne : `php artisan config:cache && php artisan route:cache && php artisan event:cache`
  (après toute modification du `.env` : `php artisan config:cache` à nouveau).
- **Réglages PHP recommandés** (hPanel → Configuration PHP) : `memory_limit` 256M, `upload_max_filesize` 16M,
  `post_max_size` 20M, `exposePhp` désactivé, `logErrors` activé.
- **Serveur web** : `public/.htaccess` sert directement l'application (sans PHP), met en cache long les fichiers
  versionnés et renvoie un vrai 404 pour un fichier absent. Testé avec Apache 2.4 dans les deux organisations
  possibles (racine sur `public/` ou sur le dossier Laravel).
- **Surveillance** : `GET /api/health` (base, cache, disque, dernier passage des automatismes, sans donnée sensible),
  à brancher sur un service de surveillance gratuit (ex. UptimeRobot, toutes les 5 min).

## Maintenance, sauvegarde et restauration

- **Journaux** : `storage/logs/laravel-AAAA-MM-JJ.log`, un fichier par jour, 14 jours conservés
  (`LOG_STACK=daily`). Les notifications de plus de 6 mois sont nettoyées automatiquement (`app:tick`).
- **État des automatismes** : `/api/health` indique depuis combien de minutes ils ont tourné et si une étape a échoué ;
  le détail (durée et résultat de chaque étape) est gardé en cache.
- **Sauvegardes** : Hostinger sauvegarde le compte (stockage à Boston). Avant chaque mise à jour, faire en plus un
  export SQL (phpMyAdmin → Exporter) et une copie de `.env` et `storage/app/webpush-vapid.json`.
- **Restauration** (à tester une fois sur une base vide avant d'en avoir besoin) :
  1. phpMyAdmin → base de test → Importer le fichier SQL ;
  2. remettre `.env` et `storage/app/webpush-vapid.json` ;
  3. `php artisan optimize:clear && php artisan migrate:status` (toutes les migrations « Ran ») ;
  4. ouvrir `/api/health`.
- **Retour arrière d'une mise à jour** : `php artisan migrate:rollback --step=N` (N indiqué dans chaque procédure
  `docs/MISE-A-JOUR-*.md`), puis remettre les fichiers précédents. La sauvegarde SQL reste la voie de retour complète.

## Limites connues

- **Charge simultanée** : mesurée sur un poste reproduisant le forfait (1 cœur, 40 processus PHP, MySQL,
  5 500 membres) : 500 membres connectés au rythme réel, p95 262 ms, 0 erreur ; plafond d'environ 49 requêtes/s
  sur un cœur ; 500 ouvertures dans les mêmes 5 secondes : 0,9 % d'erreurs et jusqu'à ~30 s d'attente pour les
  derniers servis (voir `docs/AUDIT-2026-09.md` §9). À confirmer sur l'hébergement réel (Linux, plus rapide) avec
  `scripts/load/charge.mjs`.
- **Rapport « toute l'église » sur 12 mois** : calculé à la demande (≈ 2 s à 5 000 membres en local), puis en cache 10 min.
- **Push sur iPhone** : uniquement avec l'application installée sur l'écran d'accueil (iOS 16.4+).
- **SMS** : tant que `SMS_DRIVER=log`, aucun SMS n'est envoyé (phase de test).
- **Supervision** : les mesures commencent à la mise en service de l'agent ; la console (projet séparé) a ses propres
  limites, décrites dans son README.
- **Vertumètre** : actuellement la moyenne des notes des responsables ; version remplie par le membre en attente des questionnaires.

## Documentation complémentaire

- [`docs/AUDIT-2026-09.md`](docs/AUDIT-2026-09.md) : audit complet (architecture, sécurité, performance, hébergement, mesures).
- [`docs/A-FAIRE-DE-VOTRE-COTE.md`](docs/A-FAIRE-DE-VOTRE-COTE.md) : ce que l'équipe doit faire (mise en ligne, hPanel, surveillance, restauration, test de charge).
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) : modèle de données et choix techniques.
- [`docs/AGENT-SUPERVISION.md`](docs/AGENT-SUPERVISION.md) : agent de supervision (collecte, API signée pour la console evh_monitoring).
- [`docs/IA-ARCHITECTURE.md`](docs/IA-ARCHITECTURE.md) : proposition d'architecture pour de futures fonctions d'IA (non implémentées).
- `docs/MISE-A-JOUR-*.md` : procédures des mises à jour successives.
