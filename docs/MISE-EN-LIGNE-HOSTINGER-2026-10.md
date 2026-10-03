# Mise en ligne sur Hostinger — octobre 2026

Cette procédure envoie **tout le code actuel** (paquets complets) : elle fonctionne quel que soit l'état du site en
ligne. La base ne reçoit que les migrations qui lui manquent.

Durée : environ **45 minutes**. À faire un soir de semaine, en dehors des cultes et des rappels
(ni le dimanche matin, ni le mercredi soir, ni le samedi soir à cause du Samedi des miracles).

---

## Ce qui arrive en ligne

| Évolution | Détail |
|---|---|
| Rapports mensuels | questionnaire guidé du patriarche (tribu) et du responsable de département ; âmes gagnées listées automatiquement ; rapports reçus / manquants pour l'AP et les pasteurs ; export **PDF** |
| Rapport hebdomadaire des Gardes | présences au culte et à la rencontre du GEM, envoyé automatiquement au patriarche et à l'AP |
| Responsables | annuaire des AP, patriarches, responsables de département et Gardes avec leur appartenance ; fiche d'un Garde (comment il mène son GEM) |
| FISS | réservées au patriarche, à l'AP et aux pasteurs (plus visibles par les Gardes) |
| Programme des cultes | **Samedi des miracles**, chaque samedi de 18 h 30 à 20 h 30 |
| Nom de l'application | **My vasesdhonneur** (titre, menu, connexion, nom à l'installation sur le téléphone) |
| Connexion par SMS | **Twilio Verify** : vrais codes par SMS, en français, avec le nom de l'application |
| Notifications | les envois de test ne s'affichent plus dans la page Notifications (boutons retirés, anciens tests effacés) |
| Agent de supervision | API de contrôle pour la console séparée `evh_monitoring`, blocage de comptes, suivi des erreurs et lenteurs — détail : [`AGENT-SUPERVISION.md`](AGENT-SUPERVISION.md) |

**Base de données** : 6 nouvelles migrations (et celles d'avant qui manqueraient encore en ligne) :
`2026_10_01_100001_create_leader_reports_table`, `2026_10_02_100001_create_gem_weekly_reports_table`,
`2026_10_02_100002_add_samedi_des_miracles_to_service_schedule`, `2026_10_03_100001_add_blocking_to_users`,
`2026_10_03_100002_create_monitoring_telemetry_tables`, `2026_10_03_100010_remove_test_notifications`.
Elles ajoutent des tables, une colonne et un événement, et retirent les anciennes « Notification de test » ;
aucune autre donnée n'est modifiée.

**Dépendances** : aucune nouvelle dépendance PHP (le dossier `vendor/` du serveur reste tel quel).

## Les deux fichiers à envoyer

Dans le dossier `deployment/` du projet (préparés et vérifiés : aucun mot de passe, aucune clé, aucune base locale) :

| Fichier | Contenu | Destination |
|---|---|---|
| `hostinger-backend-complet-20261003.zip` | code Laravel : `app/`, `bootstrap/`, `config/`, `database/`, `lang/`, `resources/`, `routes/`, `public/.htaccess`, `artisan`, `composer.json`, `composer.lock` | dossier **LARAVEL** |
| `hostinger-public-complet-20261003.zip` | application compilée : `index.html`, `sw.js`, `manifest.webmanifest`, `assets/`, `login/`, icônes | dossier **PUBLIC** |

**LARAVEL** = `/home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace` (contient `artisan`,
`app`, `vendor`, `.env`) ; **PUBLIC** = son sous-dossier `public/` (contient `index.php`, `index.html`, `assets/`).

## Étape 1 — Sauvegardes (10 min)

1. **Base de données** : hPanel → Bases de données → **phpMyAdmin** → cliquer sur la base → **Exporter** →
   méthode rapide, format SQL → **Exporter**. Garder le fichier `.sql` sur l'ordinateur.
2. **Code actuel** : hPanel → **Gestionnaire de fichiers** → `public_html` → clic droit sur `espace` →
   **Compresser** (zip) → télécharger l'archive, puis la supprimer du serveur.
3. Télécharger séparément `espace/.env` et `espace/storage/app/webpush-vapid.json` (s'il existe).

Ne passez pas à l'étape 2 sans ces trois sauvegardes.

## Étape 2 — Envoyer le code Laravel (5 min)

1. Gestionnaire de fichiers → ouvrir **LARAVEL** (`public_html/espace`).
2. **Téléverser** `hostinger-backend-complet-20261003.zip` dans ce dossier.
3. Clic droit sur le zip → **Extraire** → dans le dossier courant (`espace`) → accepter de **remplacer** les fichiers.
4. Supprimer le zip du serveur.

**Ne pas toucher** : `.env`, `vendor/`, `storage/` (photos, journaux, clés push), `public/index.php`,
`public/storage`, le `.htaccess` à la racine de `espace/`.

## Étape 3 — Le fichier `.env` du serveur : Twilio (5 min)

Gestionnaire de fichiers → `espace/.env` → **Modifier**.

1. **Supprimer** les anciennes lignes si elles existent : `TWILIO_SID=`, `TWILIO_TOKEN=`, `TWILIO_FROM=`.
2. **Ajouter ou remplacer** ces lignes (les valeurs sont celles de votre fichier local `backend/.env`, ouvrez-le dans
   un éditeur et copiez-les une par une — même compte Twilio) :

```ini
SMS_DRIVER=twilio_verify
TWILIO_ACCOUNT_SID=AC...
TWILIO_API_KEY_SID=SK...
TWILIO_API_KEY_SECRET=...
TWILIO_VERIFY_SERVICE_SID=VA...
TWILIO_VERIFY_TEMPLATE_SID=HJ4d9c5db569029bedab5b28ab79f4cc8d
EXPOSE_OTP=false
```

Pour l'agent de supervision, ajouter aussi `MONITOR_AGENT_SECRET=` (le même secret que dans le `.env` de la
console `evh_monitoring` ; laissé vide, l'API de contrôle reste fermée) — voir [`AGENT-SUPERVISION.md`](AGENT-SUPERVISION.md).

3. Vérifier aussi (sans les changer s'ils sont déjà là) : `APP_TIMEZONE=America/Toronto`, `LOG_STACK=daily`,
   `LOG_DAILY_DAYS=14`.
4. Ne rien changer d'autre (surtout pas `APP_KEY` ni les accès à la base). **Enregistrer.**

Le secret de la clé API ne doit être que dans ce fichier `.env` : ni dans un message, ni dans un fichier du projet.

## Étape 4 — Base de données, caches et contrôle de Twilio (5 min)

Par **SSH** (hPanel → Avancé → **Accès SSH** → copier la commande de connexion dans un terminal) :

```sh
cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan migrate:status
php artisan app:sms-check
```

Résultats attendus :
- `migrate:status` : toutes les migrations en **Ran**, dont les six `2026_10_...` ;
- `app:sms-check` : « Mode : twilio_verify », « Connexion à Twilio Verify : OK », « Modèle du SMS : présent »,
  « Longueur du code : 6 ». En cas d'échec, la commande indique quoi corriger dans le `.env` ; après toute
  correction du `.env`, relancer `php artisan config:cache`.

Facultatif, un vrai SMS de test vers votre téléphone : `php artisan app:sms-check +14187181876`.

> **Sans SSH** : hPanel → Avancé → **Tâches Cron** → ajouter une tâche « chaque minute » avec la commande
> `cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace && /usr/bin/php artisan optimize:clear && /usr/bin/php artisan migrate --force && /usr/bin/php artisan config:cache && /usr/bin/php artisan route:cache && /usr/bin/php artisan event:cache`
> attendre 2 minutes, faire l'étape 6 point 1, puis **supprimer cette tâche** (ne pas toucher à la tâche
> `schedule:run` existante). Le contrôle de Twilio se fait alors par une connexion réelle (étape 6 point 2).

## Étape 5 — Envoyer l'application (5 min)

1. Extraire `hostinger-public-complet-20261003.zip` sur votre ordinateur.
2. Gestionnaire de fichiers → **PUBLIC** (`espace/public`) → téléverser les dossiers `assets/` et `login/` en
   **fusionnant** avec l'existant, puis les icônes, `manifest.webmanifest` et `sw.js` (remplacer).
3. Téléverser **`index.html` en dernier**.
4. Les anciens fichiers de `assets/` peuvent rester ; les supprimer une semaine plus tard.

## Étape 6 — Vérifications (10 min)

1. `https://espace.vasesdhonneurchicoutimi.org/api/health` → `"status":"ok"` (sinon attendre 5 minutes le passage du
   cron et recharger).
2. **Connexion** sur votre téléphone : le SMS arrive (« Votre code de vérification pour My vasesdhonneur… »), la
   connexion se fait. Le code ne doit **pas** s'afficher à l'écran.
3. **Nom** : l'onglet du navigateur affiche « My vasesdhonneur » ; le menu aussi.
4. **Calendrier** : « Samedi des miracles » chaque samedi à 18 h 30 (une seule fois).
5. **Pasteur (PR ou PA)** : menu **Responsables** (AP, patriarches, responsables de département, Gardes) et
   **Rapports mensuels** (tribus et départements, « Reçu » / « Non reçu »).
6. **Patriarche** : bandeau « Rapport de septembre 2026 à envoyer » ; menu **Responsables** → ses Gardes → fiche.
7. **Garde** : menu **Rapport de GEM** ; aucune trace des FISS de ses membres (ni tuile, ni filtre, ni colonne).

## Étape 7 — Après la mise en ligne

- **Nommer les responsables** pour que les rapports leur parviennent : patriarches (rôle Patriarche sur la tribu),
  Gardes (page GEMs), responsables de département (Organisation → Départements).
- **Prévenir les responsables** : rapport mensuel à remplir du 1er au 10 (patriarches, responsables de département),
  rapport de la semaine chaque dimanche soir (Gardes).
- **Application déjà installée sur iPhone** : pour voir le nouveau nom sous l'icône, la supprimer puis refaire
  « Sur l'écran d'accueil ». Sur Android, le nom se met à jour de lui-même.
- **Twilio** : surveiller le solde (recharge automatique activée) et les journaux d'envoi (console Twilio →
  Monitor → Logs). Si l'église est enregistrée (NEQ), le profil Twilio individuel peut être converti en profil
  d'organisation.

## En cas de problème

- **« L'envoi du code par SMS est momentanément indisponible »** : `php artisan app:sms-check` indique la cause ;
  détail dans `espace/storage/logs/laravel-AAAA-MM-JJ.log` (lignes « OTP : envoi Twilio Verify impossible »).
  **Dépannage immédiat** (personne ne peut se connecter) : remettre `SMS_DRIVER=log` et `EXPOSE_OTP=true` dans le
  `.env`, puis `php artisan config:cache` — le code s'affiche alors à l'écran, le temps de corriger.
- **Page blanche ou erreur 500** : `php artisan optimize:clear` puis `php artisan config:cache` ; sinon lire le
  journal du jour.
- **Ancienne application affichée** : `index.html` n'a pas été remplacé (étape 5), ou fermer et rouvrir l'application.

## Retour arrière complet

1. phpMyAdmin → la base → **Importer** le fichier `.sql` de l'étape 1.
2. Gestionnaire de fichiers → remettre le dossier `espace` à partir de l'archive de l'étape 1.
3. Remettre `.env` et `storage/app/webpush-vapid.json` sauvegardés.
4. `php artisan optimize:clear`, puis ouvrir le site.

Retour arrière des seules migrations de cette mise à jour : `php artisan migrate:rollback --step=6` (les rapports
déjà envoyés seraient perdus).
