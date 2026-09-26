# Correction des rôles et évaluations — 21 septembre 2026

Correction effectuée uniquement sur la copie locale. Aucun accès ni changement au site de production.

## Diagnostic et fichiers corrigés

Laravel attend des chemins techniques sans accents. Le client ajoute déjà `/api` aux chemins ci-dessous.

| Fichier | Corrections |
| --- | --- |
| `frontend/src/pages/admin/MemberDetail.tsx` | POST `/admin/members/${id}/roles`, DELETE `/admin/members/${id}/roles/${assignmentId}` ; classe CSS `role-chip` correspondant au sélecteur existant |
| `frontend/src/pages/admin/RolesAdmin.tsx` | PUT et DELETE `/admin/roles/${editing.id}` |
| `frontend/src/components/EvaluationsPanel.tsx` | GET et POST `/admin/members/${userId}/evaluations`, DELETE `/admin/evaluations/${id}` |

Soit sept appels API et une classe CSS corrigés. Les textes visibles, dont les noms de rôles et les messages français, conservent leurs accents. Les routes et contrôleurs Laravel ne nécessitent aucune modification pour ces erreurs.

Les paramètres ont été vérifiés : `role_key`, `scope_id`, identifiant utilisateur, identifiant de rôle pour sa gestion, identifiant de la ligne d'attribution pour son retrait ; pour les évaluations : `type`, `title`, `score`, `evaluated_on`, `comment`. Les clés sont compatibles avec la validation des contrôleurs. Les routes de navigation React et leurs liens ont également été examinés. La modification des événements utilise correctement POST multipart et `_method=PUT` ; ne pas la remplacer par un POST simple ou modifier sa route Laravel.

## Fichiers ajoutés et livrables

- `backend/tests/Feature/RoleAndEvaluationApiTest.php` : quatre tests fonctionnels avec base SQLite en mémoire.
- `frontend/scripts/check-api-routes.mjs` : comparaison statique des appels frontend avec `artisan route:list --json`, contrôlant les méthodes, segments de chemins et classes CSS accentuées. Les identifiants sont substitués par une valeur de test ; ce contrôle ne valide pas tous les contenus de formulaires ni les permissions métier de toutes les fonctionnalités.
- `docs/CORRECTION-ROLES-HOSTINGER.md` : ce guide.
- `deployment/build-sha256.txt` : liste exhaustive des 180 fichiers du build avec leurs empreintes SHA-256 ; les chemins sont relatifs au dossier public de destination.
- `deployment/hostinger-public-20260921.zip` : ces 180 fichiers, directement à la racine de l'archive (`index.html`, `assets/`, icônes, manifeste, service worker…). Aucun PHP, secret, base de données ou fichier utilisateur.
- `deployment/correctif-sources-20260921.zip` : les trois sources corrigées, les deux fichiers de test/contrôle, ce guide et le manifeste, avec leur arborescence projet.
- `frontend/dist/` : build Vite régénéré, identique au contenu de l'archive publique. Ce dossier et les ZIP sont ignorés par Git selon la configuration existante.

Seuls les trois fichiers applicatifs du tableau ont été modifiés. Les dépendances, migrations et paramètres Laravel sont inchangés. `backend/public/` n'a pas été remplacé localement : utiliser le nouveau build livré, et non l'ancienne archive `backend/backend.zip`.

## Vérifications réalisées

| Vérification | Résultat |
| --- | --- |
| PHP local | 8.3.30 |
| `php artisan route:list --path=api --except-vendor` | Réussite, 76 routes API |
| `node scripts/check-api-routes.mjs <php>` depuis `frontend/` | 77 appels, 82 combinaisons URL/méthode conformes aux 76 routes chargées |
| `php artisan test` depuis `backend/` | 6 tests réussis, 22 assertions |
| Nouveaux tests fonctionnels | Attribution persistée, pas de doublon, retrait par ID d'attribution ; création/lecture/modification/suppression de rôle ; ajout/lecture/suppression de note ; refus 401 sans connexion et 403 sans permission |
| `npm run lint` depuis `frontend/` | Réussite |
| `npm run build` depuis `frontend/` | TypeScript et Vite réussis, 200 modules transformés |
| `git diff --check` | Réussite |
| Inspection JS compilé | Absence des chemins `/rôles`, `/évaluations` et de la classe `rôle-chip` |
| Archive publique | 180 fichiers, aucun PHP, `.env` ou contenu de `storage/` |

Pour relancer sous PowerShell sur cette machine :

```powershell
cd D:\projets\evh_platform\backend
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan route:list --path=api --except-vendor
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan test
cd ..\frontend
node scripts/check-api-routes.mjs C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
npm.cmd run lint
npm.cmd run build
```

Les tests HTTP sont exécutés dans Laravel avec SQLite en mémoire. Aucun scénario interactif sur le site Hostinger ni test de sa base MySQL n'a été effectué. Les fonctions hors rôles/évaluations ont fait l'objet du contrôle des URL et méthodes, pas d'un test fonctionnel complet.

## Mise à jour Hostinger

### 1. Identifier la destination existante

Dans le gestionnaire de fichiers Hostinger, ouvrir le dossier du sous-domaine `espace.vasesdhonneurchicoutimi.org`. Retrouver la racine Laravel, reconnaissable à `artisan`, `app/`, `routes/`, `vendor/` et `public/`.

Appelons **LARAVEL** le chemin absolu de ce dossier et **PUBLIC** le chemin du dossier contenant actuellement `index.php`, `index.html`, `.htaccess` et `assets/`. Dans l'architecture fournie, PUBLIC = LARAVEL/public. Le `.htaccess` de la racine Laravel peut aussi rediriger vers ce dossier. Conserver cette organisation.

Exemple uniquement : si `artisan` est à `/home/u123456789/domains/vasesdhonneurchicoutimi.org/public_html/espace/artisan`, la destination est `/home/u123456789/domains/vasesdhonneurchicoutimi.org/public_html/espace/public/`. Le numéro de compte et le chemin réel ne sont pas présents dans la copie locale : ne pas copier cet exemple sans l'adapter. Si l'installation sépare Laravel de `public_html`, utiliser le dossier public réellement servi, qui contient déjà les anciens fichiers du build.

### 2. Transférer le build

1. Télécharger une sauvegarde du `index.html` et des fichiers publics actuels avant remplacement, pour pouvoir revenir au build précédent.
2. Extraire localement `deployment/hostinger-public-20260921.zip`.
3. Transférer tout le contenu de `assets/` vers **PUBLIC/assets/**, en fusionnant les dossiers. Conserver les anciens fichiers aux noms hachés pendant la transition pour les sessions déjà ouvertes.
4. Transférer les autres fichiers de la racine de l'archive vers **PUBLIC/** : `apple-touch-icon.png`, `favicon.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `logo-vh.png`, `manifest.webmanifest`, `sw.js`, `vite.svg`.
5. Transférer **`index.html` en dernier** vers **PUBLIC/index.html**, en remplaçant l'ancien. Il référence notamment `/assets/index-xogXyw-e.js` et `/assets/index-BA55QkP_.css`.

Ne pas créer de sous-dossier `dist` dans PUBLIC. La mise à jour doit donner PUBLIC/index.html et PUBLIC/assets/… directement. Conserver `index.php`, les `.htaccess`, le lien `storage`, les téléversements et `.env`. Aucun remplacement du dossier Laravel complet n'est nécessaire.

Les fichiers `.tsx` sont les sources : les copier dans la copie de développement aux chemins du tableau, ou conserver l'archive sources. Leur seul transfert sur Hostinger ne corrige pas le JavaScript déjà compilé. Les tests et le guide ne sont pas à placer dans le dossier public.

### 3. Commandes et caches

Aucune migration, installation Composer, génération de clé ou compilation sur Hostinger n'est nécessaire pour ce correctif frontend. Il n'est pas nécessaire de vider le cache applicatif ou de modifier les routes Laravel.

Pour contrôler les routes du serveur, activer l'accès SSH puis copier la commande de connexion affichée par Hostinger : [documentation officielle SSH](https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/).

Une fois connecté, depuis **LARAVEL** (remplacer le chemin) :

```sh
cd '/chemin/reel/du/dossier/qui/contient/artisan'
php -v
php artisan route:list --path=api/admin/members --except-vendor
php artisan route:list --path=api/admin/roles --except-vendor
php artisan route:list --path=api/admin/evaluations --except-vendor
```

Le PHP CLI doit être compatible avec le projet (8.3 ou supérieur). Si ces listes correspondent aux routes ci-dessous, aucune autre commande Artisan n'est requise. Si le fichier `routes/api.php` du serveur contient ces routes mais que la liste ne les montre pas, supprimer uniquement le cache de routes et relancer la liste :

```sh
php artisan route:clear
php artisan route:list --path=api/admin --except-vendor
```

Si le fichier de routes du serveur lui-même diffère de la copie fournie, comparer les versions avant de le remplacer : cette correction ne suppose pas que d'autres modifications de production peuvent être écrasées.

Si un CDN ou cache d'hébergement sert encore l'ancien HTML, purger ce cache après transfert. Fermer puis rouvrir l'application, recharger la page en ligne. Pour une PWA encore ancienne, fermer toutes ses fenêtres et essayer en navigation privée ; si nécessaire, effacer les données du site (cela déconnecte le compte), puis se reconnecter. Le service worker existant utilise le réseau pour la navigation et des noms hachés pour les assets ; aucune modification globale des caches n'a été ajoutée au code.

### 4. Vérifier dans le navigateur

1. Vérifier dans le code source de la page que le script d'entrée est `/assets/index-xogXyw-e.js` et qu'il répond en HTTP 200.
2. Se connecter avec un compte ayant `roles.assign` et accès à la fiche membre. Utiliser un membre de test et un rôle autorisé par la hiérarchie du compte.
3. Ouvrir les outils développeur, onglet Réseau, puis attribuer ce rôle. L'appel doit être **POST `/api/admin/members/ID/roles`**, avec `role_key` et `scope_id` si le rôle nécessite une portée, et répondre **200** (`Rôle attribué.`). L'URL ne doit contenir ni `rôles` ni `r%C3%B4les`.
4. Recharger la fiche : le rôle doit toujours être présent. Le retirer : **DELETE `/api/admin/members/ID/roles/ASSIGNMENT_ID`** doit répondre **200** et le rôle doit rester absent après rechargement.
5. Avec `roles.manage`, créer un rôle personnalisé de test, modifier son nom, puis le supprimer : **POST `/api/admin/roles`**, **PUT `/api/admin/roles/ROLE_ID`**, **DELETE `/api/admin/roles/ROLE_ID`**, réponses **200**. Ne pas supprimer un rôle de base ni son propre rôle administrateur.
6. Avec `evaluations.manage` et accès au membre, ouvrir les notes, ajouter une note de test, puis la supprimer : **GET `/api/admin/members/ID/evaluations`** → **200**, **POST** sur la même URL → **201**, **DELETE `/api/admin/evaluations/EVALUATION_ID`** → **200**.

Un 401 indique une session à renouveler ; un 403, une permission ou une restriction métier ; un 422, un champ invalide ou une portée manquante. Un 404 contenant encore le chemin accentué indique qu'un ancien build est toujours chargé. Un 404 sur un chemin corrigé demande de vérifier les routes présentes et les identifiants sur le serveur.

Pour revenir en arrière, restaurer le précédent `index.html` et les fichiers publics sauvegardés, puis recharger/purger le cache HTML. Aucune migration de données n'est à annuler.
