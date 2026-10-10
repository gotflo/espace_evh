<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VertumetreEditRequest;
use App\Models\VertumetreForm;
use App\Services\FissService;
use App\Services\VertumetreService;
use App\Support\French;
use App\Support\VertumetreCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vertumetre mensuel du membre : questionnaire, mois evalue, verrouillage, demandes de modification. */
class MyVertumetreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $forms = VertumetreForm::where('user_id', $user->id)->orderByDesc('period')->limit(13)
            ->with(['editRequests' => fn ($q) => $q->latest('id')])->get();

        return response()->json([
            'sections' => VertumetreCatalog::forClient(),
            // Mois que l'on peut remplir : le mois en cours seulement.
            'periods' => collect(VertumetreService::fillablePeriods())->map(fn ($p) => [
                'period' => $p,
                'label' => FissService::periodLabel($p),
                'filled' => $forms->contains('period', $p),
            ])->values(),
            'forms' => $forms->map(fn ($f) => VertumetreService::present($f))->values(),
            'max_requests' => VertumetreEditRequest::MAX_PER_FORM,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'answers' => ['required', 'array']]);
        $form = VertumetreService::create($request->user(), $data['period'], $data['answers']);

        return response()->json([
            'message' => 'Vertumètre '.French::de(FissService::periodLabel($form->period)).' enregistré. Merci !',
            'form' => VertumetreService::present($form->load('editRequests')),
        ], 201);
    }

    public function update(Request $request, VertumetreForm $form): JsonResponse
    {
        $data = $request->validate(['answers' => ['required', 'array']]);
        $form = VertumetreService::update($request->user(), $form, $data['answers']);

        return response()->json(['message' => 'Vertumètre modifié et reverrouillé.', 'form' => VertumetreService::present($form->load('editRequests'))]);
    }

    public function requestEdit(Request $request, VertumetreForm $form): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        VertumetreService::requestEdit($request->user(), $form, $data['reason']);

        return response()->json(['message' => 'Demande envoyée à votre patriarche.', 'form' => VertumetreService::present($form->fresh()->load('editRequests'))], 201);
    }

    public function cancelRequest(Request $request, VertumetreEditRequest $editRequest): JsonResponse
    {
        VertumetreService::cancel($request->user(), $editRequest);

        return response()->json(['message' => 'Demande annulée.']);
    }
}
