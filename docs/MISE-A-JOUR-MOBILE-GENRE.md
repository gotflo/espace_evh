# Mise à jour : menu mobile et choix Homme/Femme

## Corrections

- `frontend/src/index.css` : menu latéral défilable, hauteur adaptée à la zone visible (`100dvh`, avec repli `100vh`), marges de sécurité et contenu non comprimé. La déconnexion reste accessible en faisant défiler le menu, y compris avec toutes les entrées administrateur.
- `frontend/src/pages/ProfileSetup.tsx` : seuls Homme et Femme sont sélectionnables à l'inscription. Le texte initial « Choisir » est un indicateur désactivé et masqué dans la liste, pas une troisième valeur. Un choix explicite est requis.
- `frontend/src/pages/MyProfile.tsx` : même liste pour modifier son profil, avec message si aucun choix n'est fait.
- `backend/app/Http/Controllers/Api/ProfileController.php` : validation `required` et `in:homme,femme` pour que l'API applique la même règle.
- `backend/tests/Feature/ProfileGenderTest.php` : six cas de test sur les valeurs acceptées et refusées.

Les valeurs anciennes ne sont pas converties automatiquement. Un profil auparavant vide ou « autre » doit choisir Homme ou Femme lors de sa prochaine sauvegarde. Aucune migration ni modification directe de la base.

Les corrections précédentes des rôles et évaluations sont incluses dans le nouveau build. Les autres champs ayant une option « Autre » ou « Non précisé » ne sont pas concernés.

## Copie locale et vérifications

Les sources ont été corrigées directement dans `D:\projets\evh_platform`.
Le build a été régénéré dans `frontend/dist/` puis copié dans `backend/public/`. Chaque fichier copié a été comparé par SHA-256 ; `backend/public/index.php` a été conservé à l'identique. Les anciens assets sont conservés pour les sessions déjà ouvertes.

- `php artisan test` : **12 tests réussis, 44 assertions**, base SQLite en mémoire.
- `npm run lint` : réussi.
- `npm run build` : TypeScript et Vite réussis.
- Contrôle API avec la liste des routes Laravel : **77 appels, 82 combinaisons, 76 routes**, réussi.
- `git diff --check` : réussi.
- Contrôle navigateur du menu : reproduction locale avec le CSS réel et les 14 liens du menu, à **320 × 480** et **390 × 664**. Défilement jusqu'à la déconnexion vérifié ; clic vérifié à 320 × 480. Ce contrôle porte sur la disposition du menu, pas sur une session connectée en production ni sur un appareil iOS/Android physique.

## Archives

- `deployment/hostinger-public-mobile-genre-20260921.zip` : tout le nouveau build public, sans dossier `dist` intermédiaire.
- `deployment/hostinger-backend-mobile-genre-20260921.zip` : uniquement `app/Http/Controllers/Api/ProfileController.php`, à fusionner avec la racine Laravel.
- `deployment/sources-mobile-genre-20260921.zip` : sources corrigées de cette mise à jour et de la précédente, tests, contrôle API, ce guide et inventaire du build ; à conserver pour le développement, pas à extraire dans le dossier public.
- `deployment/mobile-genre-build-sha256.txt` : liste exacte de chaque fichier public livré et son empreinte.

Ces archives sont des correctifs à fusionner avec le projet existant. Elles ne contiennent pas `index.php`, qui reste inchangé.

## Déploiement Hostinger

Appelons **LARAVEL** le dossier existant contenant `artisan`, `app`, `routes`, `vendor` et **PUBLIC** le dossier déjà servi contenant `index.php`, `index.html` et `assets`. Dans l'organisation fournie, PUBLIC = LARAVEL/public. Le chemin absolu de votre compte Hostinger n'est pas connu : utiliser les dossiers existants du sous-domaine `espace.vasesdhonneurchicoutimi.org`, sans modifier leur organisation.

1. Sauvegarder le contrôleur `LARAVEL/app/Http/Controllers/Api/ProfileController.php`, l'ancien `PUBLIC/index.html` et les fichiers publics avant remplacement.
2. Extraire le ZIP public sur votre ordinateur. Envoyer `assets/` vers **PUBLIC/assets/** en fusionnant, puis les icônes, `manifest.webmanifest`, `sw.js` et autres fichiers statiques vers **PUBLIC/**. Envoyer **index.html en dernier** vers **PUBLIC/index.html**. Conserver les anciens fichiers hachés pendant la transition.
3. Extraire le ZIP backend et copier **app/Http/Controllers/Api/ProfileController.php** vers **LARAVEL/app/Http/Controllers/Api/ProfileController.php**, en remplaçant uniquement ce fichier. Faire ce transfert juste après le build pour réduire le temps de coexistence avec l'ancien formulaire.
4. Ne pas écraser `.env`, `index.php`, `.htaccess`, `storage` ou les photos des membres. Ne pas créer de dossier `dist` dans PUBLIC. Les fichiers `.tsx` du ZIP sources ne remplacent pas le JavaScript compilé.

### Commandes

Cette mise à jour ne change ni routes, ni configuration, ni dépendances, ni schéma de base : **aucune commande de migration, Composer, npm ou de purge Laravel n'est nécessaire sur Hostinger**.

Vérification facultative par SSH, en remplaçant le chemin ci-dessous par LARAVEL :

```sh
cd '/chemin/reel/du/dossier/qui/contient/artisan'
php -l app/Http/Controllers/Api/ProfileController.php
php artisan route:list --path=api/profile --except-vendor
```

Le PHP CLI doit être compatible avec le projet (8.3 ou supérieur). Si l'hébergement conserve une ancienne version PHP via OPcache sans revalidation, utiliser le mécanisme de redémarrage PHP de l'hébergement ou son support. Une commande `opcache_reset()` exécutée en CLI ne purge pas nécessairement le cache du serveur web.

### Vérification après transfert

1. Recharger en ligne ; si nécessaire purger le cache HTML du CDN/hébergement. Fermer puis rouvrir la PWA. Le code source de la page doit référencer **`/assets/index-4xGlwaM4.js`**.
2. Sur un petit téléphone, ouvrir le menu avec un compte administrateur et faire défiler **à l'intérieur du menu** jusqu'en bas : Déconnexion doit être visible et utilisable.
3. Sur une inscription, ouvrir Genre : seuls **Homme** et **Femme** sont sélectionnables. Enregistrer et vérifier le choix après rechargement.
4. Vérifier la même liste dans Mon profil. Pour un ancien profil sans choix valide, sélectionner explicitement Homme ou Femme avant d'enregistrer.
5. Une soumission sans choix ou avec `autre` doit être refusée par l'API en HTTP 422 ; Homme/Femme doit être accepté en HTTP 200 avec les autres champs valides.
6. Vérifier à nouveau l'ajout/retrait d'un rôle : les corrections précédentes sont toujours présentes.

Si la liste affiche encore « Non précisé » ou « Autre », un ancien build est chargé. Essayer une navigation privée, puis vider les données du site au besoin (cela déconnecte le compte).

Pour revenir en arrière, restaurer le contrôleur et le build sauvegardés ensemble, puis recharger/purger le cache HTML. Aucune migration à annuler.
