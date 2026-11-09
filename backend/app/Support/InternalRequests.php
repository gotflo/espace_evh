<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Lectures regroupees : chaque chemin est execute par le routeur, comme un appel separe, avec TOUTES ses regles
 * (connexion, permission, module, portee verifiee par le controleur) et pour le meme utilisateur. Un regroupement
 * ne peut donc jamais ouvrir plus que l'appel seul. Gain : un seul demarrage de l'application et une seule requete
 * reseau au lieu de plusieurs (l'hebergement traite peu d'appels a la fois).
 */
class InternalRequests
{
    /**
     * @param  list<string>  $paths  chemins de l'API sans « /api » (ex. /admin/members/12/fiss), GET seulement
     * @return array<string, array{status: int, body?: mixed}>
     */
    public static function getMany(Request $outer, array $paths): array
    {
        $app = app();
        /** @var Router $router */
        $router = $app->make('router');
        $outerRoute = $outer->route();
        $responses = [];
        try {
            foreach ($paths as $path) {
                $responses[$path] = self::one($outer, $router, $path);
            }
        } finally {
            // L'appel principal retrouve sa requete et sa route (supervision, suite du traitement).
            $app->instance('request', $outer);
            if ($outerRoute instanceof Route) {
                $app->instance(Route::class, $outerRoute);
            }
        }

        return $responses;
    }

    /** @return array{status: int, body?: mixed} */
    private static function one(Request $outer, Router $router, string $path): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $sub = Request::create('/api'.parse_url($path, PHP_URL_PATH), 'GET', $query);
        foreach (['accept', 'authorization', 'accept-language', 'user-agent'] as $header) {
            if ($outer->headers->has($header)) {
                $sub->headers->set($header, $outer->headers->get($header));
            }
        }
        $sub->server->set('REMOTE_ADDR', $outer->ip());
        app()->instance('request', $sub);
        // Meme utilisateur que l'appel principal (deja authentifie).
        $sub->setUserResolver($outer->getUserResolver());

        try {
            $response = $router->dispatch($sub);
        } catch (HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage()]];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 500, 'body' => ['message' => 'Erreur du serveur.']];
        }

        $status = $response->getStatusCode();
        $body = $response instanceof JsonResponse ? $response->getData(true) : json_decode((string) $response->getContent(), true);

        return $body === null ? ['status' => $status] : ['status' => $status, 'body' => $body];
    }
}
