<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\InternalRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ouverture d'un ecran de gestion en un seul appel (comme /me/home pour l'accueil) : les lectures de ses panneaux
 * sont executees ici par le routeur, chacune avec ses propres regles d'acces (voir InternalRequests).
 * Seules les lectures de la liste ci-dessous peuvent etre regroupees ; toute autre adresse repond 404 sans etre
 * executee.
 */
class BundleController extends Controller
{
    /** Lectures autorisees (GET) : panneaux de la fiche d'un membre, blocs de gestion de l'accueil. */
    private const ALLOWED = [
        '#^/admin/members/\d{1,10}/(fiss|resolutions|assignments|vertumetre|meditations|spiritual|evaluations|deletion|exercises|fiss-history)$#',
        '#^/admin/(stats|requests|validations/count|new-members|leader-reports|gem-reports)$#',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'max:12'],
            'paths.*' => ['required', 'string', 'max:200'],
        ]);

        $allowed = [];
        $responses = [];
        foreach (array_unique($data['paths']) as $path) {
            // Chemin seul (jamais d'adresse complete), compare tel quel a la liste.
            $route = explode('?', $path, 2)[0];
            if (str_starts_with($route, '/') && collect(self::ALLOWED)->contains(fn (string $pattern) => preg_match($pattern, $route) === 1)) {
                $allowed[] = $path;
            } else {
                $responses[$path] = ['status' => 404];
            }
        }

        return response()->json(['responses' => $responses + InternalRequests::getMany($request, $allowed)]);
    }
}
