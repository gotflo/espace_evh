# Mise à jour : audit, robustesse, versets administrables, hébergement

Voir l'audit complet : [`AUDIT-2026-09.md`](AUDIT-2026-09.md).

## Ce qui change pour les membres et les responsables

- **Versets du tableau de bord** (menu Versets, PR et PA) : bibliothèque de textes, brouillon / publié, période
  d'affichage, rotation quotidienne dans l'ordre choisi, mise en avant, aperçu, historique. Le verset actuel
  (Actes 20.28) est repris tel quel.
- **« Content de vous revoir »** après plus de 4 jours d'absence : annonces, événements et exercices arrivés entre-temps.
- **Rapport mensuel** envoyé automatiquement le 1er du mois aux responsables (chiffres clés + lien vers le rapport).
- **Choisir mes notifications** (page Notifications) : couper le push d'une catégorie ; tout reste dans la cloche.
- **Hors ligne** : bandeau d'information ; les textes en cours (FISS, réponse à un exercice, message) sont
  conservés sur l'appareil jusqu'à l'envoi.
- **Listes des membres** paginées (50 par page ; 60 dans les rapports) avec recherche côté serveur.
- **Messages d'erreur en français** (ils affichaient parfois des codes comme « validation.min.string »).
- **Accessibilité** : focus clavier visible partout, lien « Aller au contenu », zones tactiles agrandies.
- Textes relus : accents corrigés partout.

## Ce qui change techniquement

| Sujet | Avant | Après |
|-------|-------|-------|
| Feuille de style principale | 533 Ko (drapeaux intégrés) | 112 Ko (21 Ko compressés) |
| Polices | Google Fonts (autre domaine, bloquant) | servies par le site |
| Chargement d'une page | Laravel démarre pour renvoyer `index.html` (0,65 s mesuré) | `index.html` servi directement par Apache |
| Fichiers `/assets` | cache 7 jours | cache 1 an (noms versionnés) |
| Fichier absent | page de l'application (200) | vrai 404 |
| En-tête `X-Powered-By` | version de PHP exposée | retiré |
| Destinataires des notifications automatiques | toute l'église chargée à chaque envoi | seulement les responsables, une fois par passage |
| Anniversaires / mariages du calendrier | tous les profils lus | seulement les mois affichés |
| Rapport église 12 mois (500 membres) | 1,4 s | 0,22 s |
| Journaux | un seul fichier sans limite | un fichier par jour, 14 jours |
| Surveillance | `/up` (démarrage seulement) | `/api/health` : base, cache, disque, automatismes |

## Fichiers

### Créés (backend)
```
app/Console/Commands/LoadTestUsers.php
app/Http/Controllers/Api/Admin/VerseController.php
app/Http/Controllers/Api/HealthController.php
app/Http/Controllers/Api/MyNotificationPrefsController.php
app/Http/Controllers/Api/MyWelcomeController.php
app/Models/DashboardVerse.php
app/Services/VerseService.php
database/migrations/2026_09_29_100001_create_dashboard_verses_table.php
database/migrations/2026_09_29_100002_add_notification_prefs_to_users.php
lang/fr/validation.php                     ← nouveau dossier « lang » à la racine du Laravel
```

### Modifiés (backend)
```
app/Console/Commands/AutomationTick.php
app/Http/Controllers/Api/Admin/AuditLogController.php
app/Http/Controllers/Api/Admin/MemberController.php
app/Http/Controllers/Api/Admin/ReportController.php
app/Http/Controllers/Api/AuthController.php
app/Http/Middleware/SecurityHeaders.php
app/Models/SpiritualHealthForm.php
app/Models/User.php
app/Services/CalendarService.php
app/Services/Notifier.php
app/Services/ReportService.php
app/Support/ProfileCompletion.php
app/Support/Recipients.php
database/seeders/RolesAndPermissionsSeeder.php
public/.htaccess                           ← important : à remplacer sur le serveur
routes/api.php
routes/web.php
```
(Tests, `phpunit.xml`, `.env.production.example` : dans le dépôt seulement.)

### Supprimés
Aucun. Dépendance npm `evh-site` (inutilisée, hors dépôt) retirée de `package.json`.

### Dépendances
Frontend : `@fontsource/inter`, `@fontsource/cormorant-garamond` (polices). Lancer `npm install` avant le build.
Aucune nouvelle dépendance Composer.

## Déploiement

1. **Sauvegarde** de la base (phpMyAdmin → Exporter) et du dossier Laravel (`app/`, `routes/`, `public/.htaccess`, `.env`).
2. Envoyer les fichiers backend ci-dessus, **dont `public/.htaccess` et le nouveau dossier `lang/`**.
3. `.env` du serveur, ajouter :
   ```ini
   LOG_STACK=daily
   LOG_DAILY_DAYS=14
   APP_FALLBACK_LOCALE=en
   ```
4. Sur le serveur :
   ```sh
   cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan event:cache
   php artisan app:tick
   ```
5. Application : `npm install && npm run build` en local, puis envoyer le contenu de `frontend/dist/` dans `public/`
   (remplacer `index.html`, `sw.js`, `assets/`). Les anciens fichiers de `assets/` peuvent être supprimés.
6. **hPanel → Avancé → Configuration PHP** (recommandé) :
   `memory_limit` **256M**, `upload_max_filesize` **16M**, `post_max_size` **20M**, `exposePhp` **décoché**,
   `logErrors` **coché**. Le reste (OPcache, `max_execution_time` 360) peut rester tel quel.
7. **Surveillance** (gratuite, recommandée) : un service comme UptimeRobot qui appelle toutes les 5 minutes
   `https://espace.vasesdhonneurchicoutimi.org/api/health` et vous prévient par courriel si la réponse n'est pas 200
   ou contient `"degraded"`.
8. **Événements** : si des cultes avaient été créés à la main comme événements récurrents avant l'ajout automatique
   des horaires (mise à jour précédente), les supprimer dans Événements pour éviter les doublons.

## Vérifications (10 min)

1. `https://espace.vasesdhonneurchicoutimi.org/api/health` → `"status":"ok"` (après le premier passage du cron).
2. Tableau de bord : verset affiché ; menu **Versets** (PR/PA) : ajouter un texte, le publier, il apparaît dans la liste.
3. Membres : 50 membres puis « Afficher plus ».
4. Notifications → Choisir mes notifications : décocher puis recocher une catégorie.
5. Couper le Wi-Fi du téléphone : bandeau « Vous êtes hors ligne » ; le rétablir : « Connexion rétablie ».
6. Test de vitesse hPanel : relancer (le premier affichage doit nettement baisser).

## Retour arrière

```sh
php artisan optimize:clear
php artisan migrate:rollback --step=2
```
Remettre les fichiers sauvegardés (dont l'ancien `public/.htaccess`) et l'ancien contenu de `public/`.
Les versets créés entre-temps et les préférences de notifications sont alors supprimés.
