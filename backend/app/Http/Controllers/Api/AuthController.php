<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedPhone;
use App\Models\Department;
use App\Models\Gem;
use App\Models\ManagerDevice;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\MembershipService;
use App\Services\Monitoring\Monitor;
use App\Services\OtpService;
use App\Services\Sms\TwilioVerifyException;
use App\Support\DepartmentLeadership;
use App\Support\LocalTestMode;
use App\Support\Phone;
use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use App\Support\ProfileCompletion;
use App\Support\Registration;
use App\Support\SecurityJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** Etape 1 : le fidele saisit son numero, on envoie un code par SMS. */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $phone = Phone::normalize($data['phone'], $data['country'] ?? 'CA');
        if (! $phone) {
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro de téléphone n\'est pas valide.',
            ]);
        }

        // Compte bloque depuis la console : aucun code envoye (ni SMS facture).
        if (User::where('phone', $phone)->whereNotNull('blocked_at')->exists()) {
            Monitor::event('warning', 'security', 'auth.blocked', 'Demande de code pour un compte bloqué', ['phone' => '…'.substr($phone, -4)], 'denied');
            throw ValidationException::withMessages([
                'phone' => 'Ce compte est suspendu. Contactez un responsable de l\'église.',
            ]);
        }

        // Numero d'un compte supprime : plus d'inscription possible (aucun SMS envoye).
        if (self::isBlockedForSignup($phone)) {
            Monitor::event('warning', 'security', 'auth.blocked_phone', 'Inscription refusée : numéro d\'un compte supprimé', ['phone' => '…'.substr($phone, -4)], 'denied');
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro ne peut plus être utilisé pour s\'inscrire. Contactez l\'église.',
            ]);
        }

        // Numero de gestion reserve : jamais de code SMS. Sur un appareil de confiance, il entre directement ;
        // sinon l'appareil doit d'abord etre autorise (une seule fois) avec un code genere sur le serveur.
        if (PlatformManager::isReservedPhone($phone)) {
            $manager = PlatformManager::lockedFor() ? null : PlatformManager::userForDevice($request->input('device_token'));
            if ($manager) {
                $manager->forceFill(['last_login_at' => Carbon::now()])->save();
                SecurityJournal::record('manager.login', [], 'info', $manager);
                Monitor::event('info', 'auth', 'auth.login', 'Connexion du compte de gestion', [], 'success', $manager->id);

                return $this->managerSession($manager);
            }
            SecurityJournal::record('manager.device_unknown', [], 'warning');

            return response()->json([
                'manager_device_required' => true,
                'phone' => $phone,
                'message' => 'Appareil pas encore autorisé : saisissez le code d\'autorisation remis par le propriétaire de la plateforme.',
            ]);
        }

        // Essais locaux seulement (TEST_DIRECT_LOGIN et APP_ENV=local) : connexion sans etape de code.
        if (self::directLoginForTests()) {
            return $this->loginVerifiedPhone($phone);
        }

        if ($this->otp->isThrottled($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Un code vient déjà d\'être envoyé. Patientez un instant avant de réessayer.',
            ]);
        }

        try {
            $code = $this->otp->sendCode($phone);
        } catch (TwilioVerifyException $e) {
            // Numero refuse par Twilio : message clair sous le champ. Panne ou mauvaise configuration :
            // journalisee (sans le numero complet), et le fidele sait qu'il peut reessayer.
            Log::log($e->isAboutThePhone() ? 'warning' : 'error', 'OTP : envoi Twilio Verify impossible', [
                'phone' => '…'.substr($phone, -4), 'http' => $e->httpStatus, 'twilio_code' => $e->twilioCode, 'error' => $e->getMessage(),
            ]);
            abort_unless($e->isAboutThePhone(), 503, $e->userMessage());
            throw ValidationException::withMessages(['phone' => $e->userMessage()]);
        }

        Monitor::event('info', 'auth', 'auth.otp_requested', 'Code de connexion demandé', ['phone' => '…'.substr($phone, -4)], 'success');

        $payload = [
            'message' => 'Un code de vérification a été envoyé par SMS.',
            'phone' => $phone,
        ];

        // Le code de test n'est renvoye qu'en mode local/log, jamais pour Twilio Verify.
        // Code affiche a l'ecran : essais seulement, jamais en production (meme si EXPOSE_OTP y etait laisse a true).
        if ($code !== null && (app()->environment('local') || (config('app.expose_otp') && ! app()->environment('production')))) {
            $payload['dev_code'] = $code;
        }

        return response()->json($payload);
    }

    /** Etape 2 : le fidele saisit le code. Si valide, on le connecte. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $phone = Phone::normalize($data['phone']);
        if (PlatformManager::isReservedPhone($phone)) {
            SecurityJournal::record('manager.otp_refused', [], 'warning');
            throw ValidationException::withMessages(['code' => 'Ce numéro se connecte avec son code d\'accès, pas avec un code SMS.']);
        }
        try {
            $valid = $phone && $this->otp->verify($phone, $data['code']);
        } catch (TwilioVerifyException $e) {
            Log::error('OTP : vérification Twilio Verify impossible', ['http' => $e->httpStatus, 'twilio_code' => $e->twilioCode, 'error' => $e->getMessage()]);
            abort(503, 'La vérification du code est momentanément indisponible. Réessayez dans quelques minutes.');
        }
        if (! $valid) {
            Monitor::event('notice', 'auth', 'auth.otp_failed', 'Code de connexion erroné ou expiré', ['phone' => $phone ? '…'.substr($phone, -4) : null], 'failure');
            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré.',
            ]);
        }

        return $this->loginVerifiedPhone($phone);
    }

    /**
     * Connexion d'un numero dont la possession est prouvee (code SMS verifie) : compte cree au premier
     * passage, sinon retrouve ; jeton de session.
     */
    private function loginVerifiedPhone(string $phone): JsonResponse
    {
        if (User::where('phone', $phone)->whereNotNull('blocked_at')->exists()) {
            Monitor::event('warning', 'security', 'auth.blocked', 'Connexion refusée : compte bloqué', ['phone' => '…'.substr($phone, -4)], 'denied');
            abort(403, 'Ce compte est suspendu. Contactez un responsable de l\'église.');
        }

        // Code demande juste avant la suppression du compte : pas de nouveau compte pour autant.
        abort_if(self::isBlockedForSignup($phone), 403, 'Ce numéro ne peut plus être utilisé pour s\'inscrire. Contactez l\'église.');

        // Cree le compte au premier passage, sinon le retrouve. Un nouveau compte attend la validation du
        // pasteur (sauf comptes predefinis de la mise en ligne).
        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['phone_verified_at' => Carbon::now()],
        );
        if ($user->wasRecentlyCreated && ! isset(self::PREDEFINED_ROLES[$phone])) {
            $user->forceFill(['approval_status' => User::PENDING])->save();
        }

        $user->forceFill([
            'phone_verified_at' => $user->phone_verified_at ?? Carbon::now(),
            'last_login_at' => Carbon::now(),
        ])->save();

        // Essais locaux : une inscription deja envoyee et encore en attente est validee a la connexion.
        if (LocalTestMode::active() && $user->approval_status === User::PENDING && $user->approval_requested_at !== null) {
            MembershipService::approveForLocalTests($user);
            $user->refresh();
        }

        // Une connexion est une activite : derniere connexion enregistree, membre inactif reactive.
        ActivityService::touch($user, true, 'login');

        // Cree un profil vide s'il n'existe pas encore.
        $profile = Profile::firstOrCreate(['user_id' => $user->id]);

        // Nouveau compte : role "fidele" par defaut.
        if ($user->wasRecentlyCreated) {
            $fidele = Role::where('key', 'fidele')->first();
            if ($fidele) {
                $user->roles()->syncWithoutDetaching([$fidele->id]);
            }
        }

        // Comptes predefinis (bootstrap) : role(s) attribue(s) automatiquement a la connexion.
        $this->applyPredefinedRoles($user, $phone);

        $isNewAccount = $user->wasRecentlyCreated;
        Monitor::event('info', 'auth', 'auth.login', $isNewAccount ? 'Première connexion (nouveau compte)' : 'Connexion', [], 'success', $user->id);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json(array_merge(
            ['token' => $token, 'is_new_account' => $isNewAccount],
            $this->authPayload($user->fresh()),
        ));
    }

    /** Reponse de connexion du compte de gestion (meme forme que la connexion par code SMS). */
    private function managerSession(User $user, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(
            ['token' => $user->createToken('mobile')->plainTextToken, 'is_new_account' => false],
            $extra,
            $this->authPayload($user->fresh()),
        ));
    }

    /**
     * Autorise cet appareil pour le compte de gestion (et l'active a la premiere fois) avec le code a usage
     * unique genere sur le serveur. L'appareil recoit son jeton : ensuite, le numero suffit sur cet appareil.
     * Codes faux consignes dans le journal de securite ; verrouillage 15 min apres 5 erreurs.
     */
    public function activateManager(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'activation_code' => ['required', 'string', 'max:40'],
        ]);
        $phone = Phone::normalize($data['phone'], 'TG');
        if (! PlatformManager::isReservedPhone($phone)) {
            throw ValidationException::withMessages(['activation_code' => 'Code d\'autorisation invalide.']);
        }
        if ($seconds = PlatformManager::lockedFor()) {
            SecurityJournal::record('manager.locked_attempt', [], 'warning');
            abort(429, 'Trop d\'essais manqués : réessayez dans '.max(1, (int) ceil($seconds / 60)).' min.');
        }
        if (User::where('phone', $phone)->whereNotNull('blocked_at')->exists()) {
            SecurityJournal::record('manager.blocked_account', [], 'alert');
            abort(403, 'Ce compte est suspendu. Contactez un responsable de l\'église.');
        }

        $code = PlatformManager::consumeActivationCode($data['activation_code']);
        if (! $code) {
            $left = PlatformManager::registerFailure();
            SecurityJournal::record('manager.activation_failed', ['attempts_left' => $left], 'alert');
            throw ValidationException::withMessages(['activation_code' => $left > 0
                ? "Code d'autorisation invalide ou expiré. Essais restants : {$left}."
                : 'Code d\'autorisation invalide. Compte verrouillé '.PlatformManager::LOCK_MINUTES.' min.']);
        }
        PlatformManager::clearFailures();

        // Premiere fois : activation du compte. Ensuite : simple ajout d'un appareil (les autres restent connectes).
        $user = User::where('phone', $phone)->first();
        if (! $user?->isPlatformManager()) {
            [$user, $previous] = PlatformManager::activate($phone);
            SecurityJournal::record('manager.activated', $previous, 'warning', $user);
            Monitor::event('warning', 'security', 'manager.activated', 'Compte de gestion activé', [], 'success', $user->id);
        }
        $deviceToken = PlatformManager::issueDevice($user, (string) $request->userAgent());
        $code->forceFill(['used_by' => $user->id])->save();
        SecurityJournal::record('manager.device_authorized', ['devices' => ManagerDevice::where('user_id', $user->id)->whereNull('revoked_at')->count()], 'warning', $user);

        return $this->managerSession($user, ['device_token' => $deviceToken]);
    }

    /**
     * Comptes predefinis (bootstrap de la mise en ligne) : certains numeros recoivent
     * automatiquement leur(s) role(s) a la connexion (idempotent, se repare tout seul).
     */
    private const PREDEFINED_ROLES = [
        '+14185500785' => ['super_admin'],            // Pasteur Resident
        '+14187181876' => ['fidele', 'administrateur'], // Membre + attribution de roles
    ];

    private function applyPredefinedRoles(User $user, string $phone): void
    {
        $keys = self::PREDEFINED_ROLES[$phone] ?? null;
        if (! $keys) {
            return;
        }

        $roleIds = Role::whereIn('key', $keys)->pluck('id');
        if ($roleIds->isNotEmpty()) {
            $user->roles()->syncWithoutDetaching($roleIds->all());
        }
    }

    /** Utilisateur connecte + son profil + ses roles/permissions. */
    /**
     * Connexion directe pour les essais : jamais en production (environnement local exige, en plus de l'option).
     */
    public static function directLoginForTests(): bool
    {
        return LocalTestMode::active();
    }

    /** Numero bloque (compte supprime) et aucun compte existant avec ce numero. */
    private static function isBlockedForSignup(string $phone): bool
    {
        return BlockedPhone::where('phone', $phone)->exists() && ! User::where('phone', $phone)->exists();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        // Essais locaux : l'ecran d'attente (qui interroge /me) s'ouvre des que l'inscription est envoyee.
        if (LocalTestMode::active() && $user->approval_status === User::PENDING && $user->approval_requested_at !== null) {
            MembershipService::approveForLocalTests($user);
            $user->refresh();
        }

        // Pastille des notifications affichee des l'ouverture (sans attendre le premier « pouls »).
        return response()->json($this->authPayload($user) + ['unread' => $user->notifications()->whereNull('read_at')->count()]);
    }

    /** Charge et met en forme les informations du compte connecte (et les reglages utiles a l'application). */
    private function authPayload(User $user): array
    {
        return $this->accountPayload($user) + ['settings' => PlatformSettings::client()];
    }

    private function accountPayload(User $user): array
    {
        // Compte de gestion principal : pas de fiche de membre ni de role, permissions de gestion seulement.
        if ($user->isPlatformManager()) {
            return [
                'user' => $user->only(['id', 'phone']) + ['last_seen_at' => $user->last_seen_at?->toIso8601String()],
                'profile' => null,
                'profile_completed' => true,
                'registration' => ['tribe_required' => false, 'reopened' => false],
                'completion' => null,
                'membership' => ['status' => User::APPROVED, 'submitted' => true],
                'is_super_admin' => false,
                'is_platform_manager' => true,
                'roles' => [],
                'permissions' => PlatformManager::permissions(),
            ];
        }

        $user->load('profile.tribe', 'profile.departments', 'roles.permissions', 'ledDepartments:id,name');

        // Responsabilite de departement : affichee comme une fonction (ce n'est plus un role).
        $leaderships = $user->ledDepartments->map(fn ($d) => [
            'key' => 'department_leader',
            'name' => 'Responsable de département',
            'scope_kind' => 'department',
            'scope_id' => $d->id,
            'scope_name' => $d->name,
        ]);
        // Le role « Responsable de departement » d'un departement dirige est deja dans $leaderships : pas de doublon.
        $roles = $user->roles->reject(fn ($r) => DepartmentLeadership::isLeaderRole($r, $r->pivot->scope_kind)
            && $user->ledDepartments->contains('id', (int) $r->pivot->scope_id));

        return [
            // Derniere visite AVANT celle-ci (mise a jour apres la reponse) : sert a l'accueil
            // « Content de vous revoir » avec les nouveautes depuis cette date.
            'user' => $user->only(['id', 'phone']) + ['last_seen_at' => $user->last_seen_at?->toIso8601String()],
            'profile' => $user->profile,
            // Inscription terminee (tribu comprise) : sinon l'application reste sur l'ecran d'inscription.
            'profile_completed' => Registration::isComplete($user),
            'registration' => [
                'tribe_required' => Registration::requiresTribe($user),
                'reopened' => Registration::isReopened($user),
            ],
            'completion' => $user->profile ? ProfileCompletion::for($user->profile) : null,
            // Validation du compte par le pasteur : pending (profil envoye ou non), rejected, approved.
            'membership' => [
                'status' => $user->approval_status ?? User::APPROVED,
                'submitted' => $user->approval_requested_at !== null,
            ],
            'is_super_admin' => $user->isSuperAdmin(),
            'is_platform_manager' => false,
            'roles' => $roles->map(fn ($r) => [
                'key' => $r->key,
                'name' => $r->name,
                'scope_kind' => $r->pivot->scope_kind,
                'scope_id' => $r->pivot->scope_id,
                'scope_name' => $this->scopeName($r->pivot->scope_kind, $r->pivot->scope_id),
            ])->concat($leaderships)->values(),
            'permissions' => $user->isSuperAdmin()
                ? ['*']
                : $user->permissionKeys()->values(),
        ];
    }

    private function scopeName(?string $kind, ?int $id): ?string
    {
        if (! $kind || ! $id) {
            return null;
        }

        return match ($kind) {
            'tribe' => Tribe::find($id)?->name,
            'gem' => Gem::find($id)?->name,
            'member' => Profile::where('user_id', $id)->first()?->full_name,
            default => Department::find($id)?->name,
        };
    }

    /** Deconnexion : revoque le jeton courant. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
