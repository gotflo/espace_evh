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
