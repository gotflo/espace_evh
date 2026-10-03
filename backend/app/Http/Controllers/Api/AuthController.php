<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\Monitoring\Monitor;
use App\Services\OtpService;
use App\Services\Sms\TwilioVerifyException;
use App\Support\Phone;
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
        if ($code !== null && (app()->environment('local') || config('app.expose_otp'))) {
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

        if (User::where('phone', $phone)->whereNotNull('blocked_at')->exists()) {
            Monitor::event('warning', 'security', 'auth.blocked', 'Connexion refusée : compte bloqué', ['phone' => '…'.substr($phone, -4)], 'denied');
            abort(403, 'Ce compte est suspendu. Contactez un responsable de l\'église.');
        }

        // Cree le compte au premier passage, sinon le retrouve.
        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['phone_verified_at' => Carbon::now()],
        );

        $user->forceFill([
            'phone_verified_at' => $user->phone_verified_at ?? Carbon::now(),
            'last_login_at' => Carbon::now(),
        ])->save();

        // Une connexion est une activite : derniere connexion enregistree, membre inactif reactive.
        \App\Services\ActivityService::touch($user, true, 'login');

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
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->authPayload($request->user()));
    }

    /** Charge et met en forme les informations du compte connecte. */
    private function authPayload(User $user): array
    {
        $user->load('profile.tribe', 'profile.departments', 'roles.permissions', 'ledDepartments:id,name');

        // Responsabilite de departement : affichee comme une fonction (ce n'est plus un role).
        $leaderships = $user->ledDepartments->map(fn ($d) => [
            'key' => 'department_leader',
            'name' => 'Responsable de département',
            'scope_kind' => 'department',
            'scope_id' => $d->id,
            'scope_name' => $d->name,
        ]);

        return [
            // Derniere visite AVANT celle-ci (mise a jour apres la reponse) : sert a l'accueil
            // « Content de vous revoir » avec les nouveautes depuis cette date.
            'user' => $user->only(['id', 'phone']) + ['last_seen_at' => $user->last_seen_at?->toIso8601String()],
            'profile' => $user->profile,
            'profile_completed' => (bool) optional($user->profile)->is_completed,
            'completion' => $user->profile ? \App\Support\ProfileCompletion::for($user->profile) : null,
            'is_super_admin' => $user->isSuperAdmin(),
            'roles' => $user->roles->map(fn ($r) => [
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
            'gem' => \App\Models\Gem::find($id)?->name,
            'member' => \App\Models\Profile::where('user_id', $id)->first()?->full_name,
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
