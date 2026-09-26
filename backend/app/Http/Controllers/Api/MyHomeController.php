<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\VerseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Ouverture du tableau de bord en un seul appel : les lectures demandees par ses blocs
 * (verset, FISS, evenements, annonces, exercices, rendez-vous) sont executees ici, par les
 * memes controleurs, avec les memes regles d'acces (l'utilisateur est celui de la requete).
 *
 * Pourquoi : sur l'hebergement (1 coeur), chaque requete coute surtout le demarrage de
 * Laravel ; 1 appel au lieu de 7 divise d'autant le travail quand toute l'eglise ouvre
 * l'application en meme temps (apres une notification). Si cet appel echoue, l'application
 * refait simplement les lectures une par une.
 */
class MyHomeController extends Controller
{
    /** Seules ces lectures peuvent etre regroupees (chemin => [controleur, methode]). */
    private const SOURCES = [
        '/dashboard/verse' => [VerseController::class, 'current'],
        '/me/fiss' => [MyFissController::class, 'index'],
        '/me/events' => [MyEventController::class, 'index'],
        '/me/announcements' => [MyAnnouncementController::class, 'index'],
        '/me/exercises' => [MyExerciseController::class, 'index'],
        '/calendar' => [CalendarController::class, 'index'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'max:10'],
            'paths.*' => ['required', 'string', 'max:200'],
        ]);

        $responses = [];
        foreach (array_unique($data['paths']) as $path) {
            $route = (string) parse_url($path, PHP_URL_PATH);
            if (! isset(self::SOURCES[$route])) {
                $responses[$path] = ['status' => 404];

                continue;
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $sub = Request::create('/api'.$route, 'GET', $query);
            $sub->setUserResolver($request->getUserResolver());
            [$class, $method] = self::SOURCES[$route];
            try {
                $response = app()->call([app($class), $method], ['request' => $sub]);
                $responses[$path] = ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
            } catch (ValidationException $e) {
                $responses[$path] = ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
            } catch (HttpExceptionInterface $e) {
                $responses[$path] = ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage()]];
            }
        }

        return response()->json(['responses' => $responses]);
    }
}
