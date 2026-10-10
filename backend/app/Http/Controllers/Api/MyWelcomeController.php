<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WelcomeBackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Nouveautes depuis la derniere visite d'un membre (« Content de vous revoir ») :
 * annonces recues, evenements a venir publies entre-temps, exercices ouverts non faits.
 * Uniquement ce qui lui est adresse ; rien qui culpabilise.
 */
class MyWelcomeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['required', 'date']]);
        $user = $request->user();
        // Au plus 90 jours en arriere (au-dela, tout est « nouveau »).
        $since = Carbon::parse($data['since'])->max(now()->subDays(90));

        return response()->json(WelcomeBackService::for($user, $since));
    }
}
