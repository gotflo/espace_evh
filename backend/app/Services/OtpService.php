<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioVerifyClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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
            // Twilio owns the real code; this random hash keeps the existing
            // required column populated without storing that code locally.
            'code_hash' => Hash::make($code ?? bin2hex(random_bytes(32))),
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

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $valid = config('services.sms.driver') === 'twilio_verify'
            ? $this->twilioVerify->checkVerification($phone, $code)
            : Hash::check($code, $otp->code_hash);

        if (! $valid) {
            $otp->increment('attempts');
            return false;
        }

        $otp->update(['consumed_at' => Carbon::now()]);

        return true;
    }

    /** Un envoi est-il trop recent pour ce numero ? (anti-spam) */
    public function isThrottled(string $phone): bool
    {
        $last = OtpCode::where('phone', $phone)->latest()->first();

        return $last
            && $last->created_at->diffInSeconds(Carbon::now()) < self::RESEND_COOLDOWN_SEC;
    }
}
