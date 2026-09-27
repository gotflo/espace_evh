# Mise en ligne complète sur Hostinger (tout ce qui a été fait depuis le 20 septembre 2026)

Le site en ligne correspond au code du **20 septembre 2026** (commit `0528387`). Depuis, cinq évolutions ont été
faites et testées, mais rien n'a été envoyé sur Hostinger. Cette procédure met tout à jour **en une seule fois**,
sans avoir à comparer les fichiers un par un.

Durée : environ **45 minutes**. À faire un soir de semaine, en dehors des cultes et des rappels (pas le dimanche
matin ni le mercredi soir).

---

## Ce qui arrive en ligne

| Date | Évolution | Détail |
|---|---|---|
| 26 sept. | Évolution de la plateforme | rapports (graphiques, PDF), validations FISS et tribus, périmètres AP multi-tribus, famille (conjoint, enfants), journal d'audit, rôle « Responsable » remplacé, « GAD » → « Garde », départements corrigés |
| 26 sept. | Vidéos, cultes, sécurité | exercices vidéo YouTube avec suivi, horaires des cultes et rappels, tenue en charge, protections contre les injections |
| 26 sept. | Audit et robustesse | versets du tableau de bord administrables, rapport mensuel automatique, préférences de notifications, « Content de vous revoir », santé `/api/health`, performances à 5 000 membres, messages en français |
| 26 sept. | Charge et concurrence | accueil en un seul appel, corrections de doubles envois (FISS, contact, validations de tribu), pannes gérées, présentation sans emojis, accessibilité |
| 26 sept. | Derniers ajustements | liste des départements lisible à l'inscription, responsables de département choisis parmi toute l'église, question conjugale seulement pour les personnes mariées, boutons actifs seulement quand il y a une modification |

**Base de données** : 16 nouvelles migrations (listées en annexe). Elles ajoutent des tables et des colonnes ;
l'une restructure les rôles (GAD → Garde, rôle « Responsable » converti) et la liste des départements.
**La sauvegarde de l'étape 1 est donc indispensable.**

**Dépendances** : aucune nouvelle dépendance PHP (le dossier `vendor/` du serveur reste tel quel).

## Les deux fichiers à envoyer

Dans le dossier `deployment/` du projet (préparés et vérifiés) :

| Fichier | Contenu | Destination |
|---|---|---|
| `hostinger-backend-complet-20260926.zip` | tout le code Laravel : `app/`, `bootstrap/app.php`, `config/`, `database/`, `lang/` (nouveau), `resources/`, `routes/`, `public/.htaccess`, `artisan`, `composer.json`, `composer.lock` — sans `.env`, `vendor/`, `storage/` ni base locale | dossier **LARAVEL** |
| `hostinger-public-complet-20260926.zip` | l'application compilée : `index.html`, `sw.js`, `manifest.webmanifest`, `assets/`, `login/`, icônes | dossier **PUBLIC** |

Rappel : **LARAVEL** = le dossier qui contient `artisan`, `app`, `vendor`, `.env`
(`/home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace`) ; **PUBLIC** = son sous-dossier
`public/` (celui qui contient `index.php`, `index.html` et `assets/`).

## Étape 1 — Sauvegardes (10 min)

1. **Base de données** : hPanel → Bases de données → **phpMyAdmin** → cliquer sur la base → onglet **Exporter** →
   méthode rapide, format SQL → **Exporter**. Garder le fichier `.sql` sur votre ordinateur.
2. **Code actuel** : hPanel → **Gestionnaire de fichiers** → ouvrir `public_html` → clic droit sur le dossier
   `espace` → **Compresser** (zip) → télécharger l'archive obtenue, puis la supprimer du serveur.
3. Télécharger aussi séparément `espace/.env` et `espace/storage/app/webpush-vapid.json` (s'il existe).

Ne passez pas à l'étape 2 sans ces trois sauvegardes.

## Étape 2 — Envoyer le code Laravel (10 min)

1. Sur votre ordinateur, extraire `hostinger-backend-complet-20260926.zip`.
2. Gestionnaire de fichiers → ouvrir **LARAVEL** (`public_html/espace`) → **Téléverser** les dossiers et fichiers
   extraits en **remplaçant** l'existant quand la question est posée :
   `app`, `bootstrap/app.php`, `config`, `database`, `lang`, `resources`, `routes`, `artisan`, `composer.json`,
   `composer.lock`, et `public/.htaccess` (dans `espace/public/`).
   *Astuce* : téléverser le ZIP lui-même dans `espace`, puis clic droit → **Extraire** ici en acceptant de
   remplacer ; supprimer ensuite le ZIP du serveur.
3. **Ne pas toucher** : `.env`, `vendor/`, `storage/` (photos, journaux, clés push), `public/index.php`,
   `public/storage`, le `.htaccess` à la racine de `espace/`.

## Étape 3 — Compléter le fichier `.env` du serveur (2 min)

Gestionnaire de fichiers → `espace/.env` → **Modifier**. Vérifier ou ajouter ces lignes (remplacer si elles
existent déjà avec une autre valeur) :

```ini
APP_TIMEZONE=America/Toronto
APP_FALLBACK_LOCALE=en
LOG_STACK=daily
LOG_DAILY_DAYS=14
```

Ne rien changer d'autre (surtout pas `APP_KEY` ni les accès à la base). Enregistrer.

## Étape 4 — Mettre à jour la base et les caches (5 min)

Par **SSH** (hPanel → Avancé → **Accès SSH** → activer, puis copier la commande de connexion dans un terminal) :

```sh
cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan app:tick
php artisan migrate:status
```

`migrate:status` doit afficher toutes les migrations en **Ran**. `app:tick` doit se terminer sans ligne d'erreur.

> **Sans SSH** : hPanel → Avancé → **Tâches Cron** → ajouter une tâche « chaque minute » avec la commande
> `cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace && /usr/bin/php artisan optimize:clear && /usr/bin/php artisan migrate --force && /usr/bin/php artisan config:cache && /usr/bin/php artisan route:cache && /usr/bin/php artisan event:cache`
> attendre 2 minutes, vérifier l'étape 7 point 1, puis **supprimer cette tâche** (ne pas toucher à la tâche
> `schedule:run` existante).

## Étape 5 — Envoyer l'application (5 min)

1. Extraire `hostinger-public-complet-20260926.zip` sur votre ordinateur.
2. Gestionnaire de fichiers → **PUBLIC** (`espace/public`) → téléverser les dossiers `assets/` et `login/`
   (photos de l'écran de connexion) en fusionnant avec l'existant, puis les icônes, `manifest.webmanifest` et `sw.js`.
3. Téléverser **`index.html` en dernier** (il appelle les nouveaux fichiers de `assets/`).
4. Les anciens fichiers de `assets/` peuvent rester (inutilisés) ; les supprimer une semaine plus tard.

Les membres reçoivent la nouvelle version à leur prochaine ouverture de l'application (au besoin : fermer et
rouvrir l'application installée).

## Étape 6 — Réglages PHP dans hPanel (3 min)

hPanel → Sites web → Tableau de bord → Avancé → **Configuration PHP** → **Options PHP** :
`memory_limit` **256M**, `upload_max_filesize` **16M**, `post_max_size` **20M**, `exposePhp` **décoché**,
`logErrors` **coché**. Enregistrer.

## Étape 7 — Vérifications (10 min)

1. `https://espace.vasesdhonneurchicoutimi.org/api/health` → `"status":"ok"` (sinon attendre 5 minutes le passage
   du cron et recharger ; `"degraded"` avec `automation` en échec = vérifier la tâche Cron `schedule:run`).
2. Connexion avec votre téléphone → le code se remplit et la connexion se fait seule.
3. **Tableau de bord** : verset, rappel FISS, « Nos rendez-vous », 3 événements puis « Voir les autres ».
4. **Ma fiche (FISS)** : le bouton ne s'active qu'une fois toutes les réponses données ; « Situation conjugale »
   n'apparaît que pour une personne dont le profil indique « Marié(e) ».
5. **Mon profil** : « Enregistrer » grisé tant que rien n'est modifié ; famille (conjoint, enfants).
6. **Organisation** (PR/PA) → Départements → **Modifier** : rechercher un membre, le nommer responsable,
   case « Suivre la ponctualité aux répétitions ».
7. **Membres**, **Rapports** (PDF), **Validations**, **Versets** (ajouter un texte, le publier), **Journal d'audit**.
8. Inscription d'un nouveau compte (ou page Profil) : la liste des départements est lisible.
9. **Calendrier** : cultes du mercredi et du dimanche présents **une seule fois** (sinon étape 8).

## Étape 8 — Après la mise en ligne

- **Cultes en double** : si des cultes avaient été créés à la main comme événements récurrents, ils apparaissent
  deux fois : menu Événements → ouvrir l'ancien → Supprimer.
- **Responsables de départements** : les nommer dans Organisation → Départements → Modifier.
- **Surveillance** (gratuite) et **test de restauration** : voir [`A-FAIRE-DE-VOTRE-COTE.md`](A-FAIRE-DE-VOTRE-COTE.md),
  étapes 3 et 5.

## En cas de problème

- **Page blanche ou erreur** : lire le journal du jour `espace/storage/logs/laravel-AAAA-MM-JJ.log` (dernières lignes).
- **« validation.min.string » ou textes en anglais** : le dossier `lang/` n'a pas été envoyé (étape 2).
- **Application ancienne affichée** : `index.html` n'a pas été remplacé (étape 5) ou vider le cache du navigateur.
- **Erreur 500 juste après l'envoi** : relancer `php artisan optimize:clear` puis `php artisan config:cache`.

## Retour arrière complet (si nécessaire)

1. phpMyAdmin → la base → **Importer** le fichier `.sql` de l'étape 1 (il remet toutes les tables telles qu'avant).
2. Gestionnaire de fichiers → supprimer le contenu modifié et **extraire l'archive** `espace` de l'étape 1 à sa place
   (ou réenvoyer au moins `app`, `bootstrap/app.php`, `config`, `database`, `routes`, `public/.htaccess`,
   `public/index.html`, `public/assets`).
3. Remettre `.env` et `storage/app/webpush-vapid.json` sauvegardés.
4. `php artisan optimize:clear`, puis ouvrir le site.

## Annexe — les 16 migrations ajoutées

```
2026_09_25_100001_create_user_notifications_table
2026_09_25_100002_create_push_subscriptions_table
2026_09_25_100003_add_recurrence_to_events
2026_09_25_100004_add_welcome_and_service_tracking
2026_09_26_100001_add_broadcast_and_member_scoped_roles
2026_09_27_100001_create_audit_logs_table
2026_09_27_100002_create_publication_scopes_table
2026_09_27_100003_add_activity_tracking_to_users
2026_09_27_100004_add_fiss_locking_and_edit_requests
2026_09_27_100005_create_tribe_change_requests
2026_09_27_100006_create_family_links
2026_09_27_100007_restructure_roles_and_departments
2026_09_28_100001_add_service_schedule_to_events
2026_09_28_100002_add_video_tracking_to_exercises
2026_09_29_100001_create_dashboard_verses_table
2026_09_29_100002_add_notification_prefs_to_users
```

Si le serveur en avait déjà exécuté certaines, `migrate` ne lance que celles qui manquent.
