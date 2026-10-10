# Mise à jour : Mes RM, Mon devoir (DS), Vertumètre, rendez-vous avec le pasteur, suppression d'un membre, FISS « Vie intérieure », tribu obligatoire

> Préparée en octobre 2026, **pas encore en ligne**. À déployer seulement après le feu vert.

## Ce qui change

### 1. Horaires des cultes
- Dimanche : le programme commence à **8 h 45** (temps de prière et d'intercession, auparavant 8 h 30).
- Mercredi de l'intercession : **plus d'heure de fin** affichée (« 19h30 »).
- Nouvel écran **Horaires des cultes** (menu Gestion, PR et PA) : jour, début, fin facultative, intitulé.
  On peut prévenir toute l'église d'un changement. Lien « Modifier » sur le bloc « Nos rendez-vous » du tableau de bord.
- Un horaire déjà modifié à la main n'est **jamais** écrasé par la migration.

### 2. Enfants : date de naissance complète
- Le profil demande la **date complète** (jour, mois, année) au lieu de l'année.
- Les enfants déjà saisis gardent leur année ; le parent voit « Né(e) en 2014 : précisez le jour et le mois ».
- Le responsable voit « 3 mai 2014 · 12 ans ».
- Le taux de complétion du profil demande désormais la date de chaque enfant : les parents concernés recevront le
  rappel habituel de profil incomplet (toutes les 2 semaines au plus).

### 3. Inscription : tribu obligatoire
- La tribu se choisit dans une grille de boutons ; impossible de terminer l'inscription sans elle.
- Tant que l'inscription n'est pas terminée, l'application reste sur l'écran d'inscription et la personne
  **n'apparaît chez aucun responsable** (listes, fiche, statistiques, rapports).
- **Membres déjà inscrits sans tribu** : à leur prochaine ouverture, l'écran « Une dernière étape : choisissez votre
  tribu » s'ouvre directement sur le choix ; leurs données sont conservées. Les responsables de la tribu reçoivent
  « X a rejoint la tribu Y » (et non « Nouveau membre »). Chaque compte concerné est tracé dans le journal d'audit.
- Exemptés (tribu facultative) : PR, PA, Assistants Pasteurs (qui suivent plusieurs tribus) et Administrateur.

### 4. Verset du tableau de bord illustré
- Photo de l'église en fond (fichiers `public/verset/fond-800.jpg` et `fond-1600.jpg`, 63 Ko et 196 Ko).
- Téléphone : la photo en bandeau en haut, le texte sur la partie assombrie. Ordinateur : texte à gauche, scène à droite.
- La taille du texte s'adapte à sa longueur ; un texte très long est replié avec « Lire la suite ».
- Pour changer la photo : remplacer les deux fichiers (mêmes noms), largeur 800 et 1600 px.

### 5 à 8. Mes RM (Résolutions du Mois)
- Menu **Mes RM**, juste après « Ma fiche (FISS) », avec Job 22:28 en tête.
- **Bilan** du mois passé (atteinte, en partie, pas encore) pour chaque résolution, au début du mois.
- Rubriques : méditation, prière, jeûne, sanctification, assiduité aux cultes, autres résolutions.
- **« Je renouvelle »** : bouton par rubrique, bouton « Je renouvelle mes résolutions de … » pour tout reprendre,
  ou la phrase écrite en toutes lettres (elle est remplacée par la résolution du mois précédent).
- **Selon le FISS** : chaque rubrique affiche le repère de la dernière FISS (ex. « FISS 8/20 · à fortifier ») et,
  pour l'assiduité, les présences pointées aux cultes.
- **Ressources** (méditation, prière, jeûne, culte) : lien ou vidéo YouTube avec le **nom de l'auteur**, ajoutées
  par le PR dans **Devoirs et RM › Ressources RM** (titre et auteur remplis tout seuls depuis YouTube).
- Modifiables tout le mois ; brouillon gardé sur le téléphone.
- Rappels : du 1er au 3 (dès 9 h) invitation, du 8 au 12 (dès 10 h) une relance, seulement à ceux qui ne les ont pas prises.

### 9 et 10. Mon devoir (DS)
- Le PR publie, modifie ou retire une **vidéo** (YouTube lue dans l'application, ou autre lien) ou un
  **audio** (fichier MP3, M4A, WAV, OGG jusqu'à 40 Mo, ou lien) dans **Devoirs et RM › Devoirs**.
- Le membre : écoute ou regarde, écrit son **Résumé** et ses **Actions et résolutions**, avec le rappel de prier et de se
  recommander à Dieu, source de force par sa grâce ; ce rappel s'affiche aussi après l'enregistrement.
- **Même suivi de lecture que les exercices vidéo**, pour la vidéo comme pour l'audio : passages réellement lus,
  avances rapides détectées (et non comptées), vitesse de lecture, reprise là où l'on s'était arrêté, contrôle du
  serveur (pas plus de lecture que le temps écoulé). Un devoir est **fait** quand le résumé est envoyé **et** que la
  vidéo ou l'audio a été lu en entier (95 %). Le membre peut écrire son résumé avant la fin ; il voit « Résumé envoyé ·
  écoute à terminer ». Un devoir publié par simple lien externe ne se mesure pas : le résumé suffit.
- Les responsables voient, pour chaque membre, le pourcentage lu, les avances rapides et la vitesse, comme pour les exercices.
- Changer la vidéo ou l'audio d'un devoir remet la lecture à zéro (une simple correction du titre la garde).
- Notification à la publication (facultative) ; une relance 3 jours après à ceux qui ne l'ont pas fait
  (résumé manquant ou lecture non terminée). Ouvrir « Mon devoir » marque la notification comme lue.
- « Content de vous revoir » (après quelques jours d'absence) annonce aussi les nouveaux devoirs.
- Un devoir retiré disparaît pour les membres mais leurs réponses restent dans leur historique.

### Qui voit les RM et les devoirs des membres
PR, PA, patriarche et Assistant Pasteur **de la tribu du membre** (même règle que la FISS) :
fiche du membre (sections « Résolutions du mois » et « Devoirs »), **Devoirs et RM › Suivi des RM** (qui a pris ses
résolutions, renouvellements, bilan du mois d'avant) et la liste des réponses de chaque devoir.
Un Garde, un responsable de département, l'administrateur ou un membre n'y ont pas accès (contrôle serveur, testé).

### 11. FISS : rubrique « Vie intérieure »
Six questions confidentielles, **obligatoires** (elles font partie du remplissage de la fiche ; « Aucune » ou « Non »
si rien à signaler), ouvertes d’office : addiction, victoire sur une addiction, saines
compagnies (1 Corinthiens 15:33), blessure intérieure, situations difficiles, pensées de découragement
(2 Thessaloniciens 2:3). Lues par le patriarche, l'AP de la tribu et les pasteurs dans la fiche FISS du membre.
Les questions se modifient dans `app/Support/FissCatalog.php` (`innerLife()`).

### Tableau de bord : « Mon mois »
Une carte remplace le bandeau FISS : fiche de santé spirituelle, résolutions du mois et devoir, avec ce qui reste à
faire (un appui ouvre le bon écran). Quand tout est fait, elle se réduit à une ligne d'encouragement.

## Deuxième lot (8 octobre)

### Devoirs (DS) et ressources des RM : le Pasteur Résident seul
- Seul le PR publie, modifie ou retire un devoir et les ressources des RM (le PA, les patriarches et les AP suivent les
  réponses sans pouvoir publier). Chez les membres, « Mon devoir » présente les devoirs **de la semaine**, puis les
  semaines précédentes.
- Les patriarches et AP voient les réponses des membres de **leur** tribu ; le PR et le PA, celles de toute l'église.

### Tableau de bord et vidéos
- Encadré du verset plus compact (la photo reste bien visible, la carte « Mon mois » remonte au premier écran).
- **Mes exercices** : titres de section plus lisibles ; le titre d'une vidéo s'affiche en grand dans la page (plus
  coupé dans l'en-tête), lisible même long sur téléphone.
- Titres collés depuis YouTube (lettres « stylisées » 𝗧𝘂 𝘃𝗲𝘂𝘅…, émojis) ramenés à un texte simple, partout.

### Vertumètre mensuel
- Menu **Mon Vertumètre** : le questionnaire de la liste des tâches (vie de prière et de jeûne, méditation de la Parole,
  fruit de l'Esprit et caractère), en 3 étapes, pour **le mois en cours seulement** (un mois passé ne se rattrape pas).
- Après l'enregistrement : confirmation, réponse **verrouillée** ; une modification passe par une demande au patriarche
  (2 au plus, 7 jours pour modifier), **comme la FISS** (Validations › Modifications du Vertumètre).
- Lecteurs : PR, PA, patriarche et AP de la tribu (fiche du membre, Suivi du mois › Vertumètre). Rappels le 20 puis le
  dernier jour du mois, à ceux qui ne l'ont pas rempli. Le questionnaire se modifie dans `app/Support/VertumetreCatalog.php`.
- L'ancien « Vertumètre » (notes /20 données par les responsables) s'appelle désormais **« Notes »** (données inchangées).

### Divers
- « Mon mois » compte aussi le Vertumètre ; page responsables renommée **« Suivi du mois »** (Devoirs, Résolutions,
  Vertumètre, Ressources RM).
- Messages corrigés : « d'octobre », « d'avril », « d'août » (au lieu de « de octobre »…).
- Affichage revu sur toutes les largeurs (320 px à grand écran) avec des textes extrêmes : noms très longs mis en
  valeur (prénom puis nom, dans « Mon profil » et la fiche membre), aucun texte qui sort d'une carte ou d'un bouton,
  libellés de boutons raccourcis, barre des notifications, horaires du tableau de bord, journal, page Responsables.

## Troisième lot (9 octobre)

### Suppression d'un membre et numéro bloqué
- Fiche d'un membre : le **PR** supprime (motif, résumé de ce qui disparaît, case de confirmation).
- Le **patriarche** de la tribu et les **responsables de département** du membre **demandent** la suppression (motif) ;
  le PR approuve (suppression) ou refuse (motif communiqué au demandeur) dans **Validations**.
- Le numéro d'un compte supprimé (y compris depuis la console de supervision) est **refusé à l'inscription**, sans
  envoi de SMS ; le PR peut le débloquer (Validations › Numéros bloqués). Un compte existant n'est jamais concerné.
- Une demande en attente se ferme d'elle-même si le compte est supprimé autrement.

### Rendez-vous avec le pasteur
- **Pasteur** (le PR ; un autre rôle avec la permission « Recevoir des rendez-vous ») : menu **Rendez-vous ›
  Disponibilités**. Ouvert par défaut : **mercredi 21h50 – 23h00** et **dimanche 12h50 – 14h00**, **15 min** par
  personne. Il ajoute une plage à une date, bloque une séance entière ou un créneau, change la durée, ou ferme les
  nouvelles réservations. Il peut **révoquer** n'importe quel rendez-vous (motif facultatif, la personne est prévenue
  et peut reprendre un autre créneau).
- **Créneau bloqué alors qu'il était réservé** : le rendez-vous est **déplacé automatiquement** au prochain créneau
  libre (les plus urgents d'abord) et la personne est prévenue de sa nouvelle heure ; s'il ne reste aucun créneau
  libre, il est annulé avec un message.
- **Membre** : menu **Prendre rendez-vous** (« Prendre rendez-vous avec le pasteur »). Il choisit un **créneau
  précis**, la **première heure libre d'un jour**, ou **le plus tôt possible** : le rendez-vous est **confirmé
  aussitôt**, sans validation. Il indique la priorité (normale, importante, urgente) et, s'il le souhaite, un message
  **lu par le pasteur seulement**. Un seul rendez-vous à la fois ; annulation possible jusqu'à l'heure du rendez-vous.
- **Le pasteur est notifié** à chaque rendez-vous pris (en priorité haute s'il est urgent), le protocole aussi.
- **Équipe du protocole** : nouveau rôle à attribuer dans **Rôles** aux membres du protocole. Menu **Rendez-vous ›
  Organiser** : les rendez-vous à venir **par ordre de priorité** (urgence, puis ancienneté) et les séances créneau par
  créneau ; déplacer, révoquer, ajuster la priorité, noter « fait » ou « absent ».
- **Les créneaux se suivent sans pause** (21h50, 22h05, 22h20…). Le jour même, le protocole voit qui est « En cours » ou
  « Suivant(e) » : dès qu'une personne sort (même avant la fin de ses 15 min), il touche **« Terminé »** (ou « Absent »)
  et la personne suivante reçoit aussitôt « C'est votre tour » ; **« Faire entrer »** l'appelle à la main.
- **Automatique** : 3 h avant chaque séance, le pasteur et le protocole reçoivent la liste du jour ; rappel au membre
  le jour même dès 8 h ; un rendez-vous passé sans suite est clos tout seul.
- Le tableau de bord du membre affiche son prochain rendez-vous.
- Si un compte « Pasteur Résident » ne reçoit pas de rendez-vous (compte de test, deuxième compte) : le décocher dans
  Rendez-vous › Disponibilités (« Je prends des rendez-vous ») ; il n'apparaît plus chez les membres.

## Quatrième lot (10 octobre) : courriels et textos

Expéditeur : **noreply@vasesdhonneurchicoutimi.org** (alias de la boîte eden@), courriels automatiques sans adresse de
réponse ; le pied de chaque courriel invite à écrire depuis « Nous contacter » dans l'application. Chaque
message passe par une file d'envoi traitée chaque minute en arrière-plan (`php artisan app:send-outbox`), avec
reprise en cas d'échec (1, 5, 30, 120 min) et sans aucun doublon. Membre : **courriel si son adresse est remplie,
sinon texto** ; il choisit dans Notifications › « Recevoir aussi par courriel / par texto ».

- **Pasteur : un seul courriel par jour de rendez-vous** avec la liste des personnes attendues (ordre de passage,
  tribu, téléphone, priorité, message). Envoyé dès que toutes les plages du jour sont prises, sinon 15 min avant le
  premier rendez-vous. Si la liste change après un envoi anticipé, une seule version « Liste mise à jour » part à
  ce même moment. Sans adresse dans son profil, le pasteur en est averti une fois dans l'application.
- **Demande de suppression** (patriarche, responsable) : courriel au Pasteur Résident avec **Accepter / Rejeter**.
  Un appui suffit : la décision se fait aussitôt, puis l'application s'ouvre (Validations) et confirme le résultat.
  Liens nominatifs, valables 14 jours ; une décision déjà prise n'est jamais refaite ; un antivirus de messagerie qui
  ouvre le lien ne décide rien.
- **Liens des courriels** : ouverts sans être connecté, la page visée s'ouvre juste après la connexion.
- **Design** : couleurs pleines et cartes claires, lisibles en mode clair comme en mode sombre (Gmail, Outlook, Apple Mail).
- **Nouveau devoir (DS)** : courriel (ou texto) à chaque membre dès la publication (si « Prévenir » est coché).
- **FISS** : rappel la première semaine du mois, relance la dernière semaine, à ceux qui ne l'ont pas envoyée ;
  plus rien après l'envoi. (Remplace l'ancien rappel du 20 et du dernier jour.)
- **Rapports des patriarches et responsables** : rappel la première semaine, relance la dernière semaine du mois ;
  plus rien après l'envoi. (Remplace l'ancien rappel du 1er au 10.)
- **Textos** : écrits sans caractère qui doublerait leur coût, un seul texto (lien jamais coupé), jamais la nuit
  (de 20 h 30 à 9 h, ils attendent le matin). **Il faut un numéro Twilio** (aucun sur le compte au 10 octobre) :
  sans `TWILIO_SMS_FROM`, les textos sont simplement désactivés et rien ne se bloque.
- **Adresse courriel des membres** : bandeau sur le tableau de bord (adresse saisie sur place, « Plus tard » = 7 jours),
  invitation par notification puis un seul rappel 3 semaines plus tard, et champ dans Notifications.
- **SMS du code de connexion** : le modèle avec le nom est utilisé par défaut, mais au Canada Twilio envoie les codes
  depuis un numéro partagé et **retire le nom** (règle des opérateurs, avertissement 60333 dans Twilio). Seul un
  numéro dédié (avec un service de messagerie branché à Verify) permettrait d'afficher « My vasesdhonneur ».

## Cinquième lot (11 octobre) : validation des nouveaux comptes, absences, exercices

- **Rendez-vous** : au moins 6 rendez-vous par séance. Par défaut 21 h 50 - 23 h 20 (mercredi) et 12 h 50 - 14 h 20
  (dimanche) ; une plage plus courte est prolongée d'elle-même.
- **Sujet du rendez-vous** : lu par le pasteur seulement ; le protocole ne voit même plus qu'il y en a un.
- **Exercices** : historique complet (en cours, terminés, les miens, recherche, 20 par page) avec l'auteur et
  l'avancement. L'AP de la tribu, le PR et le PA suivent les exercices des patriarches ; un responsable dont la portée
  ne couvre qu'une partie de l'exercice ne voit que ses membres. Bouton « Relancer » (une fois par jour) et, sur la
  fiche d'un membre, ses exercices avec leur statut. Corrigé : répondre à un exercice sans date limite plantait.
- **FISS** : rappel la 2e semaine du mois (du 8 au 14) et relance la dernière semaine, par courriel et dans l'application.
- **Nouveau compte** : après le profil, écran d'attente (bienvenue, verset du jour, « en attente de validation par
  le pasteur », courriel conseillé). Le pasteur reçoit le profil complet par courriel avec « Valider » et
  « Rejeter » (un appui suffit), et dans Validations. La décision s'applique aussitôt ; la personne est prévenue
  (courriel, notification) et l'écran d'attente s'ouvre tout seul. Un refus se corrige dans Validations (30 jours).
  Tant qu'il n'est pas validé, le compte n'apparaît dans aucune liste, statistique ni relance. **Les comptes existants
  ne changent pas** ; seules les inscriptions jamais terminées passeront par la validation.
- **Absence d'une semaine** : courriel au membre (ce qui l'attend), une seule fois par absence (de 7 à 30 jours) ; le
  patriarche et l'AP de sa tribu reçoivent la liste dans l'application. Filtre « Absents 7 j » dans Membres.

## Fichiers à envoyer (dossier LARAVEL = `public_html/espace`)

```
app/Console/Commands/AutomationTick.php
app/Console/Commands/FissRemind.php
app/Console/Commands/MailTest.php                                                           (nouveau)
app/Console/Commands/SendOutbox.php                                                         (nouveau)
app/Http/Controllers/Api/Admin/AppointmentController.php                                    (nouveau)
app/Http/Controllers/Api/Admin/AssignmentController.php                                     (nouveau)
app/Http/Controllers/Api/Admin/AuditLogController.php
app/Http/Controllers/Api/Admin/ExerciseController.php
app/Http/Controllers/Api/Admin/FissController.php
app/Http/Controllers/Api/Admin/MemberController.php
app/Http/Controllers/Api/Admin/MemberDeletionController.php                                 (nouveau)
app/Http/Controllers/Api/Admin/ResolutionController.php                                     (nouveau)
app/Http/Controllers/Api/Admin/ServiceScheduleController.php                                (nouveau)
app/Http/Controllers/Api/Admin/ValidationController.php
app/Http/Controllers/Api/Admin/VertumetreController.php                                     (nouveau)
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/EmailActionController.php                                          (nouveau)
app/Http/Controllers/Api/MyAppointmentController.php                                        (nouveau)
app/Http/Controllers/Api/MyAssignmentController.php                                         (nouveau)
app/Http/Controllers/Api/MyFamilyController.php
app/Http/Controllers/Api/MyFissController.php
app/Http/Controllers/Api/MyHomeController.php
app/Http/Controllers/Api/MyMonthController.php                                              (nouveau)
app/Http/Controllers/Api/MyNotificationPrefsController.php
app/Http/Controllers/Api/MyResolutionController.php                                         (nouveau)
app/Http/Controllers/Api/MyVertumetreController.php                                         (nouveau)
app/Http/Controllers/Api/MyWelcomeController.php
app/Http/Controllers/Api/ProfileController.php
app/Http/Controllers/Api/PulseController.php
app/Mail/NotificationMail.php                                                               (nouveau)
app/Models/Appointment.php                                                                  (nouveau)
app/Models/AppointmentBlock.php                                                             (nouveau)
app/Models/AppointmentDigest.php                                                            (nouveau)
app/Models/AppointmentSetting.php                                                           (nouveau)
app/Models/AppointmentWindow.php                                                            (nouveau)
app/Models/Assignment.php                                                                   (nouveau)
app/Models/AssignmentResponse.php                                                           (nouveau)
app/Models/AssignmentView.php                                                               (nouveau)
app/Models/BlockedPhone.php                                                                 (nouveau)
app/Models/Exercise.php
app/Models/FamilyLink.php
app/Models/MemberDeletionRequest.php                                                        (nouveau)
app/Models/MonthlyResolution.php                                                            (nouveau)
app/Models/OutboundMessage.php                                                              (nouveau)
app/Models/ResolutionResource.php                                                           (nouveau)
app/Models/SpiritualHealthForm.php
app/Models/VertumetreEditRequest.php                                                        (nouveau)
app/Models/VertumetreForm.php                                                               (nouveau)
app/Services/AppointmentService.php                                                         (nouveau)
app/Services/AssignmentProgress.php                                                         (nouveau)
app/Services/ExerciseProgress.php
app/Services/FamilyService.php
app/Services/FissService.php
app/Services/LeaderReportService.php
app/Services/MemberDeletionService.php                                                      (nouveau)
app/Services/MemberMessages.php                                                             (nouveau)
app/Services/Messenger.php                                                                  (nouveau)
app/Services/Monitoring/UserAdmin.php
app/Services/Notifier.php
app/Services/ResolutionService.php                                                          (nouveau)
app/Services/Sms/TwilioMessagingClient.php                                                  (nouveau)
app/Services/VertumetreService.php                                                          (nouveau)
app/Support/FissCatalog.php
app/Support/French.php                                                                      (nouveau)
app/Support/MemberScope.php
app/Support/PlainText.php                                                                   (nouveau)
app/Support/ProfileCompletion.php
app/Support/Registration.php                                                                (nouveau)
app/Support/ResolutionCatalog.php                                                           (nouveau)
app/Support/VertumetreCatalog.php                                                           (nouveau)
config/mail.php
config/services.php
database/migrations/2026_10_07_100001_update_service_times.php                              (nouveau)
database/migrations/2026_10_07_100002_add_birth_date_to_family_links.php                    (nouveau)
database/migrations/2026_10_07_100003_require_tribe_at_registration.php                     (nouveau)
database/migrations/2026_10_07_100004_add_inner_life_to_spiritual_health_forms.php          (nouveau)
database/migrations/2026_10_07_100005_create_monthly_resolutions_tables.php                 (nouveau)
database/migrations/2026_10_07_100006_create_assignments_tables.php                         (nouveau)
database/migrations/2026_10_07_100007_create_assignment_views_table.php                     (nouveau)
database/migrations/2026_10_08_100001_create_member_deletion_tables.php                     (nouveau)
database/migrations/2026_10_08_100003_create_vertumetre_tables.php                          (nouveau)
database/migrations/2026_10_09_100001_create_appointment_tables.php                         (nouveau)
database/migrations/2026_10_10_100001_create_outbound_messages_tables.php                   (nouveau)
database/seeders/RolesAndPermissionsSeeder.php
resources/views/email-action.blade.php                                                      (nouveau)
resources/views/emails/appointments-digest.blade.php                                        (nouveau)
resources/views/emails/assignment.blade.php                                                 (nouveau)
resources/views/emails/deletion-request.blade.php                                           (nouveau)
resources/views/emails/fiss-reminder.blade.php                                              (nouveau)
resources/views/emails/layout.blade.php                                                     (nouveau)
resources/views/emails/partials/button.blade.php                                            (nouveau)
resources/views/emails/plain.blade.php                                                      (nouveau)
resources/views/emails/report-reminder.blade.php                                            (nouveau)
routes/api.php
routes/console.php
```

Et l'application compilée (`frontend/dist/`, `npm run build`) dans **PUBLIC** (`espace/public`) :
`assets/` (fusionner), **`verset/`** (nouveau dossier), `manifest.webmanifest`, `sw.js`, puis `index.html` **en dernier**.

## Avant de déployer
- **Sauvegarde SQL** de la base (export phpMyAdmin).
- **Courriels** : dans le `.env` du serveur, renseigner la section « Courriels » de `.env.production.example`
  (`MAIL_*`, mot de passe de la boîte Hostinger qui porte contact@) et laisser `MAIL_TEST_TO` vide. Vérifier :
  `php artisan app:mail-test votre@adresse` (un exemple de chaque courriel).
- **Textos** (facultatif) : acheter un numéro canadien dans Twilio, puis `TWILIO_SMS_FROM=+1...` ;
  vérifier avec `php artisan app:mail-test votre@adresse --sms=+1...`.
- **Cron** : `php artisan schedule:run` chaque minute (il lance aussi `app:send-outbox`).
- **Taille des fichiers audio** : vérifier dans hPanel › PHP que `upload_max_filesize` et `post_max_size` valent au moins
  **48M** (sinon un audio de 40 Mo serait refusé par l'hébergeur avant même d'arriver à l'application).

## Commandes (SSH, dans `public_html/espace`)

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

Les migrations : ajoutent des colonnes (date de naissance des enfants, vie intérieure de la FISS) et des tables
(résolutions, ressources, devoirs, réponses, suivi de lecture des devoirs, Vertumètre mensuel et ses demandes de
modification, demandes de suppression et numéros bloqués, rendez-vous avec le pasteur) et le rôle « Équipe du protocole » ; règlent les deux horaires ; rouvrent l'inscription des membres sans
tribu (hors exemptés). Aucune donnée n'est supprimée. Ne **pas** relancer le seeder des rôles.

## Vérifier (15 min)

1. **Membre sans tribu** (s'il y en a) : l'application s'ouvre sur « Une dernière étape » ; choisir la tribu ; le
   tableau de bord s'ouvre, le patriarche reçoit « … a rejoint la tribu … ».
2. **Tableau de bord** (téléphone) : verset sur la photo de l'église, carte « Mon mois », « Nos rendez-vous » avec
   dimanche 8h45 et mercredi 19h30 sans fin.
3. **PR** : Horaires des cultes (modifier puis remettre un horaire) ; Suivi du mois : publier un devoir vidéo puis un
   audio, ajouter une ressource par thème.
4. **Membre** : Mes RM (bilan si résolutions le mois passé, « Je renouvelle », enregistrer) ; Mon devoir (résumé,
   actions, prière) ; Mon Vertumètre (3 étapes, confirmation, verrou) ; FISS (rubrique Vie intérieure) ; Mon profil ›
   Famille (date de naissance d'un enfant).
5. **Patriarche** : fiche d'un membre de sa tribu (Résolutions du mois, Devoirs, Vertumètre, Vie intérieure dans la
   FISS) ; Suivi du mois ne liste que sa tribu, sans bouton pour publier un devoir.
6. **Garde** : n'a ni le menu « Suivi du mois » ni les sections RM, devoirs et Vertumètre dans la fiche d'un membre.
7. **Rendez-vous** : attribuer le rôle « Équipe du protocole » ; un membre réserve un créneau puis un autre fait une
   réservation « le plus tôt possible » (confirmée aussitôt) ; le pasteur reçoit la notification ; il bloque un créneau
   réservé (le rendez-vous se déplace tout seul) puis en révoque un autre.
8. **Suppression** : un patriarche demande la suppression d'un membre de test, le PR refuse (motif reçu par le patriarche).

## Retour arrière

```sh
php artisan migrate:rollback --step=12
```
La 12e étape (5e lot) rend l'accès aux comptes encore en attente : ils reviennent à l'écran d'inscription.
Les horaires reviennent à 8 h 30 et au mercredi 19 h 30 - 21 h 30, les membres rouverts redeviennent inscrits sans
tribu ; les résolutions, ressources, devoirs, réponses, Vertumètres remplis, dates de naissance complètes et réponses
« Vie intérieure »
seraient **perdus** : faire un export SQL avant. Remettre ensuite les anciennes versions des fichiers.
