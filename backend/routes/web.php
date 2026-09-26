<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * En production, Laravel sert l'application React (build Vite copie dans public/).
 * Toutes les routes non-API renvoient index.html ; le routeur React prend le relais.
 * Les fichiers reels (assets, /storage, favicon...) sont servis directement par le
 * serveur web avant d'atteindre Laravel (voir public/.htaccess).
 */
Route::get('/{any}', function () {
    $index = public_path('index.html');
    abort_unless(File::exists($index), 404, "Application non deployee (index.html manquant).");

    return response(File::get($index), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('any', '^(?!api|up)(?!.*\.[A-Za-z0-9]{1,8}$).*$'); // une adresse de fichier absente renvoie 404
