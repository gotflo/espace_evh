<div align="center">
  <img src="frontend/public/logo-vh.png" alt="Vases d'Honneur Chicoutimi" width="110" />

  # Espace Vases d'Honneur Chicoutimi

  Plateforme de gestion et de suivi des membres de l'eglise Vases d'Honneur Chicoutimi.
  Chaque fidele dispose d'un espace personnel, et les responsables suivent la vie de
  l'eglise selon leur niveau (GEM, tribu, ministere).
</div>

---

## Ce que fait la plateforme

- **Connexion par telephone** avec code de verification (OTP par SMS), sans mot de passe.
- **Espace du fidele** : profil personnel et spirituel, vie spirituelle, journal, temoignages,
  demandes aux responsables, exercices a rendre, evenements a venir.
- **Fiche de sante spirituelle (FISS)** remplie une fois par mois, avec bareme d'indices,
  indicateur d'evolution par rapport au mois precedent et rappels automatiques en fin de mois.
- **Suivi par les responsables** : membres, presences, notes et evaluations, annonces,
  evenements, demandes, le tout limite au perimetre de chacun.
- **Hierarchie de discipulat** : Membre, Responsable GEM (GAD), Patriarche, Assistant Pasteur,
  Pasteur Assistant, Pasteur Resident, plus un role Administrateur pour l'attribution des roles.
- **Statistiques gamifiees** : assiduite, vertumetre (moyenne des notes), parcours, ponctualite.
- **Application installable (PWA)** sur Android et iPhone depuis le navigateur.

## Pile technique

| Partie | Technologies |
|--------|--------------|
| Backend (API) | Laravel 13, PHP 8.3, Sanctum (jetons), Eloquent |
| Base de donnees | MySQL en production, SQLite en developpement |
| Frontend (SPA) | React, TypeScript, Vite, React Router |
| Telephone | libphonenumber (validation et format par pays) |
| Hebergement | Hostinger (Laravel sert l'API et l'application React) |

## Architecture

Un seul domaine sert tout. Laravel expose l'API sous `/api`, sert les fichiers du build React
et renvoie `index.html` pour toutes les autres routes (le routeur React prend le relais).

```
Navigateur
   |
   |  https://espace.vasesdhonneurchicoutimi.org
   v
Laravel (public/)
   |-- /api/*        -> API (controleurs, Sanctum, permissions)
   |-- /storage/*    -> images (photos, annonces, evenements)
   |-- /assets/*     -> build React (JS, CSS)
   |-- reste         -> index.html (application React)
```

Les permissions sont verifiees par un middleware `permission:xxx`, et la portee de chaque
responsable (son GEM, sa tribu ou son departement) est appliquee par un helper central
`MemberScope` sur toutes les listes et diffusions.

## Structure des fichiers

```
evh_platform/
├── backend/                     API Laravel
│   ├── app/
│   │   ├── Console/Commands/    fiss:remind, user:make-admin
│   │   ├── Http/
│   │   │   ├── Controllers/Api/ Auth, Profile, My*, et Admin/*
│   │   │   └── Middleware/      EnsurePermission, SecurityHeaders
│   │   ├── Models/              User, Profile, Role, Tribe, Gem, Department,
│   │   │                        Event, Announcement, Evaluation, SpiritualHealthForm...
│   │   ├── Services/            OtpService, Sms/
│   │   └── Support/             Phone, Audience, MemberScope, LeaderRole, catalogues
│   ├── config/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/             RolesAndPermissionsSeeder, DepartmentsSeeder
│   ├── routes/                  api.php, web.php, console.php
│   └── .env.production.example  modele de configuration pour la mise en ligne
│
├── frontend/                    Application React
│   ├── src/
│   │   ├── api/                 client HTTP, cache des references
│   │   ├── auth/                contexte d'authentification
│   │   ├── components/          composants reutilisables
│   │   ├── pages/               ecrans (+ pages/admin/)
│   │   └── utils/
│   └── public/                  logo, manifest PWA, service worker, icones
│
└── docs/
```

## Installation en local

Prerequis : PHP 8.3, Composer, Node 18 ou plus.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=DepartmentsSeeder
php artisan storage:link
php artisan serve
```

Le premier pasteur (super administrateur) se cree ainsi :

```bash
php artisan user:make-admin "+14185550123"
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

Le serveur Vite proxifie `/api` et `/storage` vers le backend en developpement, ce qui evite
les soucis de CORS. L'application est alors accessible sur `http://localhost:5173`.

## Mise en ligne (Hostinger)

1. Creer une base de donnees MySQL et un sous-domaine dans hPanel.
2. En local : `npm run build` dans `frontend`, puis copier le contenu de `frontend/dist`
   dans `backend/public`.
3. Copier `backend/.env.production.example` en `.env` et renseigner la base, l'URL du
   sous-domaine et les autres valeurs.
4. Envoyer le projet Laravel sur le serveur, avec la racine du sous-domaine pointant sur
   le dossier `public` de Laravel.
5. Sur le serveur : `composer install --no-dev`, `php artisan migrate --force`, les seeders,
   `php artisan storage:link`, puis `php artisan config:cache`.
6. Activer le certificat SSL (HTTPS) et ajouter une tache cron
   `php artisan schedule:run` chaque minute pour les rappels mensuels.

La connexion par SMS necessite un fournisseur (par exemple Twilio) en production. Sans lui,
le code de verification part uniquement dans les journaux.

## Securite

- Jetons Sanctum avec expiration, codes OTP haches et a usage unique.
- Limitation du nombre de tentatives de connexion.
- En-tetes de securite sur chaque reponse, HTTPS force en production.
- Verification des permissions et de la portee sur chaque action sensible.
- Anti-escalade des roles : personne ne peut attribuer un role plus eleve que le sien.
