# Mise à jour : exercices vidéo, horaires des cultes, tenue en charge et sécurité

## Ce qui change

1. **Horaires des cultes** ajoutés au calendrier de toute l'église (rendez-vous hebdomadaires) :
   - mercredi 19 h 30 – 21 h 30 : Mercredi de l'intercession ;
   - dimanche 8 h 30 : Temps de prière & d'intercession · 9 h 30 : Culte de contemplation et de célébration ·
     12 h 30 : Healing Time · 13 h 00 : Bloom Light · Coin cocktail fraternel.
   - Carte **« Nos rendez-vous »** sur le tableau de bord.
   - **Rappels** : un seul message la veille à partir de 18 h (programme complet du lendemain), puis un rappel à
     tous **30 minutes avant chaque rendez-vous**. Un membre qui a indiqué « Absent » n'est pas relancé.
   - Tout événement récurrent peut devenir un rendez-vous régulier (case à cocher dans l'éditeur d'événement).
   - Lieu indiqué : « 70, rue Racine Est, Chicoutimi » (modifiable depuis Événements).
2. **Exercices vidéo YouTube** (Exercices → Nouvel exercice → Vidéo YouTube) :
   - lien YouTube collé → titre et miniature récupérés, vérification que la vidéo est lisible dans l'application ;
   - consignes, réponse écrite facultative, **date et heure limites**, portée selon le rôle (tribus, GEM, départements, église) ;
   - la vidéo se lit **dans l'application** ; le suivi mesure ce qui est réellement regardé : avancer la vidéo ne compte
     pas, les passages sautés sont signalés au responsable (« a avancé la vidéo 2 fois, 4 min sautées ») ;
   - notifications nommant précisément l'exercice : à la publication, 2 jours après s'il n'est pas fait,
     la veille de la fermeture, puis 3 h avant (8 h – 21 h seulement) ;
   - à l'heure limite l'exercice **se ferme automatiquement** (plus de visionnage ni de réponse) et l'auteur reçoit le bilan ;
   - écran **Suivi** par exercice : terminé / en cours / pas commencé / a avancé la vidéo, pourcentage vu, réponse.
   - Côté fidèle : nouvelle page **Mes exercices** (menu), reprise de la lecture là où il s'était arrêté.
3. **Tenue en charge (200 à 500 membres connectés)** :
   - un seul appel léger toutes les ~45 s par appareil (« pouls ») remplace les rafraîchissements de chaque écran
     (environ 8 fois moins de requêtes) ; chaque écran ne se recharge que si ses données ont changé ;
   - notifications push envoyées **en parallèle par lots de 20** (500 appareils en quelques secondes) ;
   - « dernière utilisation » des sessions écrite au plus toutes les 5 minutes (au lieu de chaque requête) ;
   - rapport annuel 2,3 fois plus rapide ; noms des groupes chargés une fois par requête dans les listes ;
   - demandes simultanées (double clic, réseau qui renvoie) : réponse propre, jamais d'erreur serveur ; les limites
     « 2 demandes de modification de FISS » et « une demande de changement de tribu » sont protégées par un verrou ;
   - application : écran de secours au lieu d'une page blanche, rechargement automatique après une mise en ligne,
     nouvelles tentatives automatiques des lectures si le réseau ou le serveur hésite, délai maximal de 25 s.
4. **Sécurité (injections)** : audit complet, voir la section Sécurité du README. Ajouts : politique de sécurité du
   contenu (CSP) dans le build, recherches protégées contre les jokers `%` et `_`, flux d'agenda (iCal) protégé
   contre l'injection de lignes, lien d'une notification push limité au site, erreur 500 corrigée pour les visiteurs
   non connectés qui appelaient l'API sans en-tête JSON.

## Fichiers

### Créés (backend)
```
app/Http/Controllers/Api/PulseController.php
app/Models/ExerciseVideoView.php
app/Models/PersonalAccessToken.php
app/Services/ExerciseProgress.php
app/Support/Like.php
app/Support/ScopeNames.php
app/Support/YouTube.php
database/migrations/2026_09_28_100001_add_service_schedule_to_events.php
database/migrations/2026_09_28_100002_add_video_tracking_to_exercises.php
```
(Tests : `tests/Feature/InjectionTest.php`, `LoadProfileTest.php`, `RobustnessTest.php`, `ServiceScheduleTest.php`,
`VideoExercisesTest.php` — non nécessaires sur le serveur.)

### Modifiés (backend)
```
app/Console/Commands/AutomationTick.php
app/Http/Controllers/Api/Admin/AuditLogController.php
app/Http/Controllers/Api/Admin/EventController.php
app/Http/Controllers/Api/Admin/ExerciseController.php
app/Http/Controllers/Api/Admin/MemberController.php
app/Http/Controllers/Api/CalendarController.php
app/Http/Controllers/Api/MyEventController.php
app/Http/Controllers/Api/MyExerciseController.php
app/Http/Controllers/Api/MyNotificationController.php
app/Http/Middleware/TriggerAutomation.php
app/Models/Concerns/HasPublicationScopes.php
app/Models/Event.php
app/Models/Exercise.php
app/Providers/AppServiceProvider.php
app/Services/CalendarService.php
app/Services/FamilyService.php
app/Services/FissService.php
app/Services/Notifier.php
app/Services/Push/WebPush.php
app/Services/ReportService.php
app/Services/TribeChangeService.php
bootstrap/app.php
routes/api.php
```

### Supprimés
Aucun fichier du backend. Application : `src/hooks/useAutoRefresh.ts` (remplacé par `src/pulse.ts`).

### Dépendances
Aucune nouvelle dépendance (ni Composer, ni npm).

## Déploiement

1. **Sauvegarde** de la base (phpMyAdmin → Exporter) et des dossiers `app/`, `bootstrap/`, `routes/`, `database/`.
2. Envoyer les fichiers backend ci-dessus (ou remplacer `app/`, `bootstrap/app.php`, `routes/`, `database/migrations/`).
3. Sur le serveur :
   ```sh
   cd /chemin/du/dossier/qui/contient/artisan
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan event:cache
   php artisan app:tick
   ```
   Les trois commandes `*:cache` accélèrent nettement chaque requête. **Après toute modification du `.env`**,
   relancer `php artisan config:cache`. En cas de doute : `php artisan optimize:clear` revient au mode sans cache.
4. Application : `npm run build` en local, puis envoyer le contenu de `frontend/dist/` dans `public/`
   (remplacer `index.html`, `sw.js`, `assets/`). L'`index.html` contient désormais la politique de sécurité (CSP).
5. Cron inchangé (`php artisan schedule:run` chaque minute), indispensable pour des rappels de culte à l'heure.

Aucune variable d'environnement nouvelle.

## Vérifications (10 min)

1. Tableau de bord : carte « Nos rendez-vous » (mercredi et dimanche).
2. Calendrier : les rendez-vous apparaissent chaque semaine.
3. Exercices → Nouvel exercice → Vidéo YouTube : coller un lien, la miniature et le titre apparaissent ; publier
   pour une tribu ; un membre de cette tribu reçoit la notification et lit la vidéo dans « Mes exercices ».
4. Exercices → Suivi : le pourcentage regardé s'affiche ; avancer la vidéo est signalé.
5. Rapports → PDF : le téléchargement fonctionne toujours.

## Retour arrière

```sh
php artisan optimize:clear
php artisan migrate:rollback --step=2
```
Puis remettre les fichiers sauvegardés et l'ancien `public/`. Le retour arrière supprime les rendez-vous de culte
créés par la migration, les colonnes vidéo et le suivi de visionnage (les exercices classiques sont conservés).
