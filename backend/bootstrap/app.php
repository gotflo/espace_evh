<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Visiteur non connecte : 401 JSON sur l'API (jamais de redirection vers une page
        // « login » inexistante, qui provoquait une erreur 500), page de connexion ailleurs.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/connexion');

        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);

        // En-tetes de securite sur toutes les reponses.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Automatismes (rappels...) declenches aussi par l'activite, si le cron manque.
        $middleware->appendToGroup('api', \App\Http\Middleware\TriggerAutomation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Deux requetes identiques simultanees (double clic, reseau qui renvoie) : la base
        // refuse le doublon ; on repond proprement au lieu d'une erreur serveur.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Cette action a déjà été enregistrée.'], 409);
            }
        });

        // Base momentanement occupee (verrou, trop de connexions) : le client peut reessayer.
        $exceptions->render(function (QueryException $e, Request $request) {
            $busy = in_array((string) $e->getCode(), ['40001', '1213', '1205', 'HY000'], true)
                && preg_match('/deadlock|lock wait|database is locked|too many connections|max_user_connections/i', $e->getMessage());
            if ($busy && $request->is('api/*')) {
                report($e);

                return response()->json(['message' => 'Le service est très sollicité. Réessayez dans un instant.'], 503, ['Retry-After' => '3']);
            }
        });

        // Jamais de detail technique vers l'utilisateur en production.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*') && $response->getStatusCode() >= 500 && ! config('app.debug')
                && $response->getStatusCode() !== 503) {
                return response()->json(['message' => 'Une erreur est survenue. Réessayez ; si elle persiste, prévenez un responsable.'], 500);
            }

            return $response;
        });
    })->create();
