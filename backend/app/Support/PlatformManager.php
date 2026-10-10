<?php

namespace App\Support;

use App\Models\Gem;
use App\Models\ManagerActivationCode;
use App\Models\ManagerDevice;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Compte de gestion principal (numero reserve, config/platform.php).
 *
 * - Le numero ne passe pas par le code SMS : sur un APPAREIL DE CONFIANCE, le saisir suffit pour entrer.
 *   Un nouvel appareil s'autorise une seule fois avec un code a usage unique genere sur le serveur par le
 *   proprietaire (php artisan app:manager-activation-code) ; ailleurs, le numero seul ne donne rien.
 * - Une fois active, le compte a toutes les permissions de GESTION (PermissionCatalog::managementKeys),
 *   independamment des roles, et AUCUNE permission confidentielle (fiches, FISS, Vertumetre, rapports...).
 * - Il n'a pas de fiche de membre (profil « non termine ») : il n'apparait dans aucune liste de membres.
 * - Ses operations vont au journal de securite prive, pas au journal d'activite ordinaire.
 */
class PlatformManager
{
    /** Nom affiche a la place du compte (auteur d'un contenu, etc.). */
    public const DISPLAY_NAME = 'Administration de la plateforme';

    /** Alphabet des codes d'activation (sans 0/O, 1/I/L pour eviter les confusions). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Numero reserve normalise (E.164), ou null si la fonction est desactivee. */
    public static function phone(): ?string
    {
        // Memorise (appele a chaque verification de permission) ; recalcule si la configuration change.
        static $cache = [];
        $configured = trim((string) config('platform.manager.phone'));

        return $cache[$configured] ??= ($configured === '' ? null : Phone::normalize($configured));
    }

    /** Le numero (deja normalise par Phone::normalize) est-il le numero reserve ? */
    public static function isReservedPhone(?string $phone): bool
    {
        $reserved = self::phone();

        return $reserved !== null && $phone !== null && hash_equals($reserved, $phone);
    }

    /** Nom affiche pour l'auteur d'un contenu : le compte de gestion n'apparait jamais sous un nom de personne. */
    public static function nameOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return $user->isPlatformManager() ? self::DISPLAY_NAME : ($user->profile?->full_name ?: null);
    }

    /** @return array<int, string> */
    public static function permissions(): array
    {
        return PermissionCatalog::managementKeys();
    }

    public static function allows(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    /** Ids des comptes de gestion actives (pour les exclure des listes et des journaux ordinaires). */
    public static function userIds(): array
    {
        $phone = self::phone();

        return $phone === null ? [] : User::whereNotNull('platform_manager_at')->where('phone', $phone)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ------------------------------------------------------------------ Codes d'activation (serveur)

    /**
     * Nouveau code d'activation (affiche une seule fois par la commande) ; les codes precedents
     * non utilises sont aussitot invalides. Seule l'empreinte est conservee.
     */
    public static function issueActivationCode(): string
    {
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        DB::transaction(function () use ($raw) {
            ManagerActivationCode::whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
            ManagerActivationCode::create([
                'code_hash' => Hash::make($raw),
                'expires_at' => now()->addHours(max(1, (int) config('platform.manager.activation_code_ttl_hours', 24))),
            ]);
        });

        return implode('-', str_split($raw, 4));
    }

    /** Code saisi : majuscules, sans espaces ni tirets. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** Code valide (non utilise, non expire) : il est consomme. */
    public static function consumeActivationCode(string $code): ?ManagerActivationCode
    {
        $code = self::normalizeCode($code);
        if (strlen($code) !== 12) {
            return null;
        }
        foreach (ManagerActivationCode::whereNull('used_at')->where('expires_at', '>', now())->get() as $candidate) {
            if (Hash::check($code, $candidate->code_hash)) {
                $claimed = ManagerActivationCode::whereKey($candidate->id)->whereNull('used_at')->update(['used_at' => now()]);

                return $claimed === 1 ? $candidate->fresh() : null;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ Appareils de confiance (sans SMS)

    /** Essais manques (codes d'autorisation) avant verrouillage, et duree du verrouillage (minutes). */
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    private const FAILURES_KEY = 'platform-manager-failures';

    /** Autorise un appareil : jeton aleatoire remis une seule fois a l'appareil (seule son empreinte est gardee). */
    public static function issueDevice(User $user, ?string $label = null): string
    {
        $token = Str::random(64);
        ManagerDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => $label ? mb_substr($label, 0, 255) : null,
            'last_used_at' => now(),
        ]);

        return $token;
    }

    /** Compte de gestion actif lie a ce jeton d'appareil (non retire), ou null. */
    public static function userForDevice(?string $token): ?User
    {
        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }
        $device = ManagerDevice::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        $user = $device ? User::find($device->user_id) : null;
        if (! $user?->isPlatformManager()) {
            return null;
        }
        $device->forceFill(['last_used_at' => now()])->save();

        return $user;
    }

    public static function revokeDevices(User $user): int
    {
        return ManagerDevice::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * Verrouillage global (et non par adresse IP) : des codes faux venus de plusieurs adresses
     * comptent ensemble. Seconde restante avant la fin du verrouillage, 0 si le compte est ouvert.
     */
    public static function lockedFor(): int
    {
        $state = Cache::get(self::FAILURES_KEY);
        if (! is_array($state) || ($state['count'] ?? 0) < self::MAX_FAILURES) {
            return 0;
        }

        return max(0, (int) ($state['until'] ?? 0) - now()->getTimestamp());
    }

    /** Essai manque : nombre d'essais restants avant verrouillage. */
    public static function registerFailure(): int
    {
        $state = Cache::get(self::FAILURES_KEY);
        $count = (is_array($state) ? (int) ($state['count'] ?? 0) : 0) + 1;
        $until = now()->addMinutes(self::LOCK_MINUTES)->getTimestamp();
        Cache::put(self::FAILURES_KEY, ['count' => $count, 'until' => $until], now()->addMinutes(self::LOCK_MINUTES));

        return max(0, self::MAX_FAILURES - $count);
    }

    public static function clearFailures(): void
    {
        Cache::forget(self::FAILURES_KEY);
    }

    /**
     * Active (ou reactive, code oublie) le compte de gestion pour le numero reserve, autorise par le code
     * d'activation a usage unique. Un compte existant avec ce numero devient le compte de gestion : ses roles et
     * responsabilites sont retires (consignes dans le journal de securite), sa fiche devient invisible et
     * ses sessions anterieures sont fermees.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    public static function activate(string $phone): array
    {
        abort_unless(self::isReservedPhone($phone), 403, 'Ce numéro n\'est pas le numéro de gestion.');

        return DB::transaction(function () use ($phone) {
            $user = User::where('phone', $phone)->lockForUpdate()->first()
                ?? User::create(['phone' => $phone, 'phone_verified_at' => Carbon::now()]);
            // Compte suspendu depuis la console de supervision : il le reste.
            abort_if($user->blocked_at !== null, 403, 'Ce compte est suspendu.');
            $previous = [
                'existing_account' => ! $user->wasRecentlyCreated,
                'roles' => DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->where('role_user.user_id', $user->id)->get(['roles.key', 'role_user.scope_kind', 'role_user.scope_id'])
                    ->map(fn ($r) => (array) $r)->all(),
                'led_departments' => DB::table('department_leaders')->where('user_id', $user->id)->pluck('department_id')->all(),
                'profile_was_completed' => (bool) Profile::where('user_id', $user->id)->value('is_completed'),
            ];

            DB::table('role_user')->where('user_id', $user->id)->delete();
            DB::table('department_leaders')->where('user_id', $user->id)->delete();
            Gem::where('leader_user_id', $user->id)->update(['leader_user_id' => null]);
            $user->tokens()->delete();

            $user->forceFill([
                'platform_manager_at' => Carbon::now(),
                'approval_status' => User::APPROVED,
                'phone_verified_at' => $user->phone_verified_at ?? Carbon::now(),
            ])->save();
            // Pas de fiche de membre : profil « non termine » = absent de toutes les listes (comme une inscription en attente).
            $profile = Profile::firstOrCreate(['user_id' => $user->id]);
            $profile->forceFill(['is_completed' => false])->save();
            $user->forgetPermissions();

            return [$user->fresh(), $previous];
        });
    }

    /** Retire le statut de gestion (compromission, changement de proprietaire) et ferme les sessions. */
    public static function revoke(User $user): void
    {
        $user->forceFill(['platform_manager_at' => null])->save();
        self::revokeDevices($user);
        $user->tokens()->delete();
    }
}
