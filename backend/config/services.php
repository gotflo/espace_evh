<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Envoi/validation des codes OTP. 'log' en dev, 'twilio_verify' en production.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        // Anti-fraude (pompage de SMS factures) : pays acceptes pour l'envoi d'un code, et plafonds
        // globaux au-dela desquels plus aucun code ne part (alerte dans la supervision).
        'allowed_regions' => env('SMS_ALLOWED_REGIONS', 'CA,US'),
        'max_per_hour' => (int) env('SMS_MAX_PER_HOUR', 100),
        'max_per_day' => (int) env('SMS_MAX_PER_DAY', 300),
        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'api_key_sid' => env('TWILIO_API_KEY_SID'),
            'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
            'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
            // Modele Verify (HJ...) qui affiche le nom de l'application (« My vasesdhonneur ») dans le SMS.
            // Modele public de Twilio (pas un secret) : utilise par defaut si le .env l'oublie.
            'template_sid' => env('TWILIO_VERIFY_TEMPLATE_SID') ?: 'HJ4d9c5db569029bedab5b28ab79f4cc8d',
            // Delai avant le second essai d'un envoi qui a echoue pour une raison passagere.
            'retry_delay_ms' => (int) env('TWILIO_RETRY_DELAY_MS', 1500),
            // Textos de notification (devoir, rappels) : numero Twilio achete (+1...) ou service de messagerie (MG...).
            // Sans l'un des deux, les textos sont desactives (courriel, notification et push seulement).
            'sms_from' => env('TWILIO_SMS_FROM'),
            'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        ],
        // Essais : tous les textos partent vers ce numero.
        'test_to' => env('SMS_TEST_TO'),
        // Pas de texto la nuit : envoye a partir de 9 h, jusqu'a 20 h 30.
        'quiet_from' => '20:30',
        'quiet_until' => '09:00',
        'per_minute' => (int) env('SMS_PER_MINUTE', 30),
    ],

    // Notifications push (Web Push / VAPID). Sans cles, elles sont generees automatiquement
    // dans storage/app/webpush-vapid.json lors du premier usage.
    'webpush' => [
        'enabled' => env('WEBPUSH_ENABLED', true),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    // Automatismes (rappels, anniversaires...) : declenches par le cron Laravel et, en secours,
    // par l'activite de l'application (au plus toutes les 5 minutes).
    'automation' => [
        'auto_tick' => env('AUTO_TICK', true),
    ],

];
