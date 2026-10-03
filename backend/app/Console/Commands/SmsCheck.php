<?php

namespace App\Console\Commands;

use App\Services\OtpService;
use App\Services\Sms\TwilioVerifyClient;
use App\Services\Sms\TwilioVerifyException;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Diagnostic de l'envoi des codes de connexion par SMS (Twilio Verify) :
 *   php artisan app:sms-check                 identifiants et service Verify, SANS envoyer de SMS
 *   php artisan app:sms-check +14185551234    envoie en plus un vrai code a ce numero (SMS facture)
 * Aucun secret n'est affiche.
 */
class SmsCheck extends Command
{
    protected $signature = 'app:sms-check {telephone? : numero auquel envoyer un vrai code de test}';

    protected $description = 'Diagnostic des codes de connexion par SMS (Twilio Verify).';

    public function handle(TwilioVerifyClient $twilio): int
    {
        $driver = (string) config('services.sms.driver');
        $this->line('Mode (SMS_DRIVER) : '.$driver);
        $this->line('Code affiché à l\'écran (EXPOSE_OTP) : '.(config('app.expose_otp') ? 'oui' : 'non'));

        $present = fn (?string $value, string $prefix) => ! is_string($value) || $value === '' ? 'ABSENT'
            : (str_starts_with($value, $prefix) ? 'présent ('.$prefix.'…'.substr($value, -4).')' : 'présent mais ne commence pas par '.$prefix);
        $this->line('TWILIO_ACCOUNT_SID : '.$present(config('services.sms.twilio.sid'), 'AC'));
        $this->line('TWILIO_API_KEY_SID : '.$present(config('services.sms.twilio.api_key_sid'), 'SK'));
        $this->line('TWILIO_API_KEY_SECRET : '.(config('services.sms.twilio.api_key_secret') ? 'présent' : 'ABSENT'));
        $this->line('TWILIO_VERIFY_SERVICE_SID : '.$present(config('services.sms.twilio.verify_service_sid'), 'VA'));

        try {
            $service = $twilio->service();
        } catch (TwilioVerifyException $e) {
            $this->error('Connexion à Twilio Verify : ÉCHEC - '.$e->getMessage());
            $this->line(match (true) {
                $e->httpStatus === 401 => 'Conseil : Identifiants refusés : vérifiez la clé API (SK…) et son secret, ou recréez une clé.',
                $e->httpStatus === 404 => 'Conseil : Service introuvable : vérifiez TWILIO_VERIFY_SERVICE_SID (VA…) et qu\'il appartient bien à ce compte.',
                $e->httpStatus === null => 'Conseil : Vérifiez les quatre variables dans le fichier .env, puis : php artisan config:clear',
                default => 'Conseil : Réessayez dans quelques minutes ; si l\'erreur persiste, consultez la console Twilio (Monitor > Logs > Errors).',
            });

            return self::FAILURE;
        }

        $this->info('Connexion à Twilio Verify : OK');
        $this->line('Service Verify : « '.($service['friendly_name'] ?? '?').' »');
        $this->line('Modèle du SMS (TWILIO_VERIFY_TEMPLATE_SID) : '.($service['template_sid']
            ? 'présent ('.substr($service['template_sid'], 0, 2).'…'.substr($service['template_sid'], -4).') : le SMS affiche le nom de l\'application'
            : 'ABSENT : Twilio peut envoyer un SMS générique, sans le nom de l\'application'));
        $length = $service['code_length'];
        $this->line('Longueur du code : '.($length ?? '?').($length !== null && $length !== OtpService::CODE_LENGTH
            ? ' — ATTENTION : l\'application attend '.OtpService::CODE_LENGTH.' chiffres, réglez « Code length » sur '.OtpService::CODE_LENGTH.' dans le service Verify.' : ''));
        if ($driver !== 'twilio_verify') {
            $this->warn('SMS_DRIVER n\'est pas « twilio_verify » : les membres ne reçoivent pas encore de SMS.');
        }

        if ($to = $this->argument('telephone')) {
            $phone = Phone::normalize((string) $to);
            if (! $phone) {
                $this->error('Numéro invalide : '.$to);

                return self::FAILURE;
            }
            try {
                $twilio->sendVerification($phone);
                $this->info("Code envoyé par SMS à {$phone}. S'il n'arrive pas : console Twilio > Monitor > Logs > Verify.");
            } catch (TwilioVerifyException $e) {
                $this->error('Envoi du code : ÉCHEC - '.$e->getMessage());
                $this->line('Message affiché au membre : '.$e->userMessage());

                return self::FAILURE;
            }
        }

        return $length !== null && $length !== OtpService::CODE_LENGTH ? self::FAILURE : self::SUCCESS;
    }
}
