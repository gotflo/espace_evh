<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Monitoring\Monitor;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioVerifyClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Gestion des codes OTP : generation, envoi par SMS, verification.
 */
class OtpService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 10;       // duree de validite du code

    public const MAX_ATTEMPTS = 5;       // essais max avant blocage

    public const RESEND_COOLDOWN_SEC = 45; // delai mini entre deux envois

    /** Codes faux cumules pour un meme numero (tous envois confondus) avant un verrouillage d'une heure. */
    public const MAX_FAILURES_PER_HOUR = 10;

    /** Codes faux par heure pour un numero, toutes adresses confondues (une seule adresse ne verrouille pas un membre). */
    public const MAX_FAILURES_ALL_ADDRESSES = 30;

    /** Codes envoyes par heure a un meme numero. */
    public const MAX_CODES_PER_NUMBER_HOUR = 6;

    /** Part du plafond global de SMS ouverte aux numeros inconnus : le reste est garde aux membres. */
    private const UNKNOWN_NUMBERS_SHARE = 0.8;

    public function __construct(private SmsSender $sms, private TwilioVerifyClient $twilioVerify) {}

    /**
     * Envoie le code avec Twilio Verify, ou genere/enregistre le code pour le mode log.
     * Retourne le code uniquement pour le mode local de test.
     */
    public function sendCode(string $phone): ?string
    {
        $usesTwilioVerify = config('services.sms.driver') === 'twilio_verify';
        $code = $usesTwilioVerify
            ? null
            : str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'phone' => $phone,
            // Twilio garde le vrai code : une empreinte aleatoire (sans bcrypt, couteux et inutile ici)
            // remplit la colonne obligatoire sans rien stocker de ce code.
            'code_hash' => $code !== null ? Hash::make($code) : 'twilio:'.hash('sha256', random_bytes(32)),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            if ($usesTwilioVerify) {
                $this->twilioVerify->sendVerification($phone);
            } else {
                $this->sms->send($phone, "Votre code de connexion Vases d'Honneur Chicoutimi est : {$code}");
            }
        } catch (Throwable $exception) {
            $otp->delete();
            throw $exception;
        }

        return $code;
    }

    /**
     * Verifie un code pour un numero. Retourne true si valide (et le consomme).
     */
    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS || $this->isLocked($phone)) {
            return false;
        }

        $valid = config('services.sms.driver') === 'twilio_verify'
            ? $this->twilioVerify->checkVerification($phone, $code)
            : Hash::check($code, $otp->code_hash);

        if (! $valid) {
            $otp->increment('attempts');
            // Compteur cumule : redemander un code ne remet pas les essais a zero.
            foreach ([self::failuresKey($phone), self::allFailuresKey($phone)] as $key) {
                Cache::add($key, 0, now()->addHour());
                Cache::increment($key);
            }

            return false;
        }

        $otp->update(['consumed_at' => Carbon::now()]);
        Cache::forget(self::failuresKey($phone));
        Cache::forget(self::allFailuresKey($phone));

        return true;
    }

    /** Trop de codes faux pour ce numero dans l'heure : verrouille (meme avec un nouveau code). */
    public function isLocked(string $phone): bool
    {
        return (int) Cache::get(self::failuresKey($phone), 0) >= self::MAX_FAILURES_PER_HOUR
            || (int) Cache::get(self::allFailuresKey($phone), 0) >= self::MAX_FAILURES_ALL_ADDRESSES;
    }

    /** Par numero et par adresse : quelqu'un qui tape de faux codes ne bloque pas le vrai membre, ailleurs. */
    private static function failuresKey(string $phone): string
    {
        return 'otp-failures:'.hash('sha256', $phone.'|'.request()->ip());
    }

    private static function allFailuresKey(string $phone): string
    {
        return 'otp-failures-all:'.hash('sha256', $phone);
    }

    /**
     * Raison de ne pas envoyer de SMS a ce numero, ou null : pays non accepte, numero surtaxe ou
     * d'un type qui ne recoit pas de SMS, plafond global atteint (fraude au pompage de SMS factures).
     */
    public function smsRefusal(string $phone): ?string
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($phone, null);
        } catch (NumberParseException) {
            return 'Ce numéro de téléphone n’est pas valide.';
        }
        $allowed = array_filter(array_map('trim', explode(',', strtoupper((string) config('services.sms.allowed_regions', 'CA,US')))));
        if ($allowed && ! in_array($util->getRegionCodeForNumber($number), $allowed, true)) {
            return 'Ce numéro ne peut pas recevoir de code de connexion : seuls les numéros du Canada et des États-Unis sont acceptés. Contactez un responsable de l’église.';
        }
        if (! in_array($util->getNumberType($number), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            return 'Ce numéro ne peut pas recevoir de SMS : saisissez un numéro de téléphone mobile.';
        }
        if (OtpCode::where('phone', $phone)->where('created_at', '>=', now()->subHour())->count() >= self::MAX_CODES_PER_NUMBER_HOUR) {
            return 'Trop de codes demandés pour ce numéro : réessayez dans une heure.';
        }
        $hour = OtpCode::where('created_at', '>=', now()->subHour())->count();
        $day = OtpCode::where('created_at', '>=', now()->subDay())->count();
        // Une rafale de numeros inconnus n'empeche pas les membres de se reconnecter.
        $share = User::where('phone', $phone)->exists() ? 1 : self::UNKNOWN_NUMBERS_SHARE;
        if ($hour >= (int) ceil((int) config('services.sms.max_per_hour', 100) * $share) || $day >= (int) ceil((int) config('services.sms.max_per_day', 300) * $share)) {
            Monitor::event('critical', 'security', 'auth.sms_ceiling', 'Plafond de SMS atteint : envois de codes suspendus', ['hour' => $hour, 'day' => $day], 'denied');

            return 'Trop de demandes de code en ce moment : réessayez dans quelques minutes.';
        }

        return null;
    }

    /** Un envoi est-il trop recent pour ce numero ? (anti-spam) */
    public function isThrottled(string $phone): bool
    {
        $last = OtpCode::where('phone', $phone)->latest()->first();

        return $last
            && $last->created_at->diffInSeconds(Carbon::now()) < self::RESEND_COOLDOWN_SEC;
    }
}
