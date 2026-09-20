<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if ($this->otp->isThrottled($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Un code vient déjà d\'être envoyé. Patientez un instant avant de réessayer.',
            ]);
        }

        $code = $this->otp->sendCode($phone);

        $payload = [
            'message' => 'Un code de vérification a été envoyé par SMS.',
            'phone' => $phone,
        ];

        // En dev, ou en phase de test (EXPOSE_OTP=true) : on renvoie le code pour se
        // connecter sans vrai SMS. A desactiver des que Twilio est en place.
        if (app()->environment('local') || config('app.expose_otp')) {
            $payload['dev_code'] = $code;
        }

        return response()->json($payload);
    }

    /** Etape 2 : le fidele saisit le code. Si valide, on le connecte. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $phone = Phone::normalize($data['phone']);
        if (! $phone || ! $this->otp->verify($phone, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré.',
            ]);
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
        $user->load('profile.tribe', 'profile.departments', 'roles.permissions');

        return [
            'user' => $user->only(['id', 'phone']),
            'profile' => $user->profile,
            'profile_completed' => (bool) optional($user->profile)->is_completed,
            'is_super_admin' => $user->isSuperAdmin(),
            'roles' => $user->roles->map(fn ($r) => [
                'key' => $r->key,
                'name' => $r->name,
                'scope_kind' => $r->pivot->scope_kind,
                'scope_id' => $r->pivot->scope_id,
                'scope_name' => $this->scopeName($r->pivot->scope_kind, $r->pivot->scope_id),
            ])->values(),
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
