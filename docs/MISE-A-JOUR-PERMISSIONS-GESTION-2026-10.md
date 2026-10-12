# Mise à jour : permissions par module, compte de gestion principal, ressources et liens vidéo

> Préparée en octobre 2026, **pas encore en ligne**. À déployer seulement après le feu vert, avec le lot
> « RM, devoirs… » (docs/MISE-A-JOUR-RM-DEVOIRS-2026-10.md) dont elle dépend.

## Ce qui change

### 1. Permissions par module (écran « Rôles »)
- 50 permissions rangées en **17 modules** (tableau de gestion, comptes et rôles, membres, organisation, suivi
  spirituel, présences, exercices, annonces, événements, textes bibliques, horaires, ressources, devoirs, demandes,
  rapports, rendez-vous, journal). Chaque permission indique sa **nature** (onglet, consulter, données des autres,
  créer, modifier, supprimer, publier, exporter, valider, paramètres…) et l'étiquette **Données personnelles** quand
  elle ouvre les fiches, FISS, Vertumètres, réponses ou rapports des fidèles.
- Dans l'éditeur : un bloc repliable par module, compteur « 2/4 », boutons **Tout** / **Aucun**, recherche, total
  des permissions et nombre de permissions sur des données personnelles. Chaque carte de rôle montre le nombre de
  personnes qui l'ont.
- Les modifications s'appliquent dès la requête suivante des personnes concernées (aucune reconnexion).
- Les créations, modifications (permissions ajoutées / retirées) et suppressions de rôles sont journalisées.

Nouvelles permissions (catalogue : `backend/app/Support/PermissionCatalog.php`) :

| Module | Permissions ajoutées |
| --- | --- |
| Comptes | `accounts.view` |
| Exercices | `exercises.delete`, `exercises.view_responses` (et `exercises.create` désormais appliquée) |
| Annonces | `announcements.delete` |
| Événements | `events.delete`, `events.participants` |
| Textes bibliques | `verses.view`, `verses.edit`, `verses.publish`, `verses.delete` |
| Horaires | `schedule.view`, `schedule.edit`, `schedule.delete` |
| Ressources | `resources.view`, `resources.edit`, `resources.publish`, `resources.delete` |
| Devoirs (DS) | `assignments.publish`, `assignments.delete` |
| Rapports | `reports.export` (et `members.export` désormais appliquée) |
| Journal | `audit.view_details` |

`platform.manage` ouvre maintenant le **tableau de gestion**. `content.manage` disparaît : il est remplacé par les
permissions des textes bibliques et des horaires.

**Aucun rôle ne perd ce qu'il faisait** : la migration donne les nouvelles permissions détaillées à chaque rôle qui
avait la permission « parente » (ex. `content.manage` → versets + horaires ; `exercises.assign` → créer, supprimer,
lire les réponses ; `reports.view` → exports ; `audit.view` → détail du journal). Devoirs et ressources restent au
seul Pasteur Résident, comme avant. Les rôles personnalisés sont convertis de la même façon.

**Sécurité renforcée (anti-escalade)** : une personne qui gère les rôles ne peut plus donner ou retirer une
permission qu'elle n'a pas, ni modifier, attribuer ou retirer un rôle de rang égal ou supérieur au sien (avant, un
PA pouvait s'ajouter n'importe quelle permission ou retirer le rôle du Pasteur Résident). Le rôle de Pasteur
Résident n'est plus modifiable (il a toujours tout).

Toutes les permissions sont vérifiées **sur le serveur** (routes, contrôleurs, exports) : masquer un bouton n'est
jamais la seule protection.

### 2. Compte de gestion principal (+228 93 47 17 03)
- **Même page de connexion que tout le monde, sans code SMS.** Seul ce numéro (normalisé : `+228 93 47 17 03`,
  `0022893471703`, `93 47 17 03` avec le Togo…) est reconnu et passe directement.
- **Appareil de confiance** (choix de l'utilisateur, le 2026-10-10) : sur un appareil déjà autorisé, saisir le numéro
  suffit pour entrer. Un **nouvel appareil** demande une seule fois un code d'autorisation généré sur le serveur
  (`php artisan app:manager-activation-code` : 12 caractères, usage unique, 24 h). Sans appareil autorisé, le numéro
  seul ne donne rien : quelqu'un qui connaît le numéro ne peut pas devenir gestionnaire (il pourrait sinon
  s'attribuer un rôle de pasteur et lire les FISS de toute l'église).
- 5 codes faux : verrouillage 15 min (même pour un appareil de confiance), alerte dans le journal de sécurité et la
  console de supervision. Un code SMS demandé pour ce numéro est refusé.
- Aucun compte ordinaire n'est jamais créé avec ce numéro. Si un compte existait déjà avec ce numéro, il n'est repris
  qu'à la première autorisation : ses rôles sont retirés (consignés dans le journal de sécurité), ses sessions fermées.
- Le jeton de l'appareil est gardé dans le navigateur (`evh_manager_device`, conservé après la déconnexion) ; le
  serveur n'en garde que l'empreinte (table `manager_devices`). Retirer le compte (`--revoke`) retire tous les appareils.
- Droits : toutes les permissions de **gestion** (tableau de gestion, comptes et rôles, organisation, annonces,
  événements, exercices, textes bibliques, horaires, ressources, devoirs, journal), indépendamment des rôles.
  Il attribue tout rôle sauf celui de Pasteur Résident (réservé au PR) et ne reçoit lui-même aucun rôle.
- **Jamais** : fiches des membres, FISS, Vertumètre, RM, réponses aux devoirs et exercices, notes, suivi spirituel,
  demandes, présences, rapports (tribus, mensuels, GEM), rendez-vous, exports. Refusé côté serveur (403), même en
  appelant l'API directement. Le tableau de gestion ne montre que des chiffres globaux ; la liste des comptes ne
  montre que le nom, la tribu, la fin du numéro (« •••• 03 ») et les fonctions.
- Pas d'espace membre (FISS, profil, exercices…) : ces pages et API lui sont fermées.
- Invisible dans toutes les listes ordinaires (membres, comptes, recherches, statistiques, rapports, notifications
  aux responsables) ; sa « fiche » n'est accessible à personne. Ses contenus s'affichent signés
  « Administration de la plateforme ».
- Ses opérations n'apparaissent **pas** dans le journal d'activité ni dans l'historique des versets : elles vont au
  **journal de sécurité privé** (table `security_logs`), avec chaque requête d'écriture et chaque refus. Ce journal
  n'est lisible que sur le serveur (`php artisan app:security-log`) ; les alertes (activation, échecs, retrait)
  remontent aussi à la console de supervision.
- Les autres administrateurs (PR, PA, Administrateur) ne reçoivent aucun de ces privilèges ; le PR garde tout.

### 3. Journal d'activité
- Sans `audit.view_details`, le journal ne montre que les opérations de gestion (rôles, contenus, organisation),
  sans valeurs modifiées ni adresses IP. Les PR et PA gardent le détail complet (permission donnée par la migration).

### 4. Ressources et liens vidéo
- Nouvel écran **Ressources** (menu Gestion) ; l'onglet « Ressources RM » de « Suivi du mois » y est déplacé.
- Collez un lien YouTube (watch, youtu.be, shorts, embed, live, mobile, avec `&t=` ou `?si=`) : la vidéo est
  **reconnue automatiquement** (miniature, titre et auteur remplis s'ils sont vides). Un lien YouTube abîmé est
  signalé et refusé ; les chaînes et listes de lecture restent de simples liens ; `ftp:`, `javascript:`… refusés.
- **Brouillon / publiée** : les membres ne voient que les ressources publiées (toutes les ressources existantes
  restent publiées). Publier, dépublier ou modifier une ressource publiée demande `resources.publish`.
- Filtres Toutes / Publiées / Brouillons ; boutons selon les permissions (modifier, publier, supprimer).

### 5. Textes bibliques et autres écrans
- Versets : sans `verses.publish`, seulement des brouillons ; boutons Publier / Retirer / supprimer / ordre selon
  les permissions. Horaires, annonces, événements, exercices, rapports : boutons affichés selon les permissions.

### 6. Exports PDF
- Le PDF n'est plus fabriqué à partir de ce qui est affiché à l'écran : il est construit à partir des données
  remises par le serveur pour l'export (`GET /api/admin/reports/export`, `GET /api/admin/leader-reports/{id}/export`),
  qui vérifie le droit d'exporter (`reports.export`, `members.export` ; rapport mensuel : son auteur ou
  `reports.export`) et journalise l'export dans la même requête. La liste affichée des membres reste paginée.
- Nouvelle présentation des trois documents (rapport de tribu / d'église, liste des membres, rapport mensuel) :
  bandeau aux couleurs de l'église avec logo, fiche d'identité du document, sections numérotées, tableaux à
  en-tête répété, dates et numéros lisibles, mention « Document confidentiel — usage pastoral », date d'export et
  pagination.

### 7. Ressources (logique vérifiée)
- Chaque ressource est rangée par thème (méditation, prière, jeûne, culte) et apparaît chez les membres, une fois
  publiée, dans l'onglet de ce thème (Mes RM › Ressources pour vous aider). Le lien « Ressources (n) » d'une rubrique
  ouvre maintenant l'onglet de son thème ; une ressource changée de thème passe à la fin de son nouvel onglet.

### 8. Courriels d'essai
- `MAIL_TEST_BANNER=true` (essais seulement) : sujet préfixé « [TEST] » et bandeau « TEST — COURRIEL D'ESSAI ».

### 9. FISS, inscription et essais
- FISS : la rubrique « Vie intérieure » est **obligatoire** (6 questions), comme toutes les questions de la fiche ;
  le serveur l’exige aussi désormais (seul le commentaire reste facultatif ; situation conjugale pour les mariés).
- Inscription : champ **Courriel** (conseillé) dans le formulaire ; s’il est rempli, le bandeau « Recevez vos rappels
  par courriel » ne s’affiche pas. Même forme que l’adresse ajoutée depuis le bandeau (minuscules).
- Mot de bienvenue du tableau de bord : disparaît en fondu après 6 secondes.
- Essais locaux uniquement (`TEST_DIRECT_LOGIN=true` **et** `APP_ENV=local`) : connexion sans code SMS et inscriptions
  validées aussitôt. Ignoré en production ; ne pas mettre cette variable dans le `.env` du serveur.

### 10. Service, vidéos et listes
- **Service** : le profil ne permet plus de choisir ses départements. Le fidèle s’inscrit depuis « Service » ; sa
  demande attend la validation du responsable du département (notification dans l’application + courriel), visible
  dans Validations › Inscriptions aux services. Permission `departments.approve`.
- **Vidéos YouTube** (DS, RM, exercices) : lues dans l’application, pourcentage regardé, reprise là où l’on s’était
  arrêté ; titre et auteur détectés et préremplis partout où l’on colle un lien.
- Exercice **clôturé** : consultable seulement. **Responsables** : liste exportable en PDF.

### 11. Navigation instantanée et mise à jour silencieuse
- **Cadre persistant** : menu latéral, en-tête et barre du bas restent en place ; changer de page ne remplace que le
  contenu (plus de rechargement visible des menus). Fine barre de progression si une page met un instant à venir.
- **Pages déjà ouvertes** : affichées tout de suite avec leurs dernières données (gardées **en mémoire seulement**,
  jamais sur le disque, effacées à la déconnexion), puis mises à jour en silence. Plus de squelette ni de clignotement
  après une action (demandes, annonces, événements…). Les saisies en cours (FISS, RM) ne sont jamais écrasées.
- **Préchargement** : les pages se téléchargent en arrière-plan après la connexion (pages de gestion seulement pour les
  responsables ; rien en mode « économie de données ») ; les données d’une page partent dès le survol ou le toucher de
  son lien ; à l’ouverture de l’application, la page et les données de l’accueil partent en même temps que la
  vérification du compte. Le nombre de notifications non lues arrive avec le compte.
- Mesures en conditions de production (build, Laravel en mode production avec caches de configuration et de routes,
  OPcache, copie de la base) : réponses de l’API en 25 à 90 ms ; retour sur une page déjà vue en 33 à 113 ms sans
  squelette ; page ouverte après survol prête en 70 à 150 ms ; données de l’accueil prêtes à ~0,4 s après l’ouverture.

### 12. Temps réel
- Toute modification réussie rend le « pouls » suivant immédiatement à jour (plus d’attente du cache de 10 s) ; après
  chaque action de l’utilisateur, le pouls repasse aussitôt : les autres blocs et pages concernés se mettent à jour
  seuls. Les signaux techniques fréquents (lecture des vidéos, notifications lues, appareil) n’en déclenchent pas.
- Mise à jour automatique et silencieuse ajoutée sur : FISS (déverrouillage accordé), Service (demande acceptée),
  Nous contacter (réponse reçue), Demandes, Annonces, Événements, Horaires, Versets, Ressources, Exercices, Membres
  (première page), Tableau de gestion, et les blocs de l’accueil (FISS, aperçu, horaires, verset).

### 13. Écrans moyens (portable, tablette)
- FISS : notes côte à côte, questions de la vie intérieure sur deux colonnes quand la place le permet, barème de
  notation **en bas de la fiche**, en grille sur toute la largeur.
- Horaires des cultes : colonnes de lecture sans trous (une seule, pleine largeur, sur écran moyen).
- Fenêtre « Ressource » plus large, titre et auteur côte à côte. Aucun débordement de 320 à 1440 px.

### 14. Réglages de la plateforme (Gestion › Réglages)
- Rappels aux membres (FISS, Vertumètre, RM, DS, exercices, événements, programme du lendemain, anniversaires,
  profil incomplet, courriel, absences) et aux responsables (chiffres du mois, rapport mensuel, rapport des GEMs,
  relances de suivi) : chacun s’active ou se suspend d’un clic.
- Horaires et délais : rappel avant chaque culte (15–120 min), heure du programme du lendemain (16–21 h), jour
  d’ouverture du rapport mensuel des responsables (20–28). Limites : notifications push par heure et par personne
  (3–20), période d’accueil des nouveaux membres (7–90 jours).
- Chaque ligne indique sa valeur d’origine et se rétablit d’un clic ; rien n’est enregistré avant « Enregistrer » ;
  chaque modification est consignée au journal (au journal de sécurité privé pour le compte de gestion). Sans
  modification, tout fonctionne exactement comme avant. Les réglages de sécurité (validation des inscriptions, codes,
  droits) n’en font volontairement pas partie.
- Permission `settings.manage` : super administrateur, rôles qui gèrent les rôles (PA, Administrateur), compte de
  gestion.

### 15. Plateforme modulable et tâches faites pour les membres
- **Modules** (Réglages › Modules de la plateforme) : Vertumètre, RM, DS, Exercices, Rendez-vous, Ma vie spirituelle.
  Désactivé : retiré des menus, de l’accueil et du suivi, adresses fermées côté serveur, rappels arrêtés ; rien n’est
  effacé. Un changement de réglage s’applique en direct chez les personnes connectées.
- **Nouveaux réglages** : premier rappel de la FISS, rappel du DS, membre absent après N jours, inactif après N mois,
  relances (nouvel inscrit, demande sans réponse), relance et validation automatique des inscriptions aux services
  (0 = jamais, par défaut), renouvellement des RM oubliées le 15 (désactivé par défaut), délai avant de réinviter à
  activer les notifications.
- **Automatismes** : relance du responsable d’un service quand une inscription attend ; validation automatique si
  le pasteur la choisit ; RM du mois précédent reprises pour ceux qui n’ont rien écrit (si activé) ; FISS :
  « Reprendre mes réponses » du mois précédent en un clic.
- **Notifications** : tant qu’elles ne sont pas activées sur l’appareil, une invitation apparaît sur toutes les pages
  (« Activer » en un clic) ; « Plus tard » la repousse de quelques jours seulement. iPhone sans installation et
  notifications bloquées : explication en un clic.
- **Sécurité** : dépendances corrigées (laravel/framework 13.35, league/commonmark 2.10.3, league/flysystem 3.36 —
  0 faille connue) ; code SMS jamais affiché en production même si `EXPOSE_OTP` était laissé actif ; revue des droits
  de chaque route (administration et espace membre) ; données des nouvelles tables ajoutées à la fiche « impact » de
  suppression d’un compte.

### 16. Photos allégées, cadrage et Apparence (sans code)
- **Photo de profil** : à la sélection, la photo s’ouvre dans une fenêtre de cadrage (glisser pour placer le visage dans
  le cercle, pincer / molette / curseur pour zoomer, Pivoter, Recentrer). À la validation, elle est réduite sur le
  téléphone : 512 × 512, 180 Ko au plus (une photo de 48 Mpx est prête en moins d’une seconde) ; « Ajuster le cadrage »
  rouvre la fenêtre avant l’enregistrement. Même chose à l’inscription.
- **Annonces et événements** : l’image est allégée dès qu’elle est choisie (1600 px, 450 Ko au plus ; GIF gardés).
- **Serveur** (`App\Support\ImageOptimizer`) : chaque image envoyée est réouverte, orientée, réduite (photo 640 px,
  contenus 1600 px) et réenregistrée en JPEG sans métadonnées (position GPS…). Image de plus de 50 millions de pixels
  refusée avant ouverture (protection contre les « bombes de décompression »). L’ancienne photo n’est supprimée
  qu’une fois la nouvelle enregistrée.
- **Images déjà en ligne** : `php artisan app:images-optimize --dry-run` (compte), puis `php artisan app:images-optimize`
  (réduit les photos et images lourdes, met à jour leur chemin sans toucher à la date de modification, supprime
  l’ancien fichier).
- **Gestion › Apparence** (Pasteur Résident et compte de gestion, permission `settings.manage`) : image de l’accueil
  (fond du verset) avec cadrage et aperçus ordinateur / téléphone ; photos de l’écran de connexion (ajout, ordre,
  recadrage, retrait, 8 au plus) ; textes affichés (message sur l’écran de connexion, titre et message de bienvenue,
  message d’attente de validation). Tout revient à l’origine d’un clic ; chaque action est journalisée ; les
  écrans ouverts se mettent à jour seuls. Les données sont dans `platform_settings` (clé `appearance`) : aucune
  migration. Fichiers dans `storage/app/public/appearance`.
- **Limites de fréquence** : chaque limite de route a désormais son propre compteur (avant, une limite comme « 30 appels
  du protocole par minute » comptait tous les appels de la personne, et les essais de connexion d’une même adresse
  comptaient aussi les autres appels publics : risque de blocage injustifié, par exemple sur le Wi-Fi de l’église).

## Base de données (migration additive et réversible)
`2026_10_12_100001_permissions_by_module_and_platform_manager` :
- `users.platform_manager_at` (vide pour tous les comptes existants) ;
- table `manager_devices` (appareils de confiance, migration `2026_10_12_100002_create_manager_devices_table`) ;
- tables `security_logs` et `manager_activation_codes` ;
- `resolution_resources.status` (par défaut `published`) ;
- permissions du catalogue créées / renommées, attributions de compatibilité, suppression de `content.manage`.
- `2026_10_12_100003_create_department_join_requests_table` (inscriptions aux services, permission `departments.approve`) ;
- `2026_10_12_100004_create_resource_views_table` (lecture des vidéos des RM : `resource_views`, `resolution_resources.media_duration`) ;
- `2026_10_12_100005_create_platform_settings_table` (réglages, permission `settings.manage`) ;
- `2026_10_12_100006_restrict_platform_settings_permission` (réglages réservés au Pasteur Résident et au compte de gestion).
Retour arrière : `php artisan migrate:rollback --step=6` (recrée `content.manage` pour les rôles qui modifiaient les
versets, retire les nouvelles permissions, colonnes et tables). Aucune donnée de membre n'est modifiée.

## Mise en ligne (Hostinger)
1. Sauvegarde de la base (comme d'habitude).
2. Envoyer le code (backend et `frontend/dist` reconstruit : `npm run build`). Aucune nouvelle dépendance.
3. `.env` du serveur : ajouter `PLATFORM_MANAGER_PHONE=+22893471703` (facultatif : c'est la valeur par défaut ;
   le laisser vide désactive la fonction). Facultatif : `PLATFORM_MANAGER_ACTIVATION_TTL_HOURS=24`.
4. `php artisan migrate --force`
5. `php artisan config:clear && php artisan route:clear && php artisan cache:clear` (puis `config:cache` /
   `route:cache` si vous les utilisez).
6. Twilio : rien à faire pour le compte de gestion (il ne reçoit pas de code SMS).
7. **Autorisation du premier appareil** (par le propriétaire, en SSH sur le serveur) :
   `php artisan app:manager-activation-code` → transmettre le code affiché au détenteur du numéro, qui saisit son
   numéro sur la page de connexion habituelle, puis ce code une seule fois. Même commande pour chaque nouvel appareil.
   Vérifier ensuite : `php artisan app:platform-manager` (appareils de confiance, sessions).
8. Vérifications : connexion d'un membre, d'un PR (tous ses menus), du compte de gestion (Tableau de gestion,
   Ressources, Rôles) ; `php artisan app:security-log --limit=20` montre l'activation.

En cas de compromission : `php artisan app:platform-manager --revoke` (statut retiré, sessions fermées, appareils
retirés ; une nouvelle activation demandera un nouveau code), ou blocage du compte depuis la console de supervision.

## Tests
`php artisan test` : 301 tests, tous verts (SQLite et MySQL) (dont `PlatformManagerTest`, `PermissionsByModuleTest`, `ResourcesAndVideoLinksTest`,
`EmailTestBannerTest`, `PlatformSettingsTest`, `AppearanceAndImagesTest` et le test du pouls immédiat dans `RobustnessTest`), `tsc`, `eslint`,
`check-accents`, `check-api-routes` (vérifie aussi les adresses préchargées), `npm run build`. Essais dans le navigateur
sur une copie de la base (compte de gestion, Pasteur Résident, fidèle), mesures en conditions de production (build,
caches Laravel, OPcache) et contrôle automatique de débordement à 320, 375, 768, 960 et 1440 px : aucun débordement.
