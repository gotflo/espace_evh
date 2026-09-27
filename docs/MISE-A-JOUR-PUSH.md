# Mise à jour : notifications push fiables et vérifiables

## Ce qui a été trouvé

Le site en production est à jour et le navigateur est correctement configuré (service worker, politique de
sécurité, abonnement). La signature et le chiffrement des messages sont conformes (vérifiés par des tests
cryptographiques). En revanche, trois points pouvaient empêcher les push d'arriver **sans laisser de trace** :

1. **Envoi après la réponse** : les push déclenchés depuis l'application (annonces, événements, test…) partaient
   après la réponse HTTP. Sur l'hébergement (LiteSpeed), ce travail d'arrière-plan peut être interrompu : le push
   était alors perdu, sans erreur visible.
2. **Bouton « Envoyer un test »** : il répondait « envoyée » avant même d'essayer, et le test était soumis à la
   limite de 6 notifications par heure (souvent atteinte juste après la mise en ligne, quand les automatismes
   rattrapent leur retard).
3. **Clé de chiffrement** : chaque message génère une clé avec OpenSSL en imposant un fichier de configuration
   prévu pour Windows. Si ce réglage échoue sur le serveur, aucun message ne peut être chiffré.

## Ce qui change

- **Boîte d'envoi** : chaque push est enregistré avant d'être envoyé. Ce qui n'est pas parti dans la minute est
  renvoyé par le cron (chaque minute, 3 essais au plus, jamais en double).
- **Clé de chiffrement** : configuration OpenSSL du serveur d'abord, celle de l'application en secours.
- **Bouton « Envoyer un test »** (page Notifications) : réenregistre l'appareil, envoie tout de suite, sans limite,
  et affiche la réponse de chaque appareil du compte (« Acceptée », « Abonnement expiré », « Clé refusée »…).
- **Surveillance** : `/api/health` indique l'état des push (`checks.push`) : prêts ou non, appareils abonnés,
  envois en attente, heures depuis le dernier message reçu par un appareil.
- **Diagnostic serveur** : `php artisan app:push-check` (et `php artisan app:push-check +1418…` pour envoyer un test
  aux appareils d'un membre et voir la réponse de chaque service).

## Fichiers à envoyer (dossier LARAVEL = `public_html/espace`)

```
app/Console/Commands/PushCheck.php                  (nouveau)
app/Console/Commands/PushOutbox.php                 (nouveau)
app/Http/Controllers/Api/HealthController.php
app/Http/Controllers/Api/MyNotificationController.php
app/Services/Notifier.php
app/Services/Push/WebPush.php
database/migrations/2026_09_30_100001_create_push_outbox_table.php   (nouveau)
routes/console.php
```

Et l'application compilée (`frontend/dist/`, obtenue par `npm run build`) dans **PUBLIC** (`espace/public`) :
`assets/` (fusionner), puis `index.html` **en dernier**.

## Commandes (SSH, dans `public_html/espace`)

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan app:push-check
```

`app:push-check` doit afficher `Chiffrement et signature : OK`. La tâche Cron `schedule:run` existante suffit : la
boîte d'envoi est traitée automatiquement chaque minute (rien à ajouter dans hPanel).

## Vérifier (5 min)

1. `https://espace.vasesdhonneurchicoutimi.org/api/health` → la partie `"push"` doit indiquer `"ok":true`.
2. Sur votre téléphone : application → **Notifications** → **Envoyer un test**. Le résultat s'affiche appareil par
   appareil :
   - **Acceptée** mais rien ne s'affiche → réglages du téléphone : autoriser les notifications du navigateur (ou de
     l'application installée), couper « Ne pas déranger » ;
   - **Clé refusée** → « Désactiver sur cet appareil » puis « Activer les notifications » ;
   - **Abonnement expiré** → rouvrir l'application puis réactiver ;
   - **Aucun appareil abonné** → activer les notifications sur cet appareil (sur iPhone : application installée
     sur l'écran d'accueil, iOS 16.4 ou plus).
3. Par SSH, pour un membre qui ne reçoit rien : `php artisan app:push-check +1418XXXXXXX` (son numéro) montre la
   réponse du service pour chacun de ses appareils.

## Retour arrière

```sh
php artisan migrate:rollback --step=1
```
puis remettre les anciennes versions des fichiers ci-dessus.

## Complément : notification visible dans la barre du téléphone

Constat en production : un seul appareil abonné (iPhone) ; le test apparaît dans la cloche mais pas dans la barre
du téléphone. Le service d'Apple accepte bien le message. En revanche, **l'iPhone n'affiche pas la notification
système quand l'application est ouverte au premier plan**, ce qui est toujours le cas au moment d'appuyer sur
« Envoyer un test ».

- **« Tester dans 10 s »** (page Notifications) : le push part 10 secondes plus tard, le temps de revenir à l'écran
  d'accueil ; il passe par la boîte d'envoi (rattrapé par le cron si l'attente est interrompue).
- **Application ouverte** : un push reçu s'affiche désormais en message dans l'application (en plus de la cloche).

Fichiers : `app/Services/Notifier.php`, `app/Http/Controllers/Api/MyNotificationController.php` (paquet
`push2-backend-20260927.zip`), et l'application compilée (`push2-public-20260927.zip`, qui contient le nouveau
`sw.js`). Commandes : `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`.

Sur l'iPhone, si « Tester dans 10 s » ne montre toujours rien : Réglages → **Notifications** → application
« Vases d'Honneur » → autoriser, style « Bannières » ; vérifier qu'aucun mode **Concentration** n'est actif.
Après la mise à jour, fermer complètement l'application (balayer vers le haut) puis la rouvrir, pour que le nouveau
service de notification soit pris en compte.

## Complément 2 : abonnement de l'iPhone et manifeste

Vérifications faites : le chiffrement produit par le serveur a été déchiffré par une implémentation indépendante
(module cryptographique de Node, RFC 8291) ; il est conforme. Le service d'Apple accepte les messages. Reste le lien
entre le serveur et l'application installée sur le téléphone.

- **Bouton « Réinitialiser »** (Notifications) : supprime l'abonnement de cet appareil (téléphone et serveur), en
  crée un nouveau, puis envoie un test 10 secondes plus tard.
- **`php artisan app:push-check`** liste désormais chaque appareil : membre, modèle et version (ex. « iPhone iOS
  18.1 »), date d'abonnement, dernier message accepté.
- **Manifeste** servi en `application/manifest+json` (il l'était en texte brut) : `public/.htaccess`.

Paquets : `push3-backend-20260927.zip` (`app/Console/Commands/PushCheck.php`, `public/.htaccess`) et
`push3-public-20260927.zip` (application compilée, `index.html` en dernier). Puis `php artisan optimize:clear`.

Sur l'iPhone : supprimer l'icône « Vases d'Honneur » de l'écran d'accueil, ouvrir le site dans **Safari**, Partager →
**Sur l'écran d'accueil**, ouvrir l'application **depuis l'icône**, se connecter, Notifications → **Activer**, puis
**Réinitialiser** et revenir à l'écran d'accueil.
