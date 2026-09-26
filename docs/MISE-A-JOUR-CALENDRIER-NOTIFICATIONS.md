# Mise à jour : calendrier, notifications push, services, nouveaux inscrits

## Ce qui change

1. **Nouveaux inscrits** (tableau de bord des responsables) : ce n'est plus une liste figée des 5 derniers profils.
   - Seules les inscriptions des **30 derniers jours** apparaissent : la liste se vide d'elle-même.
   - Onglet **À accueillir** : un responsable clique **Accueillir** quand il a contacté la personne (annulable).
   - Chaque responsable ne voit que les inscrits de son périmètre (GEM, tribu, département), jamais lui-même.
   - À la première inscription complète, les responsables du fidèle reçoivent une notification.
2. **Événements à venir, 30 prochains jours** : visible par tous sur le tableau de bord, regroupé par jour
   (« Aujourd'hui », « Demain », « Dans 3 jours »), avec la réponse présent / absent / volontaire **par occurrence**.
3. **Calendrier** (`/calendrier`, pour tous) : vues Mois / Semaine / Liste, bouton Aujourd'hui, interrupteur Détails,
   Imprimer (A4 paysage), Partager. Il affiche :
   - les événements, y compris **récurrents** (↻ : chaque jour, semaine, 2 semaines, mois, avec date de fin facultative) ;
   - les **jours fériés** du Québec/Canada et les fêtes chrétiennes (Pâques, Pentecôte…), calculés automatiquement chaque année ;
   - les **anniversaires** (jour et mois du profil) ;
   - les **échéances personnelles** : exercices à rendre, fiche FISS du mois.
   - **Abonnement** : Partager → Google Agenda / iPhone / Outlook. Lien personnel, mis à jour automatiquement.
4. **Notifications** : un vrai centre de notifications (cloche, page `/notifications`) et des **notifications push**
   sur le téléphone ou l'ordinateur, même application fermée. Envoyées automatiquement pour : annonce, nouvel événement,
   événement modifié ou annulé, rappel la veille et 1 h avant (aux inscrits), nouvel exercice et rappel d'échéance,
   FISS (le 20 puis les 2 derniers jours du mois), nouvelle demande (aux responsables), réponse à une demande,
   nouvelle note, nouvelle fonction, nouveau membre, inscription à un service, anniversaires.
   Sur iPhone, le push fonctionne quand l'application est installée sur l'écran d'accueil (iOS 16.4 ou plus).
5. **Servir** (`/servir`) : le fidèle s'inscrit à un service (département). L'inscription est immédiate :
   il intègre le département, reçoit ses annonces et événements, et le responsable est prévenu. Il peut aussi le quitter.
6. **Automatismes** : une commande unique `app:tick` (rappels, anniversaires, échéances, FISS, nettoyage) sans doublon possible.

## Déploiement Hostinger

Cette mise à jour touche le **schéma de la base**, les **routes** et la **configuration** : il faut téléverser tout le
dossier `app/`, `bootstrap/app.php`, `config/`, `routes/`, `resources/openssl/`, `database/migrations/`, puis le build public.
Aucune nouvelle dépendance Composer (le push est implémenté avec l'extension OpenSSL de PHP).

```sh
cd '/chemin/du/dossier/qui/contient/artisan'
php artisan migrate --force
php artisan config:clear && php artisan route:clear
php artisan app:tick   # premier passage, vérifie que tout fonctionne
```

Dans `.env`, ajouter si absent : `APP_TIMEZONE=America/Toronto` (sinon les heures s'affichent en UTC).

### Cron (recommandé)

hPanel → Avancé → Tâches Cron, **toutes les minutes** :

```sh
/usr/bin/php /chemin/du/dossier/artisan schedule:run >> /dev/null 2>&1
```

Sans cron, l'application déclenche elle-même les automatismes (au plus toutes les 5 minutes) dès qu'un membre l'utilise ;
le cron garantit des rappels à l'heure même la nuit ou quand personne n'est connecté.

### Clés push

Rien à faire : la paire de clés VAPID est générée au premier usage dans `storage/app/webpush-vapid.json`
(fichier à **conserver** lors des mises à jour ; le supprimer obligerait chaque appareil à réactiver les notifications).

## Remarques

- Les responsables à portée restreinte ne voient plus et ne modifient plus, dans Administration → Événements,
  que les événements de leur périmètre ou ceux qu'ils ont créés.
- Le rappel FISS n'est plus publié comme annonce : il arrive en notification (et en push).

## Deuxième lot (26 septembre)

- **Notifications push** : l'invitation disparaît dès que le choix est fait ; une erreur d'abonnement est expliquée
  (au lieu d'être silencieuse) et un ancien abonnement laissé par une autre application est remplacé.
- **Toasts** : chaque action (enregistrer, publier, s'inscrire, supprimer...) affiche un message animé de quelques
  secondes, vert si c'est réussi, rouge sinon. Automatique pour toute l'application.
- **Menu « Service »** (anciennement « Servir ») avec une nouvelle icône.
- **Rôles** :
  - l'**AP** consulte désormais **tous les membres** en lecture seule ; il n'agit que sur ses tribus ;
  - nouveau rôle **« Accompagnateur d'un fidèle »** (portée : un fidèle précis) : l'attribuer à un AP lui permet
    d'agir (suivi, notes, demandes) sur ce fidèle ;
  - nouveau rôle **« Communication (toute l'église) »** : annonces et événements pour toute l'église ;
  - les rôles qui diffusaient déjà à tous (ex. Pasteur Assistant) gardent ce droit (migration automatique).
  - Correctif de sécurité : le journal spirituel d'un fidèle n'était pas protégé par la portée du responsable.
- **Calendrier** : bouton « Programmer », « + » sur chaque jour, double-clic sur un jour ou touche N.
  Tout fidèle peut ajouter des rendez-vous **privés** à son agenda (avec rappels) ; les responsables publient
  des événements pour l'église depuis le même formulaire, et peuvent modifier ou supprimer depuis le calendrier.
- **Automatismes** : ouvrir la page d'une notification la marque comme lue ; relance des responsables pour un
  nouvel inscrit non accueilli après 3 jours et pour une demande sans réponse après 48 h ; chaque lundi, liste
  des fidèles devenus inactifs.

Déploiement : `php artisan migrate --force` (nouvelle migration des rôles), puis le nouveau build public.
**Ne pas relancer le seeder des rôles en production** : il réécrit les permissions des rôles de base.

## Troisième lot (présences, GEMs, navigation)

- **Présences** : la feuille n'affiche que les fidèles **actifs** de son périmètre (GEM, tribu, département).
  Un inactif qui revient se retrouve en tapant son nom ; le pointer le rend de nouveau actif.
  Corrigé : l'enregistrement d'une session provoquait une erreur serveur (variable `$présent` mal accentuée),
  et la date proposée pouvait être celle du lendemain après 20 h.
- **GEMs** : le GAD se choisit uniquement parmi les membres de la tribu du GEM (contrôlé aussi côté serveur,
  y compris lors de l'attribution du rôle GAD depuis une fiche). Un seul GAD par GEM ; le GAD rejoint son GEM.
  Un GEM qui a des membres ne peut pas changer de tribu. Un membre qui change de tribu quitte le GEM
  (et la responsabilité GAD) de l'ancienne.
- **Navigation simplifiée** : menu en deux parties « Mon espace » / « Gestion », « Mon profil » via la carte
  utilisateur, et sur téléphone une barre d'onglets en bas (Accueil, Calendrier, Service, Alertes, Menu).
- **Responsive** : les 20 pages vérifiées automatiquement à 360 px et 768 px de large, sans débordement.

Déploiement : aucune migration nouvelle pour ce lot ; téléverser `app/`, `routes/` et le nouveau build public.
