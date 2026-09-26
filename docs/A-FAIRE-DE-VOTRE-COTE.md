# Ce qui reste à faire de votre côté (pas à pas)

Ces étapes demandent un accès que seule l'équipe possède (hPanel Hostinger, serveur, questionnaires).
Comptez environ 1 h 30 en tout, en plusieurs fois. Cochez au fur et à mesure.

| # | Étape | Durée | Quand |
|---|-------|-------|-------|
| 1 | Mettre en ligne la mise à jour | 30 min | dès que possible |
| 2 | Régler PHP dans hPanel | 5 min | juste après |
| 3 | Brancher la surveillance (UptimeRobot) | 10 min | juste après |
| 4 | Supprimer les cultes en double | 5 min | juste après |
| 5 | Tester une restauration de sauvegarde | 20 min | dans la semaine |
| 6 | Test de charge sur une copie de test | 30 min | un soir calme |
| 7 | Envoyer les questionnaires du Vertumètre | — | quand ils sont prêts |
| 8 | Relancer le test de vitesse | 2 min | après l'étape 1 |

---

## 1. Mettre en ligne la mise à jour

Suivre [`MISE-A-JOUR-AUDIT-ROBUSTESSE.md`](MISE-A-JOUR-AUDIT-ROBUSTESSE.md) (liste des fichiers, commandes,
vérifications, retour arrière). En résumé :

1. **Sauvegarde** : hPanel → Bases de données → phpMyAdmin → votre base → **Exporter** → Exécuter.
   Garder aussi une copie de `.env` et de `storage/app/webpush-vapid.json` (Gestionnaire de fichiers → clic droit → Télécharger).
2. **Envoyer les fichiers** du dossier `backend/` indiqués dans la procédure (Gestionnaire de fichiers ou FTP),
   dont `public/.htaccess` et le dossier `lang/`.
3. **Ajouter au `.env` du serveur** :
   ```ini
   LOG_STACK=daily
   LOG_DAILY_DAYS=14
   APP_FALLBACK_LOCALE=en
   ```
4. **Lancer les commandes** (SSH, voir l'encadré ci-dessous) :
   ```sh
   cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan event:cache
   php artisan app:tick
   ```
5. **Application** : sur votre ordinateur, dans `frontend/` : `npm install` puis `npm run build` ; envoyer le contenu
   de `frontend/dist/` dans `public/` (remplacer `index.html`, `sw.js` et `assets/`).
6. Ouvrir `https://espace.vasesdhonneurchicoutimi.org/api/health` : la page doit afficher `"status":"ok"`.

> **Sans SSH ?** hPanel → Avancé → **Accès SSH** → Activer, puis copier la commande de connexion affichée.
> À défaut, créer une tâche Cron temporaire (hPanel → Avancé → Tâches Cron) avec la commande
> `/usr/bin/php /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace/artisan migrate --force`,
> attendre une minute, puis la **supprimer**. Même principe pour les autres commandes.

## 2. Régler PHP dans hPanel

hPanel → Sites web → **Tableau de bord** → Avancé → **Configuration PHP** → onglet **Options PHP** :

| Réglage | Actuel | À mettre | Pourquoi |
|---------|--------|----------|----------|
| `memory_limit` | 2048M | **256M** | une requête qui s'emballe ne peut plus bloquer la mémoire des autres |
| `upload_max_filesize` | 2048M | **16M** | photos de profil : 5 Mo maximum côté application |
| `post_max_size` | 2048M | **20M** | même raison |
| `exposePhp` | coché | **décoché** | ne pas afficher la version de PHP aux visiteurs |
| `logErrors` | décoché | **coché** | garder la trace des erreurs PHP graves |

Enregistrer. Le reste (OPcache, `max_execution_time` 360) peut rester tel quel.

## 3. Brancher la surveillance (gratuit)

1. Créer un compte sur [uptimerobot.com](https://uptimerobot.com) (offre gratuite).
2. **Add New Monitor** → type **HTTP(s) - Keyword**.
3. URL : `https://espace.vasesdhonneurchicoutimi.org/api/health`
4. Keyword : `"status":"ok"` → alerte **si le mot-clé est absent** (« Keyword not exists »).
5. Intervalle : 5 minutes. Contact d'alerte : l'adresse courriel du responsable technique.

Vous serez prévenu si la base est injoignable, si le disque est presque plein ou si les automatismes
(rappels, anniversaires) ne tournent plus depuis 20 minutes.

## 4. Supprimer les cultes en double

Les horaires des cultes (mercredi, dimanche) sont maintenant ajoutés automatiquement. Si des cultes avaient été créés
à la main comme événements récurrents, ils apparaissent deux fois : menu **Événements** → ouvrir l'ancien culte →
**Supprimer**. Vérifier ensuite le calendrier de la semaine.

## 5. Tester une restauration de sauvegarde

Une sauvegarde n'est fiable qu'une fois sa restauration essayée. Sans toucher au site :

1. hPanel → Bases de données → **Gestion** → créer une base `…_evh_test` avec son utilisateur (noter le mot de passe).
2. phpMyAdmin → choisir la nouvelle base → **Importer** → le fichier SQL exporté à l'étape 1 → Exécuter.
3. Vérifier dans l'onglet SQL :
   ```sql
   SELECT COUNT(*) FROM users;
   SELECT COUNT(*) FROM spiritual_health_forms;
   SELECT MAX(created_at) FROM user_notifications;
   ```
   Les nombres doivent correspondre à la base réelle (même requêtes sur l'autre base).
4. Essayer aussi une sauvegarde automatique Hostinger : hPanel → Fichiers → **Sauvegardes** → Bases de données →
   télécharger la plus récente et l'importer de la même façon dans `…_evh_test` (vider la base avant).
5. Noter la date du test dans le README (section Maintenance). Garder la base `…_evh_test` pour l'étape 6.

## 6. Test de charge sur une copie de test

Mesure réelle sur l'hébergement Hostinger (sur mon ordinateur, les résultats ne sont qu'une estimation, voir l'audit).
La copie de test partage le processeur du site : la lancer **un soir calme** (ex. un mardi vers 23 h).

**Préparer la copie** (une fois) :

1. hPanel → Domaines → **Sous-domaines** → créer `test` (donne `test.vasesdhonneurchicoutimi.org`).
2. Copier le dossier Laravel `espace` dans le dossier du sous-domaine (Gestionnaire de fichiers → Copier, ou SSH `cp -r`).
3. Dans le `.env` **de la copie** :
   ```ini
   APP_URL=https://test.vasesdhonneurchicoutimi.org
   DB_DATABASE=…_evh_test
   DB_USERNAME=…
   DB_PASSWORD=…
   SMS_DRIVER=log
   WEBPUSH_ENABLED=false
   AUTO_TICK=false
   ```
   Ces trois dernières lignes sont **indispensables** : la copie contient les vrais membres, elle ne doit envoyer
   ni SMS ni notification. Ne **pas** créer de tâche Cron pour la copie.
4. Dans la copie : `php artisan config:cache`.

**Lancer le test** :

1. Dans la copie : `php artisan app:load-test-users 500 --force` (crée 500 comptes de test, numéros `+1999555…`).
2. Télécharger `storage/app/load-test-tokens.txt` de la copie sur votre ordinateur, à côté du projet.
3. Sur votre ordinateur, à la racine du projet :
   ```sh
   node scripts/load/charge.mjs --base https://test.vasesdhonneurchicoutimi.org --tokens load-test-tokens.txt --mode realiste --out realiste.json
   node scripts/load/charge.mjs --base https://test.vasesdhonneurchicoutimi.org --tokens load-test-tokens.txt --mode pic --out pic.json
   ```
   (15 min puis 3 min. Le mode `capacite` pousse le serveur à sa limite : à éviter sur l'hébergement partagé.)
4. **Nettoyer** : dans la copie `php artisan app:load-test-users --delete --force`, puis supprimer
   `load-test-tokens.txt` (sur le serveur et sur votre ordinateur).
5. Envoyer `realiste.json` et `pic.json` : ils contiennent seulement des temps de réponse et des taux d'erreur.

## 7. Questionnaires du Vertumètre

Pour que les membres remplissent eux-mêmes le Vertumètre, il me faut :

- la liste des questions (texte exact) ;
- pour chaque question : type de réponse (note sur 20, oui/non, choix, texte libre) ;
- la fréquence (chaque mois comme la FISS ? chaque trimestre ?) ;
- le calcul du score (moyenne simple, pondération) ;
- qui voit le détail des réponses (patriarche, AP, pasteurs) et qui ne voit que le score ;
- si les notes actuelles des responsables sont conservées à côté, ou remplacées.

## 8. Relancer le test de vitesse

hPanel → Sites web → Tableau de bord → **Performance / Vitesse de la page** → relancer sur mobile.
Avant : score 83, premier affichage (FCP) 3,4 s. Les polices servies par le site, la feuille de style allégée
et `index.html` servi sans PHP doivent faire nettement baisser le premier affichage.

---

Une fois ces étapes faites, envoyez-moi : la réponse de `/api/health`, les fichiers `realiste.json` et `pic.json`,
le nouveau score de vitesse et les questionnaires du Vertumètre.
