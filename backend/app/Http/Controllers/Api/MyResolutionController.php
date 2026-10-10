<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResolutionResource;
use App\Services\ResolutionService;
use App\Services\ResourceProgress;
use App\Support\ResolutionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mes RM (Résolutions du Mois) : ecran du membre. */
class MyResolutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(ResolutionService::overview($request->user()));
    }

    /** Resolutions du mois en cours (creation ou modification pendant le mois). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(array_fill_keys(ResolutionCatalog::keys(), ['nullable', 'string', 'max:2000']));
        $resolution = ResolutionService::save($request->user(), $data);

        return response()->json([
            'message' => $resolution->wasRecentlyCreated ? 'Résolutions enregistrées. Que Dieu vous fortifie !' : 'Résolutions mises à jour.',
            'current' => ResolutionService::present($resolution),
        ]);
    }

    /** Bilan des resolutions du mois dernier. */
    /**
     * Signal du lecteur d'une ressource video publiee (memes controles que les exercices et les devoirs) :
     * pourcentage regarde et reprise la ou le membre s'est arrete.
     */
    public function progress(Request $request, ResolutionResource $resource): JsonResponse
    {
        abort_unless($resource->status === 'published' && $resource->youtube_id, 404);
        $data = $request->validate([
            'segments' => ['nullable', 'array', 'max:500'],
            'segments.*' => ['array', 'size:2'],
            'segments.*.*' => ['numeric', 'min:0', 'max:43200'],
            'position' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'seeks' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'skipped' => ['nullable', 'numeric', 'min:0', 'max:43200'],
            'rate' => ['nullable', 'numeric', 'min:0.1', 'max:16'],
        ]);
        $view = ResourceProgress::record($resource, $request->user(), $data);
        $resource->refresh();
        $state = ResourceProgress::stateFor($resource, $view);

        return response()->json(['percent' => $state['percent'], 'video_completed' => $state['completed'], 'status' => $state['completed'] ? 'done' : 'in_progress']);
    }

    public function review(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'array'],
            'outcome.*' => ['required', 'in:'.implode(',', array_keys(ResolutionCatalog::OUTCOMES))],
        ]);
        $previous = ResolutionService::review($request->user(), $data['outcome']);

        return response()->json(['message' => 'Merci pour votre bilan.', 'previous' => ResolutionService::present($previous)]);
    }
}
