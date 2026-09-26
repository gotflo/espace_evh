<?php

use App\Http\Controllers\Api\Admin\AnnouncementController;
use App\Http\Controllers\Api\Admin\AttendanceController;
use App\Http\Controllers\Api\Admin\EvaluationController;
use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Api\Admin\ExerciseController;
use App\Http\Controllers\Api\Admin\GemController;
use App\Http\Controllers\Api\Admin\MemberController;
use App\Http\Controllers\Api\Admin\OrgController;
use App\Http\Controllers\Api\Admin\RequestController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SpiritualController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\AudienceController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\NewMemberController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\ValidationController;
use App\Http\Controllers\Api\MyFamilyController;
use App\Http\Controllers\Api\MyTribeChangeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\MyAnnouncementController;
use App\Http\Controllers\Api\MyEvaluationController;
use App\Http\Controllers\Api\MyEventController;
use App\Http\Controllers\Api\MyFissController;
use App\Http\Controllers\Api\MyOverviewController;
use App\Http\Controllers\Api\MyExerciseController;
use App\Http\Controllers\Api\MyNotificationController;
use App\Http\Controllers\Api\MyServiceController;
use App\Http\Controllers\Api\MyRequestController;
use App\Http\Controllers\Api\MySpiritualController;
use App\Http\Controllers\Api\MySpiritualProfileController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceController;
use Illuminate\Support\Facades\Route;

// --- Authentification par telephone (OTP SMS) ---
// Rate limiting : bride les tentatives (anti-force brute / anti-spam SMS) par IP.
Route::post('/auth/request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:8,1');
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:12,1');

// --- Abonnement calendrier (Google Agenda, iPhone, Outlook) : jeton personnel dans l'URL ---
Route::get('/calendar/feed/{token}.ics', [CalendarController::class, 'ics'])
    ->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1');

// --- Routes protegees (jeton Sanctum requis) ---
Route::middleware(['auth:sanctum', 'throttle:150,1', \App\Http\Middleware\TrackActivity::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile', [ProfileController::class, 'update']); // POST accepte pour l'upload de photo

    Route::get('/reference', [ReferenceController::class, 'index']);

    // --- Espace fidele : annonces / notifications ---
    Route::get('/me/announcements', [MyAnnouncementController::class, 'index']);
    Route::get('/me/announcements/unread-count', [MyAnnouncementController::class, 'unreadCount']);
    Route::post('/me/announcements/read', [MyAnnouncementController::class, 'markRead']);

    // --- Centre de notifications + notifications push ---
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
    Route::post('/me/services/{department}/join', [MyServiceController::class, 'join']);
    Route::delete('/me/services/{department}', [MyServiceController::class, 'leave']);

    // --- Espace fidele : evenements a venir + participation (RSVP) ---
    Route::get('/me/events', [MyEventController::class, 'index']);
    Route::post('/me/events/{event}/rsvp', [MyEventController::class, 'rsvp']);
    // Agenda personnel (programme depuis le calendrier, visible par soi seul)
    Route::post('/me/events', [MyEventController::class, 'store']);
    Route::put('/me/events/{event}', [MyEventController::class, 'update']);
    Route::delete('/me/events/{event}', [MyEventController::class, 'destroy']);

    // --- Espace fidele : mes demandes aux responsables ---
    Route::get('/me/requests', [MyRequestController::class, 'index']);
    Route::post('/me/requests', [MyRequestController::class, 'store']);

    // --- Espace fidele : apercu gamifie (assiduite, vertumetre, ponctualite) ---
    Route::get('/me/overview', [MyOverviewController::class, 'index']);

    // --- Espace fidele : fiche de sante spirituelle (FISS, mensuelle) ---
    Route::get('/me/fiss', [MyFissController::class, 'index']);
    Route::post('/me/fiss', [MyFissController::class, 'store']);
    // Fiche verrouillee : modification uniquement apres une demande approuvee (2 max par fiche).
    Route::put('/me/fiss/{form}', [MyFissController::class, 'update']);
    Route::post('/me/fiss/{form}/edit-requests', [MyFissController::class, 'requestEdit'])->middleware('throttle:member-requests');
    Route::delete('/me/fiss/edit-requests/{editRequest}', [MyFissController::class, 'cancelRequest']);

    // --- Changement de tribu (sur demande, validee par les responsables) ---
    Route::get('/me/tribe-change', [MyTribeChangeController::class, 'index']);
    Route::post('/me/tribe-change', [MyTribeChangeController::class, 'store'])->middleware('throttle:member-requests');
    Route::delete('/me/tribe-change/{tribeRequest}', [MyTribeChangeController::class, 'destroy']);

    // --- Famille (conjoint, enfants) ---
    Route::get('/me/family', [MyFamilyController::class, 'show']);
    Route::get('/me/family/search', [MyFamilyController::class, 'search'])->middleware('throttle:member-search');
    Route::put('/me/family/spouse', [MyFamilyController::class, 'setSpouse']);
    Route::put('/me/family/children', [MyFamilyController::class, 'setChildren']);
    Route::post('/me/family/links/{link}/confirm', [MyFamilyController::class, 'confirm']);
    Route::post('/me/family/links/{link}/decline', [MyFamilyController::class, 'decline']);

    // --- Espace fidele : mes notes / evaluations ---
    Route::get('/me/evaluations', [MyEvaluationController::class, 'index']);

    // --- Espace fidele : mon profil spirituel (conversion, baptemes, dons...) ---
    Route::get('/me/spiritual-profile', [MySpiritualProfileController::class, 'show']);
    Route::put('/me/spiritual-profile', [MySpiritualProfileController::class, 'update']);

    // --- Espace fidele : mon journal spirituel (self-service) ---
    Route::get('/me/spiritual', [MySpiritualController::class, 'show']);
    Route::post('/me/spiritual/entries', [MySpiritualController::class, 'storeEntry']);
    Route::delete('/me/spiritual/entries/{entry}', [MySpiritualController::class, 'destroyEntry']);
    Route::post('/me/spiritual/milestones', [MySpiritualController::class, 'toggleMilestone']);

    // --- Espace fidele : mes exercices ---
    Route::get('/me/exercises', [MyExerciseController::class, 'index']);
    Route::post('/me/exercises/{exercise}/respond', [MyExerciseController::class, 'respond'])
        ->middleware('permission:exercises.respond');

    // --- Administration (gestion des membres et des roles) ---
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [StatsController::class, 'index']);

        // Destinataires possibles d'une publication (tribus / GEMs / departements de sa portee).
        Route::get('/audiences', [AudienceController::class, 'index']);

        // Centre de validation : demandes de modification de FISS, changements de tribu.
        Route::get('/validations', [ValidationController::class, 'index']);
        Route::post('/validations/fiss/{editRequest}', [ValidationController::class, 'decideFiss'])->middleware('permission:fiss.review');
        Route::post('/validations/tribe/{tribeRequest}', [ValidationController::class, 'decideTribe'])->middleware('permission:tribes.transfer');
        Route::get('/members/{user}/fiss-history', [ValidationController::class, 'fissHistory']);

        // Rapports (tribu, mes tribus, eglise) et liste detaillee des membres.
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/options', [ReportController::class, 'options']);
            Route::get('/reports', [ReportController::class, 'show']);
            Route::get('/reports/members', [ReportController::class, 'members']);
            Route::post('/reports/exported', [ReportController::class, 'logExport']);
        });

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
        Route::get('/members/{user}/fiss', [App\Http\Controllers\Api\Admin\FissController::class, 'index'])
            ->middleware('permission:spiritual.view');
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

        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.assign');
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
        Route::middleware('permission:exercises.assign')->group(function () {
            Route::get('/exercises', [ExerciseController::class, 'index']);
            Route::post('/exercises', [ExerciseController::class, 'store']);
            Route::delete('/exercises/{exercise}', [ExerciseController::class, 'destroy']);
            Route::get('/exercises/{exercise}/responses', [ExerciseController::class, 'responses']);
        });

        // Annonces / communication
        Route::middleware('permission:announcements.publish')->group(function () {
            Route::get('/announcements', [AnnouncementController::class, 'index']);
            Route::post('/announcements', [AnnouncementController::class, 'store']);
            Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
        });

        // Evenements de l'eglise
        Route::middleware('permission:events.manage')->group(function () {
            Route::get('/events', [EventController::class, 'index']);
            Route::post('/events', [EventController::class, 'store']);
            Route::put('/events/{event}', [EventController::class, 'update']);
            Route::delete('/events/{event}', [EventController::class, 'destroy']);
            Route::get('/events/{event}/participants', [EventController::class, 'participants']);
        });

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
