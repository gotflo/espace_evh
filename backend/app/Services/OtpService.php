<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Gestion des codes OTP : generation, envoi par SMS, verification.
 */
class OtpService
{
    public const CODE_LENGTH = 6;
    public const TTL_MINUTES = 10;       // duree de validite du code
    public const MAX_ATTEMPTS = 5;       // essais max avant blocage
    public const RESEND_COOLDOWN_SEC = 45; // delai mini entre deux envois

    public function __construct(private SmsSender $sms) {}

    /**
     * Genere un code, l'enregistre (hache) et l'envoie par SMS.
     * Retourne le code en clair (utilise seulement en local pour faciliter les tests).
     */
    public function sendCode(string $phone): string
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        $this->sms->send($phone, "Votre code de connexion Vases d'Honneur Chicoutimi est : {$code}");

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

        if (! Hash::check($code, $otp->code_hash)) {
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
