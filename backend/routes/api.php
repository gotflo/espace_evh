<?php

use App\Http\Controllers\Api\Admin\AccountController;
use App\Http\Controllers\Api\Admin\AnnouncementController;
use App\Http\Controllers\Api\Admin\AppearanceController as AdminAppearanceController;
use App\Http\Controllers\Api\Admin\AppointmentController;
use App\Http\Controllers\Api\Admin\AssignmentController;
use App\Http\Controllers\Api\Admin\AttendanceController;
use App\Http\Controllers\Api\Admin\AudienceController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\EvaluationController;
use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Api\Admin\ExerciseController;
use App\Http\Controllers\Api\Admin\FissController;
use App\Http\Controllers\Api\Admin\GemController;
use App\Http\Controllers\Api\Admin\GemReportController;
use App\Http\Controllers\Api\Admin\LeaderDirectoryController;
use App\Http\Controllers\Api\Admin\LeaderReportController;
use App\Http\Controllers\Api\Admin\MeditationController;
use App\Http\Controllers\Api\Admin\MemberController;
use App\Http\Controllers\Api\Admin\MemberDeletionController;
use App\Http\Controllers\Api\Admin\NewMemberController;
use App\Http\Controllers\Api\Admin\OrgController;
use App\Http\Controllers\Api\Admin\PlatformController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\RequestController;
use App\Http\Controllers\Api\Admin\ResolutionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ServiceScheduleController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SpiritualController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\ValidationController;
use App\Http\Controllers\Api\Admin\VerseController;
use App\Http\Controllers\Api\Admin\VertumetreController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AppearanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\EmailActionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MyAnnouncementController;
use App\Http\Controllers\Api\MyAppointmentController;
use App\Http\Controllers\Api\MyAssignmentController;
use App\Http\Controllers\Api\MyEmailController;
use App\Http\Controllers\Api\MyEvaluationController;
use App\Http\Controllers\Api\MyEventController;
use App\Http\Controllers\Api\MyExerciseController;
use App\Http\Controllers\Api\MyFamilyController;
use App\Http\Controllers\Api\MyFissController;
use App\Http\Controllers\Api\MyHomeController;
use App\Http\Controllers\Api\MyMeditationController;
use App\Http\Controllers\Api\MyMonthController;
use App\Http\Controllers\Api\MyNotificationController;
use App\Http\Controllers\Api\MyNotificationPrefsController;
use App\Http\Controllers\Api\MyOverviewController;
use App\Http\Controllers\Api\MyRequestController;
use App\Http\Controllers\Api\MyResolutionController;
use App\Http\Controllers\Api\MyServiceController;
use App\Http\Controllers\Api\MySpiritualController;
use App\Http\Controllers\Api\MySpiritualProfileController;
use App\Http\Controllers\Api\MyTribeChangeController;
use App\Http\Controllers\Api\MyVertumetreController;
use App\Http\Controllers\Api\MyWelcomeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PulseController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Middleware\EnsureApprovedMember;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\PlatformManagerGuard;
use App\Http\Middleware\TrackActivity;
use App\Http\Middleware\VerifyAgentSignature;
use Illuminate\Support\Facades\Route;

// --- Authentification par telephone (OTP SMS) ---
// Rate limiting : bride les tentatives (anti-force brute / anti-spam SMS) par IP.
// Limites propres a chaque route (3e parametre = compteur separe) : sans lui, toutes les limites d'une meme
// adresse IP ou d'un meme compte partageaient un seul compteur (une limite de 8 comptait aussi les autres appels).
// Par adresse : assez large pour le Wi-Fi de l'eglise (une seule adresse pour tous le dimanche). La fraude
// est arretee ailleurs : 45 s entre deux codes et verrouillage par numero, pays acceptes, plafond global de SMS.
Route::post('/auth/request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:20,1,otp-request');
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:30,1,otp-verify');
// Compte de gestion principal (sans code SMS) : le numero suffit sur un appareil de confiance ; un nouvel
// appareil s'autorise une fois avec le code genere sur le serveur. Essais limites (adresse + verrouillage).
Route::post('/auth/manager-activation', [AuthController::class, 'activateManager'])->middleware('throttle:manager-activation');

// --- Abonnement calendrier (Google Agenda, iPhone, Outlook) : jeton personnel dans l'URL ---
Route::get('/calendar/feed/{token}.ics', [CalendarController::class, 'ics'])
    ->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1,calendar-feed');

// --- Etat de sante (surveillance externe), sans donnee sensible ---
Route::get('/health', HealthController::class)->middleware('throttle:30,1,health');
// Apparence (images et textes affiches, aussi sur l'ecran de connexion) : publique, sans donnee personnelle.
Route::get('/appearance', [AppearanceController::class, 'show'])->middleware('throttle:60,1,appearance');

// --- Boutons « Accepter / Rejeter » du courriel de demande de suppression (lien signe, nominatif) ---
// Le lien (GET) affiche une page qui envoie aussitot la decision (POST) ; signature verifiee dans le controleur.
Route::get('/email-actions/deletion/{deletionRequest}/{decision}/{pastor}', [EmailActionController::class, 'showDeletion'])
    ->whereIn('decision', ['approve', 'reject'])->whereNumber('pastor')->name('email.deletion')->middleware('throttle:30,1,email-actions');
Route::post('/email-actions/deletion/{deletionRequest}/{decision}/{pastor}', [EmailActionController::class, 'decideDeletion'])
    ->whereIn('decision', ['approve', 'reject'])->whereNumber('pastor')->middleware('throttle:30,1,email-actions');
// --- Boutons « Valider / Rejeter » du courriel d'une nouvelle inscription (meme fonctionnement) ---
Route::get('/email-actions/membership/{member}/{decision}/{pastor}', [EmailActionController::class, 'showMembership'])
    ->whereIn('decision', ['approve', 'reject'])->whereNumber('member')->whereNumber('pastor')->name('email.membership')->middleware('throttle:30,1,email-actions');
Route::post('/email-actions/membership/{member}/{decision}/{pastor}', [EmailActionController::class, 'decideMembership'])
    ->whereIn('decision', ['approve', 'reject'])->whereNumber('member')->whereNumber('pastor')->middleware('throttle:30,1,email-actions');

// Accuse de reception d'un push, envoye par l'appareil (service worker, sans session) :
// l'adresse d'abonnement, connue seulement du navigateur et du serveur, identifie l'appareil.
Route::post('/push/receipt', [MyNotificationController::class, 'receipt'])->middleware('throttle:60,1,push-receipt');

// Erreurs d'affichage remontees par le navigateur (supervision), sans session obligatoire.
Route::post('/monitor/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:client-errors');

// --- Agent de supervision : API de controle appelee par la console (projet evh_monitoring) ---
// Chaque requete est signee (HMAC-SHA256, secret partage MONITOR_AGENT_SECRET, horodatage, anti-rejeu).
// Limite AVANT la signature : un flot de requetes non signees est freine sans calcul ni ecriture.
Route::prefix('agent')->middleware(['throttle:agent', VerifyAgentSignature::class])->group(function () {
    Route::get('/health', [AgentController::class, 'health']);
    Route::get('/packages', [AgentController::class, 'packages']);
    Route::get('/logs/files', [AgentController::class, 'logFiles']);
    Route::get('/logs/files/{name}', [AgentController::class, 'logFile'])->where('name', '[A-Za-z0-9._-]+');
    Route::get('/users/{user}/impact', [AgentController::class, 'impact'])->whereNumber('user');
    Route::post('/users/{user}/block', [AgentController::class, 'block'])->whereNumber('user');
    Route::post('/users/{user}/unblock', [AgentController::class, 'unblock'])->whereNumber('user');
    Route::post('/users/{user}/revoke-sessions', [AgentController::class, 'revokeSessions'])->whereNumber('user');
    Route::delete('/users/{user}', [AgentController::class, 'destroy'])->whereNumber('user');
    Route::post('/actions/{action}', [AgentController::class, 'action'])->where('action', '[a-z_]+');
    Route::post('/notify', [AgentController::class, 'notify']);
});

// --- Routes protegees (jeton Sanctum requis) ---
// Compte pas encore valide par le pasteur : seul l'ecran d'attente repond (EnsureApprovedMember).
Route::middleware(['auth:sanctum', 'throttle:150,1', EnsureNotBlocked::class, TrackActivity::class, EnsureApprovedMember::class, PlatformManagerGuard::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:profile-photo');
    Route::post('/profile', [ProfileController::class, 'update'])->middleware('throttle:profile-photo'); // POST accepte pour l'upload de photo

    Route::get('/reference', [ReferenceController::class, 'index']);

    // Tableau de bord : ses lectures regroupees en un seul appel.
    Route::get('/me/home', MyHomeController::class);

    // --- Espace fidele : annonces / notifications ---
    Route::get('/me/announcements', [MyAnnouncementController::class, 'index']);
    Route::get('/me/announcements/unread-count', [MyAnnouncementController::class, 'unreadCount']);
    Route::post('/me/announcements/read', [MyAnnouncementController::class, 'markRead']);

    // --- Centre de notifications + notifications push ---
    Route::get('/me/pulse', [PulseController::class, 'show']);
    Route::get('/dashboard/verse', [VerseController::class, 'current']);
    Route::get('/me/welcome-back', [MyWelcomeController::class, 'show']);
    Route::get('/me/notification-prefs', [MyNotificationPrefsController::class, 'show']);
    Route::put('/me/notification-prefs', [MyNotificationPrefsController::class, 'update']);
    // Adresse courriel ajoutee en un appui (bandeau du tableau de bord, preferences).
    Route::put('/me/email', [MyEmailController::class, 'update'])->middleware('throttle:member-requests');
    Route::get('/me/notifications', [MyNotificationController::class, 'index']);
    Route::get('/me/notifications/unread-count', [MyNotificationController::class, 'unreadCount']);
    Route::post('/me/notifications/read-all', [MyNotificationController::class, 'markAllRead']);
    Route::post('/me/notifications/read-url', [MyNotificationController::class, 'markReadByUrl']);
    Route::delete('/me/notifications/clear-read', [MyNotificationController::class, 'clearRead']);
    Route::post('/me/notifications/{notification}/read', [MyNotificationController::class, 'markRead']);
    Route::delete('/me/notifications/{notification}', [MyNotificationController::class, 'destroy']);
    Route::get('/me/push', [MyNotificationController::class, 'pushConfig']);
    Route::post('/me/push/subscribe', [MyNotificationController::class, 'subscribe']);
    Route::post('/me/push/unsubscribe', [MyNotificationController::class, 'unsubscribe']);
    Route::post('/me/push/test', [MyNotificationController::class, 'test']);

    // --- Calendrier (tout ce qui est programme) ---
    Route::get('/calendar', [CalendarController::class, 'index']);
    Route::get('/calendar/feed-url', [CalendarController::class, 'feed']);
    Route::post('/calendar/feed-url/reset', [CalendarController::class, 'resetFeed']);

    // --- Servir : inscription a un service (departement) ---
    Route::get('/me/services', [MyServiceController::class, 'index']);
    Route::post('/me/services/{department}/join', [MyServiceController::class, 'join'])->middleware('throttle:member-writes');
    Route::delete('/me/services/{department}', [MyServiceController::class, 'leave'])->middleware('throttle:member-writes');

    // --- Espace fidele : evenements a venir + participation (RSVP) ---
    Route::get('/me/events', [MyEventController::class, 'index']);
    Route::post('/me/events/{event}/rsvp', [MyEventController::class, 'rsvp']);
    // Agenda personnel (programme depuis le calendrier, visible par soi seul)
    Route::post('/me/events', [MyEventController::class, 'store'])->middleware('throttle:member-writes');
    Route::put('/me/events/{event}', [MyEventController::class, 'update'])->middleware('throttle:member-writes');
    Route::delete('/me/events/{event}', [MyEventController::class, 'destroy']);

    // --- Espace fidele : mes demandes aux responsables ---
    Route::get('/me/requests', [MyRequestController::class, 'index']);
    Route::post('/me/requests', [MyRequestController::class, 'store'])->middleware('throttle:member-requests');

    // --- Espace fidele : apercu gamifie (assiduite, vertumetre, ponctualite) ---
    Route::get('/me/overview', [MyOverviewController::class, 'index']);

    // --- Espace fidele : fiche de sante spirituelle (FISS, mensuelle) ---
    Route::get('/me/fiss', [MyFissController::class, 'index']);
    Route::post('/me/fiss', [MyFissController::class, 'store']);
    // Fiche verrouillee : modification uniquement apres une demande approuvee (2 max par fiche).
    Route::put('/me/fiss/{form}', [MyFissController::class, 'update']);
    Route::post('/me/fiss/{form}/edit-requests', [MyFissController::class, 'requestEdit'])->middleware('throttle:member-requests');
    Route::delete('/me/fiss/edit-requests/{editRequest}', [MyFissController::class, 'cancelRequest']);

    // --- Vertumetre mensuel (verrouille a l'envoi, modification sur demande comme la FISS) ---
    Route::get('/me/vertumetre', [MyVertumetreController::class, 'index'])->middleware('module:vertumetre');
    Route::post('/me/vertumetre', [MyVertumetreController::class, 'store'])->middleware('module:vertumetre');
    Route::put('/me/vertumetre/{form}', [MyVertumetreController::class, 'update'])->whereNumber('form')->middleware('module:vertumetre');
    Route::post('/me/vertumetre/{form}/edit-requests', [MyVertumetreController::class, 'requestEdit'])->whereNumber('form')->middleware('throttle:member-requests')->middleware('module:vertumetre');

    // Prendre rendez-vous avec le pasteur.
    Route::get('/me/appointments', [MyAppointmentController::class, 'index'])->middleware('module:appointments');
    Route::post('/me/appointments', [MyAppointmentController::class, 'store'])->middleware('throttle:member-requests')->middleware('module:appointments');
    Route::post('/me/appointments/{appointment}/cancel', [MyAppointmentController::class, 'cancel'])->middleware('module:appointments');
    Route::delete('/me/vertumetre/edit-requests/{editRequest}', [MyVertumetreController::class, 'cancelRequest'])->middleware('module:vertumetre');

    // --- Mes RM (Résolutions du Mois) et Mon devoir (DS) ---
    Route::get('/me/month', MyMonthController::class);
    Route::get('/me/resolutions', [MyResolutionController::class, 'index'])->middleware('module:resolutions');
    Route::put('/me/resolutions', [MyResolutionController::class, 'save'])->middleware('module:resolutions');
    Route::post('/me/resolutions/review', [MyResolutionController::class, 'review'])->middleware('module:resolutions');
    // Ressource video des RM : suivi de lecture (pourcentage regarde, reprise).
    Route::post('/me/resources/{resource}/progress', [MyResolutionController::class, 'progress'])
        ->whereNumber('resource')->middleware('throttle:video-progress')->middleware('module:resolutions');
    Route::get('/me/assignments', [MyAssignmentController::class, 'index'])->middleware('module:assignments');
    Route::put('/me/assignments/{assignment}/response', [MyAssignmentController::class, 'respond'])->whereNumber('assignment')->middleware('module:assignments');
    Route::post('/me/assignments/{assignment}/progress', [MyAssignmentController::class, 'progress'])
        ->whereNumber('assignment')->middleware('throttle:video-progress')->middleware('module:assignments');

    // --- Changement de tribu (sur demande, validee par les responsables) ---
    Route::get('/me/tribe-change', [MyTribeChangeController::class, 'index']);
    Route::post('/me/tribe-change', [MyTribeChangeController::class, 'store'])->middleware('throttle:member-requests');
    Route::delete('/me/tribe-change/{tribeRequest}', [MyTribeChangeController::class, 'destroy']);

    // --- Famille (conjoint, enfants) ---
    Route::get('/me/family', [MyFamilyController::class, 'show']);
    Route::get('/me/family/search', [MyFamilyController::class, 'search'])->middleware('throttle:member-search');
    Route::put('/me/family/spouse', [MyFamilyController::class, 'setSpouse'])->middleware('throttle:member-writes');
    Route::put('/me/family/children', [MyFamilyController::class, 'setChildren'])->middleware('throttle:member-writes');
    Route::post('/me/family/links/{link}/confirm', [MyFamilyController::class, 'confirm']);
    Route::post('/me/family/links/{link}/decline', [MyFamilyController::class, 'decline']);

    // --- Espace fidele : mes notes / evaluations ---
    Route::get('/me/evaluations', [MyEvaluationController::class, 'index']);

    // --- Espace fidele : mon profil spirituel (conversion, baptemes, dons...) ---
    Route::get('/me/spiritual-profile', [MySpiritualProfileController::class, 'show']);
    Route::put('/me/spiritual-profile', [MySpiritualProfileController::class, 'update']);

    // --- Espace fidele : mon journal spirituel (self-service) ---
    Route::get('/me/spiritual', [MySpiritualController::class, 'show'])->middleware('module:spiritual');
    Route::post('/me/spiritual/entries', [MySpiritualController::class, 'storeEntry'])->middleware(['module:spiritual', 'throttle:member-writes']);
    Route::delete('/me/spiritual/entries/{entry}', [MySpiritualController::class, 'destroyEntry'])->middleware('module:spiritual');
    Route::post('/me/spiritual/milestones', [MySpiritualController::class, 'toggleMilestone'])->middleware('module:spiritual');

    // --- Espace fidele : mes fiches de meditation (personnelles) ---
    Route::get('/me/meditations', [MyMeditationController::class, 'index']);
    Route::post('/me/meditations', [MyMeditationController::class, 'store'])->middleware('throttle:member-writes');
    Route::get('/me/meditations/{sheet}', [MyMeditationController::class, 'show'])->whereNumber('sheet');

    // --- Espace fidele : mes exercices ---
    Route::get('/me/exercises', [MyExerciseController::class, 'index'])->middleware('module:exercises');
    Route::get('/me/exercises/{exercise}', [MyExerciseController::class, 'show'])->middleware('module:exercises');
    Route::post('/me/exercises/{exercise}/progress', [MyExerciseController::class, 'progress'])
        ->middleware('throttle:video-progress')->middleware('module:exercises');
    Route::post('/me/exercises/{exercise}/respond', [MyExerciseController::class, 'respond'])
        ->middleware('permission:exercises.respond')->middleware('module:exercises');

    // --- Administration (gestion des membres et des roles) ---
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [StatsController::class, 'index']);

        // Tableau de bord de gestion (chiffres globaux, sans donnee personnelle) et liste des comptes.
        Route::get('/platform/overview', [PlatformController::class, 'overview'])->middleware('permission:platform.manage');
        // Reglages de la plateforme (rappels automatiques, horaires d'envoi, limites).
        Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
        // Apparence : image de l'accueil et photos de l'ecran de connexion (envoi, cadrage, ordre, retour a l'origine).
        Route::middleware('permission:settings.manage')->prefix('appearance')->group(function () {
            Route::get('/', [AdminAppearanceController::class, 'index']);
            Route::post('/home', [AdminAppearanceController::class, 'uploadHome'])->middleware('throttle:20,1,appearance-upload');
            Route::put('/home', [AdminAppearanceController::class, 'positionHome']);
            Route::delete('/home', [AdminAppearanceController::class, 'resetHome']);
            Route::post('/login', [AdminAppearanceController::class, 'addLogin'])->middleware('throttle:20,1,appearance-upload');
            Route::put('/login', [AdminAppearanceController::class, 'arrangeLogin']);
            Route::delete('/login', [AdminAppearanceController::class, 'resetLogin']);
        });
        Route::get('/accounts', [AccountController::class, 'index'])->middleware('permission:accounts.view');

        // Destinataires possibles d'une publication (tribus / GEMs / departements de sa portee).
        Route::get('/audiences', [AudienceController::class, 'index']);

        // Centre de validation : demandes de modification de FISS, changements de tribu.
        Route::get('/validations', [ValidationController::class, 'index']);
        Route::post('/validations/fiss/{editRequest}', [ValidationController::class, 'decideFiss'])->middleware('permission:fiss.review');
        Route::post('/validations/tribe/{tribeRequest}', [ValidationController::class, 'decideTribe'])->middleware('permission:tribes.transfer');
        Route::post('/validations/vertumetre/{editRequest}', [ValidationController::class, 'decideVertumetre'])->middleware('permission:fiss.review');
        // Inscriptions aux services : responsable du departement (portee verifiee dans le service).
        Route::post('/validations/services/{joinRequest}', [ValidationController::class, 'decideService'])->middleware('permission:departments.approve');
        // Demandes de suppression (PR) : droits verifies dans le controleur.
        Route::post('/validations/deletions/{deletionRequest}', [MemberDeletionController::class, 'decide']);
        Route::get('/deletion-requests/{deletionRequest}', [MemberDeletionController::class, 'showRequest']);
        // Nouvelles inscriptions (pasteur : members.approve, verifie dans le service).
        Route::post('/validations/memberships/{member}', [ValidationController::class, 'decideMembership'])->whereNumber('member');
        Route::get('/membership-requests/{member}', [ValidationController::class, 'showMembership'])->whereNumber('member');

        // Suppression d'un membre (PR) et demandes de suppression (patriarche, responsable de departement).
        Route::get('/members/{user}/deletion', [MemberDeletionController::class, 'show']);
        Route::delete('/members/{user}', [MemberDeletionController::class, 'destroy']);
        Route::post('/members/{user}/deletion-requests', [MemberDeletionController::class, 'requestDeletion'])->middleware('throttle:member-requests');
        Route::get('/blocked-phones', [MemberDeletionController::class, 'blockedPhones']);
        Route::delete('/blocked-phones/{blockedPhone}', [MemberDeletionController::class, 'unblockPhone']);

        // Vertumetre : suivi du mois et reponses d'un membre (memes lecteurs que la FISS).
        Route::get('/vertumetre', [VertumetreController::class, 'index']);

        // Rendez-vous avec le pasteur (pasteur et protocole ; droits verifies dans le service).
        Route::get('/appointments', [AppointmentController::class, 'index'])->middleware('module:appointments');
        Route::post('/appointments/{appointment}/assign', [AppointmentController::class, 'assign'])->middleware('module:appointments');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('module:appointments');
        Route::post('/appointments/{appointment}/outcome', [AppointmentController::class, 'outcome'])->middleware('module:appointments');
        Route::post('/appointments/{appointment}/call', [AppointmentController::class, 'call'])->middleware('throttle:30,1,appointment-call')->middleware('module:appointments');
        Route::post('/appointments/{appointment}/urgency', [AppointmentController::class, 'urgency'])->middleware('module:appointments');
        Route::put('/appointment-availability', [AppointmentController::class, 'saveAvailability'])->middleware('module:appointments');
        Route::post('/appointment-availability/windows', [AppointmentController::class, 'addWindow'])->middleware('module:appointments');
        Route::delete('/appointment-availability/windows/{window}', [AppointmentController::class, 'removeWindow'])->middleware('module:appointments');
        Route::post('/appointment-availability/blocks', [AppointmentController::class, 'block'])->middleware('module:appointments');
        Route::delete('/appointment-availability/blocks/{block}', [AppointmentController::class, 'unblock'])->middleware('module:appointments');
        Route::get('/members/{user}/vertumetre', [VertumetreController::class, 'member']);
        Route::get('/members/{user}/fiss-history', [ValidationController::class, 'fissHistory']);

        // Mes RM et Mon devoir : lecture par les pasteurs et les responsables de la tribu (droits verifies
        // dans les controleurs, meme confidentialite que la FISS).
        Route::get('/resolutions', [ResolutionController::class, 'index']);
        Route::get('/members/{user}/resolutions', [ResolutionController::class, 'member']);
        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::get('/assignments/{assignment}/responses', [AssignmentController::class, 'responses'])->whereNumber('assignment');
        Route::get('/members/{user}/assignments', [AssignmentController::class, 'member']);

        // Rapports (tribu, mes tribus, eglise) et liste detaillee des membres.
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/options', [ReportController::class, 'options']);
            Route::get('/reports', [ReportController::class, 'show']);
            Route::get('/reports/members', [ReportController::class, 'members']);
            // Export PDF : donnees fournies et journalisees par le serveur (permissions d'export verifiees).
            Route::get('/reports/export', [ReportController::class, 'export']);
        });

        // Rapports mensuels des responsables (patriarche : tribu ; responsables : departement).
        // Droits verifies dans le controleur : auteurs, AP de la tribu, autorites pastorales.
        Route::get('/leader-reports', [LeaderReportController::class, 'index']);
        Route::get('/leader-reports/form', [LeaderReportController::class, 'form']);
        Route::put('/leader-reports', [LeaderReportController::class, 'save']);
        Route::get('/leader-reports/{report}', [LeaderReportController::class, 'show'])->whereNumber('report');
        Route::get('/leader-reports/{report}/export', [LeaderReportController::class, 'export'])->whereNumber('report');

        // Rapport hebdomadaire des Gardes (presences du GEM), transmis au patriarche et a l'AP.
        Route::get('/gem-reports', [GemReportController::class, 'index']);
        Route::put('/gem-reports/{gem}', [GemReportController::class, 'save']);

        // Annuaire des responsables et fiche d'un Garde (pasteurs : tous ; AP, patriarches : leurs tribus).
        Route::get('/leaders', [LeaderDirectoryController::class, 'index']);
        Route::get('/leaders/export', [LeaderDirectoryController::class, 'export']);
        Route::get('/leaders/gems/{gem}', [LeaderDirectoryController::class, 'gem']);

        // Journal d'audit (lecture seule).
        Route::get('/audit', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        // Nouveaux inscrits : suivi d'accueil
        Route::get('/new-members', [NewMemberController::class, 'index']);
        Route::post('/members/{user}/welcome', [NewMemberController::class, 'welcome']);
        Route::delete('/members/{user}/welcome', [NewMemberController::class, 'unwelcome']);

        Route::get('/members', [MemberController::class, 'index']);
        Route::get('/members/{user}', [MemberController::class, 'show']);
        Route::patch('/members/{user}/status', [MemberController::class, 'setActivity'])
            ->middleware('permission:members.edit');
        Route::patch('/members/{user}/belonging', [MemberController::class, 'updateBelonging'])
            ->middleware('permission:members.edit');

        // Suivi spirituel (journal + etapes)
        Route::get('/members/{user}/spiritual', [SpiritualController::class, 'show'])
            ->middleware('permission:spiritual.view');
        Route::get('/members/{user}/fiss', [FissController::class, 'index'])
            ->middleware('permission:spiritual.view');
        // Fiches de meditation : patriarche de la tribu et pasteurs (PR, PA), en lecture seule.
        Route::get('/members/{user}/meditations', [MeditationController::class, 'index'])
            ->middleware('permission:meditations.view');
        Route::get('/members/{user}/meditations/{sheet}', [MeditationController::class, 'show'])
            ->whereNumber('sheet')->middleware('permission:meditations.view');
        Route::middleware('permission:spiritual.record')->group(function () {
            Route::post('/members/{user}/spiritual/entries', [SpiritualController::class, 'storeEntry']);
            Route::delete('/members/{user}/spiritual/entries/{entry}', [SpiritualController::class, 'destroyEntry']);
            Route::post('/members/{user}/spiritual/milestones', [SpiritualController::class, 'toggleMilestone']);
        });

        // Notes / evaluations d'un fidele
        Route::middleware('permission:evaluations.manage')->group(function () {
            Route::get('/members/{user}/evaluations', [EvaluationController::class, 'index']);
            Route::post('/members/{user}/evaluations', [EvaluationController::class, 'store']);
            Route::delete('/evaluations/{evaluation}', [EvaluationController::class, 'destroy']);
        });

        // Liste des roles : roles.assign ou roles.manage (verifie dans le controleur).
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/members/{user}/roles', [RoleController::class, 'assign'])->middleware('permission:roles.assign');
        Route::delete('/members/{user}/roles/{assignment}', [RoleController::class, 'remove'])->middleware('permission:roles.assign');

        // Gestion des roles personnalises (creer / modifier / supprimer).
        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('/permissions', [RoleController::class, 'permissions']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        });

        // Presences
        Route::get('/attendance', [AttendanceController::class, 'roster'])->middleware('permission:attendance.view');
        Route::post('/attendance', [AttendanceController::class, 'save'])->middleware('permission:attendance.record');

        // Exercices spirituels (creer / assigner / voir les reponses)
        // Exercices : onglet et avancement global, creation, suppression, reponses de chaque membre.
        Route::get('/exercises', [ExerciseController::class, 'index'])->middleware('permission:exercises.assign');
        Route::post('/exercises', [ExerciseController::class, 'store'])->middleware('permission:exercises.create');
        Route::delete('/exercises/{exercise}', [ExerciseController::class, 'destroy'])->middleware('permission:exercises.delete');
        Route::post('/exercises/video-preview', [ExerciseController::class, 'videoPreview'])->middleware(['permission:exercises.create', 'throttle:member-search']);
        Route::middleware('permission:exercises.view_responses')->group(function () {
            Route::get('/exercises/{exercise}/responses', [ExerciseController::class, 'responses']);
            Route::get('/exercises/{exercise}/tracking', [ExerciseController::class, 'tracking']);
            Route::post('/exercises/{exercise}/remind', [ExerciseController::class, 'remind'])->middleware('throttle:member-requests');
            Route::get('/members/{member}/exercises', [ExerciseController::class, 'member'])->whereNumber('member');
        });

        // Textes bibliques du tableau de bord : consulter, rediger (brouillons), publier, supprimer.
        Route::get('/verses', [VerseController::class, 'index'])->middleware('permission:verses.view');
        Route::get('/verses/{verse}/history', [VerseController::class, 'history'])->middleware('permission:verses.view');
        Route::post('/verses', [VerseController::class, 'store'])->middleware('permission:verses.edit');
        Route::post('/verses/reorder', [VerseController::class, 'reorder'])->middleware('permission:verses.edit');
        Route::put('/verses/{verse}', [VerseController::class, 'update'])->middleware('permission:verses.edit');
        Route::delete('/verses/{verse}', [VerseController::class, 'destroy'])->middleware('permission:verses.delete');

        // Horaires des cultes (rendez-vous hebdomadaires de toute l'eglise).
        Route::get('/service-schedule', [ServiceScheduleController::class, 'index'])->middleware('permission:schedule.view');
        Route::post('/service-schedule', [ServiceScheduleController::class, 'store'])->middleware('permission:schedule.edit');
        Route::put('/service-schedule/{event}', [ServiceScheduleController::class, 'update'])->middleware('permission:schedule.edit');
        Route::delete('/service-schedule/{event}', [ServiceScheduleController::class, 'destroy'])->middleware('permission:schedule.delete');

        // Mon devoir (DS) : publier, modifier (POST pour le fichier audio), retirer.
        Route::post('/assignments', [AssignmentController::class, 'store'])->middleware('permission:assignments.publish');
        Route::post('/assignments/{assignment}', [AssignmentController::class, 'update'])->whereNumber('assignment')->middleware('permission:assignments.publish');
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->whereNumber('assignment')->middleware('permission:assignments.delete');

        // Ressources et liens video (themes des RM : meditation, priere, jeune, culte).
        Route::get('/resolution-resources', [ResolutionController::class, 'resources'])->middleware('permission:resources.view');
        Route::post('/resolution-resources', [ResolutionController::class, 'storeResource'])->middleware('permission:resources.edit');
        Route::put('/resolution-resources/{resource}', [ResolutionController::class, 'updateResource'])->middleware('permission:resources.edit');
        Route::delete('/resolution-resources/{resource}', [ResolutionController::class, 'destroyResource'])->middleware('permission:resources.delete');
        // Apercu d'un lien YouTube (ressources, devoirs) : droits verifies dans le controleur.
        Route::post('/content/video-preview', [ExerciseController::class, 'contentVideoPreview'])->middleware('throttle:member-search');

        Route::get('/announcements', [AnnouncementController::class, 'index'])->middleware('permission:announcements.publish');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('permission:announcements.publish');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('permission:announcements.delete');

        // Evenements de l'eglise
        Route::middleware('permission:events.manage')->group(function () {
            Route::get('/events', [EventController::class, 'index']);
            Route::post('/events', [EventController::class, 'store']);
            Route::put('/events/{event}', [EventController::class, 'update']);
        });
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->middleware('permission:events.delete');
        Route::get('/events/{event}/participants', [EventController::class, 'participants'])->middleware('permission:events.participants');

        // Demandes des fideles (contact)
        Route::middleware('permission:requests.handle')->group(function () {
            Route::get('/requests', [RequestController::class, 'index']);
            Route::get('/requests/new-count', [RequestController::class, 'newCount']);
            Route::patch('/requests/{memberRequest}/status', [RequestController::class, 'updateStatus']);
            Route::post('/requests/{memberRequest}/reply', [RequestController::class, 'reply']);
        });

        // Organisation : tribus et departements.
        Route::get('/organization', [OrgController::class, 'index']);
        Route::middleware('permission:tribes.manage')->group(function () {
            Route::post('/tribes', [OrgController::class, 'storeTribe']);
            Route::put('/tribes/{tribe}', [OrgController::class, 'updateTribe']);
            Route::delete('/tribes/{tribe}', [OrgController::class, 'destroyTribe']);
        });
        Route::middleware('permission:departments.manage')->group(function () {
            Route::post('/departments', [OrgController::class, 'storeDepartment']);
            Route::put('/departments/{department}', [OrgController::class, 'updateDepartment']);
            Route::delete('/departments/{department}', [OrgController::class, 'destroyDepartment']);
            Route::get('/departments/{department}/members', [OrgController::class, 'departmentMembers']);
            Route::get('/departments/{department}/candidates', [OrgController::class, 'departmentCandidates']);
            Route::put('/departments/{department}/leaders', [OrgController::class, 'setDepartmentLeaders']);
        });

        // GEMs (groupes de 3 a 5 membres dans une tribu, menes par un Garde)
        Route::middleware('permission:gems.manage')->group(function () {
            Route::get('/gems', [GemController::class, 'index']);
            Route::get('/gems/candidates', [GemController::class, 'candidates']);
            Route::post('/gems', [GemController::class, 'store']);
            Route::put('/gems/{gem}', [GemController::class, 'update']);
            Route::delete('/gems/{gem}', [GemController::class, 'destroy']);
        });
    });
});
