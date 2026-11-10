<?php

/*
 * Compte de gestion principal de la plateforme.
 *
 * Le numero est un IDENTIFIANT reserve (pas un secret) : le connaitre ne donne aucun droit. Il ne passe
 * pas par le code SMS : la premiere connexion demande un code d'activation a usage unique, genere sur le
 * serveur par le proprietaire (php artisan app:manager-activation-code), et le choix d'un code d'acces
 * personnel ; ensuite le numero et ce code d'acces (verrouillage 15 min apres 5 erreurs).
 */
return [
    'manager' => [
        // Numero reserve, au format international (normalise avant toute comparaison).
        'phone' => env('PLATFORM_MANAGER_PHONE', '+22893471703'),

        // Duree de validite d'un code d'activation genere sur le serveur (heures).
        'activation_code_ttl_hours' => (int) env('PLATFORM_MANAGER_ACTIVATION_TTL_HOURS', 24),
    ],

    // Video du guide de l'application, envoyee a chaque nouveau membre quand le pasteur valide son inscription
    // (courriel). Lien donne par l'eglise le 09/11/2026.
    'guide_video_url' => env('GUIDE_VIDEO_URL', 'https://youtu.be/vYO2_5nEEx8'),
];
