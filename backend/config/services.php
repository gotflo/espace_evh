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
        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'api_key_sid' => env('TWILIO_API_KEY_SID'),
            'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
            'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
            // Modele Verify (HJ...) qui affiche le nom de l'application dans le SMS ; facultatif.
            'template_sid' => env('TWILIO_VERIFY_TEMPLATE_SID'),
            // Delai avant le second essai d'un envoi qui a echoue pour une raison passagere.
            'retry_delay_ms' => (int) env('TWILIO_RETRY_DELAY_MS', 1500),
        ],
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
