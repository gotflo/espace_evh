# Mise à jour : rapports des responsables, annuaire, Samedi des miracles, nom de l'application

## Ce qui change

### 1. Rapport mensuel du patriarche et du responsable de département

**Questionnaire guidé** en 5 ou 6 étapes, puis une relecture avant l'envoi :

1. Rencontres d'échanges : nombre, thème(s) partagé(s), dynamique du groupe, cas d'incompréhension ou de recadrement ;
2. Activités menées : évangélisation, visites, appels, jeûne, drachme perdue retrouvée, fils prodigue ramené,
   brebis égarée retrouvée, autres — chaque activité cochée se résume en une ou deux phrases ;
3. Fonctionnement des GEMs (**rapport du patriarche uniquement**), avec la liste des GEMs de la tribu sous les yeux ;
4. Santé spirituelle : responsable(s) et membres, avec les chiffres du mois en repère (FISS remplies, score moyen) ;
5. Activités, programmes spéciaux ou innovations planifiés pour le mois à venir ;
6. **Âmes gagnées et intégrées** : le nombre et les **noms sont listés automatiquement** (nouveaux inscrits du mois
   dans la tribu, ou membres ayant rejoint le département) ; on peut ajouter des personnes pas encore inscrites.

- **Qui remplit** : le patriarche de la tribu ; les responsables du département (désignés dans Organisation).
- **Quand** : le rapport d'un mois s'ouvre le 25 de ce mois ; il peut être rempli ou corrigé jusqu'à la fin du mois
  suivant, puis il est figé.
- **Qui lit** : l'Assistant Pasteur de la tribu et les pasteurs (PR, PA) pour les tribus ; les pasteurs pour les
  départements. L'écran **Rapports mensuels** montre les rapports reçus et ceux qui manquent, mois par mois.
- **PDF** : un rapport envoyé s'exporte en PDF (bouton « PDF » en haut du rapport) ; chaque export est tracé dans le journal.
- **Rappels automatiques** : bandeau sur le tableau de bord, notification du 1er au 4, relance du 5 au 10.

### 2. Rapport hebdomadaire des Gardes

- Écran **Rapport de GEM** (menu Gestion du Garde) : pour chaque membre du GEM, deux cases — présent au **culte du
  dimanche**, présent à la **rencontre du GEM** — et un mot facultatif. Une minute suffit.
- Si un membre a déjà été pointé présent au culte dans **Présences**, sa case est précochée.
- À l'envoi, le rapport part **automatiquement au patriarche et à l'Assistant Pasteur** de la tribu (notification).
- **Quand** : le rapport d'une semaine (du lundi au dimanche) se remplit à partir de son dimanche et reste modifiable
  jusqu'au samedi suivant, puis il est figé.
- **Rappels** : le dimanche à partir de 18 h (ou le lundi matin), relance le mardi ou le mercredi.

### 3. Annuaire des responsables et fiche d'un Garde

- Écran **Responsables** :
  - **pasteurs (PR, PA)** : la liste des Assistants Pasteurs, des patriarches, des responsables de département et des
    Gardes, chacun avec son **appartenance** (tribus, départements, GEM) et son téléphone ; tribus sans patriarche et
    GEMs sans Garde signalés ;
  - **patriarches et AP** : les Gardes (et les patriarches) de **leurs** tribus uniquement.
  La liste se lit dans les rôles et l'organisation : rien à saisir, elle est toujours à jour.
- **Cliquer sur un Garde** ouvre sa fiche : rapports hebdomadaires envoyés sur les semaines attendues, taux de présence
  au culte et à la rencontre, FISS des membres du GEM, membres actifs/inactifs, présences de chaque membre, détail de
  chaque rapport, et son **activité de suivi** des 60 derniers jours (suivis enregistrés, appels faits, notes,
  demandes traitées, nouveaux accueillis). Le contenu des notes de suivi n'y est pas affiché.

### 4. Programme des cultes : Samedi des miracles

Nouveau rendez-vous hebdomadaire **« Samedi des miracles », chaque samedi de 18 h 30 à 20 h 30**, pour toute l'église :
calendrier, bloc « Nos rendez-vous », récapitulatif de la veille et rappel 30 minutes avant. Il se modifie comme les
autres dans **Événements** (horaire, lieu, fréquence).

### 5. FISS : réservées au patriarche, à l'AP et aux pasteurs

Un **Garde** (comme un responsable de département ou un accompagnateur) ne voit plus rien des FISS de ses membres :
ni le contenu des fiches, ni « FISS à remplir », ni le filtre « FISS manquante », ni le chiffre « FISS du mois » du
tableau de bord, ni la colonne FISS de sa propre fiche de Garde. Le patriarche et l'AP de la tribu, ainsi que les
pasteurs, gardent tout. Chaque membre voit toujours sa propre fiche.

### 6. Codes de connexion par SMS : Twilio Verify

Le branchement est terminé et testé avec un Twilio simulé : SMS en français, messages clairs en cas de numéro refusé
ou de panne, commande de diagnostic `php artisan app:sms-check`. Mise en service : README, « Connecter Twilio Verify ».

### 7. Nom de l'application : « My vasesdhonneur »

Titre, menu, page de connexion, bandeau « Installer My vasesdhonneur » et **nom inscrit à l'installation sur le
téléphone** (Android : `manifest.webmanifest` ; iPhone : `apple-mobile-web-app-title`).

## Fichiers à envoyer (dossier LARAVEL = `public_html/espace`)

```
app/Console/Commands/AutomationTick.php
app/Console/Commands/SmsCheck.php                              (nouveau)
app/Http/Controllers/Api/Admin/AuditLogController.php
app/Http/Controllers/Api/Admin/FissController.php
app/Http/Controllers/Api/Admin/GemReportController.php         (nouveau)
app/Http/Controllers/Api/Admin/LeaderDirectoryController.php   (nouveau)
app/Http/Controllers/Api/Admin/LeaderReportController.php      (nouveau)
app/Http/Controllers/Api/Admin/MemberController.php
app/Http/Controllers/Api/Admin/StatsController.php
app/Http/Controllers/Api/Admin/ValidationController.php
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/PulseController.php
app/Models/GemWeeklyReport.php                                 (nouveau)
app/Models/LeaderReport.php                                    (nouveau)
app/Models/User.php
app/Providers/AppServiceProvider.php
app/Services/FissService.php
app/Services/GemReportService.php                              (nouveau)
app/Services/LeaderDirectoryService.php                        (nouveau)
app/Services/LeaderReportService.php                           (nouveau)
app/Services/Notifier.php
app/Services/OtpService.php
app/Services/Sms/SmsSender.php
app/Services/Sms/TwilioVerifyClient.php                        (nouveau)
app/Services/Sms/TwilioVerifyException.php                     (nouveau)
app/Support/LeaderReportCatalog.php                            (nouveau)
app/Support/Recipients.php
config/services.php
database/migrations/2026_10_01_100001_create_leader_reports_table.php                  (nouveau)
database/migrations/2026_10_02_100001_create_gem_weekly_reports_table.php              (nouveau)
database/migrations/2026_10_02_100002_add_samedi_des_miracles_to_service_schedule.php  (nouveau)
routes/api.php
```

Et l'application compilée (`frontend/dist/`, obtenue par `npm run build`) dans **PUBLIC** (`espace/public`) :
`assets/` (fusionner), `manifest.webmanifest`, `sw.js`, puis `index.html` **en dernier**.

## Commandes (SSH, dans `public_html/espace`)

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

Les migrations créent deux tables (`leader_reports`, `gem_weekly_reports`) et ajoutent l'événement « Samedi des
miracles » : aucune donnée existante n'est modifiée. Ne **pas** relancer le seeder des rôles.

## Vérifier (10 min)

1. **Patriarche** : le tableau de bord affiche « Rapport de … à envoyer » ; remplir le questionnaire jusqu'à la relecture,
   puis envoyer. Dans **Responsables**, il voit les Gardes de sa tribu ; cliquer sur un Garde ouvre sa fiche.
2. **Garde** : menu **Rapport de GEM** ; cocher les présences, envoyer. Son patriarche et son AP reçoivent
   « Rapport de GEM reçu ».
3. **Pasteur (PR ou PA)** : **Responsables** liste AP, patriarches, responsables de département et Gardes ;
   **Rapports mensuels** liste « Reçu » / « Non reçu » ; ouvrir un rapport reçu puis **PDF**.
4. **Responsable de département** : même questionnaire mensuel, sans l'étape GEMs.
5. **Calendrier** : « Samedi des miracles » chaque samedi à 18 h 30.
6. **Nom à l'installation** : sur un téléphone où l'application n'est pas installée, « Installer » propose
   « My vasesdhonneur ». Sur un téléphone où elle est déjà installée : Android (Chrome) met en général le nom
   à jour de lui-même sous quelques jours — sinon, désinstaller puis réinstaller ; sur iPhone, supprimer l'icône puis
   refaire « Sur l'écran d'accueil ».

## À savoir

- **Modifier une question du rapport mensuel** : tout est dans `app/Support/LeaderReportCatalog.php` (libellés, choix,
  questions conditionnelles). L'application et le contrôle du serveur suivent automatiquement.
- **« Âmes gagnées » d'une tribu** : profils créés dans le mois et rattachés à la tribu (une arrivée par changement de
  tribu n'est pas comptée). La liste est figée au moment de l'envoi.
- **Départements** : seules les arrivées datées sont listées ; la date d'inscription à un service n'est enregistrée
  que depuis la mise à jour « calendrier et notifications » (fin septembre 2026).
- **Rapport de GEM et feuille de présence** : le rapport du Garde reprend les présences déjà pointées (cases précochées)
  mais n'écrit pas dans la feuille de présence ; l'assiduité des rapports statistiques reste calculée sur **Présences**.
- **Semaines attendues d'un Garde** : comptées depuis sa nomination (8 semaines au plus) et jamais avant la mise en
  service du rapport hebdomadaire.
- Un département sans responsable désigné n'apparaît pas dans les rapports mensuels attendus ; une tribu sans patriarche
  apparaît avec la mention « Aucun patriarche désigné ».

## Retour arrière

```sh
php artisan migrate:rollback --step=3
```
puis remettre les anciennes versions des fichiers ci-dessus (les rapports déjà envoyés seraient perdus : faire
un export SQL avant).
