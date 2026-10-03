<?php

/*
|--------------------------------------------------------------------------
| Agent de supervision
|--------------------------------------------------------------------------
| La console de supervision est un projet separe (evh_monitoring), heberge sur un autre
| sous-domaine. Ici, l'application ne fait que :
|  - collecter des mesures dans ses tables monitor_* (lues par la console) ;
|  - offrir une petite API de controle (/api/agent/*), signee avec un secret partage,
|    pour les actions decidees dans la console (bloquer un compte, lancer les automatismes...).
*/

return [

    // Collecte des mesures (requetes, erreurs, integrations). false = rien n'est enregistre.
    'enabled' => env('MONITOR_ENABLED', true),

    // Une requete plus longue est comptee « lente » et conservee en detail.
    'slow_request_ms' => (int) env('MONITOR_SLOW_REQUEST_MS', 1500),
    // Une requete SQL plus longue est journalisee (sans les valeurs).
    'slow_query_ms' => (int) env('MONITOR_SLOW_QUERY_MS', 500),
    // Niveau minimal des journaux du serveur recopies dans les mesures.
    'log_level' => env('MONITOR_LOG_LEVEL', 'warning'),

    // Filet de securite : la console purge selon ses propres reglages ; l'application supprime
    // de toute facon les mesures plus anciennes que ce nombre de jours.
    'agent_max_days' => (int) env('MONITOR_AGENT_MAX_DAYS', 120),

    // Secret partage avec la console (au moins 32 caracteres, identique des deux cotes).
    // Vide = API de controle desactivee (la console reste en lecture seule).
    'agent_secret' => env('MONITOR_AGENT_SECRET'),
    // Adresses IP autorisees a appeler l'API de controle (separees par des virgules ; vide = toutes).
    'agent_allowed_ips' => env('MONITOR_AGENT_ALLOWED_IPS'),
    // Ecart maximal tolere entre les horloges (secondes) : une requete plus ancienne est refusee.
    'agent_max_skew' => (int) env('MONITOR_AGENT_MAX_SKEW', 300),

];
