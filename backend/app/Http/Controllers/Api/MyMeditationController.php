<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeditationSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyMeditationController extends Controller
{
    /** Noms des rubriques dans les messages d'erreur. */
    private const LABELS = [
        'verse' => 'Verset à méditer',
        'observation' => 'Observation, interprétation et commentaires',
        'rhema' => 'Rhéma reçu',
        'action' => 'Action du jour à mener',
        'prayer' => 'Ma prière',
    ];

    private const MAX = 5000;

    /** Mes fiches de meditation, les plus recentes en premier (dates seulement : la fiche s'ouvre a part). */
    public function index(Request $request): JsonResponse
    {
        $sheets = MeditationSheet::where('user_id', $request->user()->id)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->get(['id', 'submitted_at']);

        return response()->json(['sheets' => $sheets->map(fn (MeditationSheet $s) => $s->summary())->values()]);
    }

    /** Une de MES fiches (celle d'un autre membre : introuvable). */
    public function show(Request $request, MeditationSheet $sheet): JsonResponse
    {
        abort_unless((int) $sheet->user_id === (int) $request->user()->id, 404, 'Fiche introuvable.');

        return response()->json(['sheet' => $sheet->present()]);
    }

    /** Enregistre une fiche : rattachee au membre connecte, datee par le serveur. */
    public function store(Request $request): JsonResponse
    {
        $rules = [];
        $messages = [];
        foreach (self::LABELS as $field => $label) {
            $rules[$field] = ['required', 'string', 'max:'.self::MAX];
            $messages["{$field}.required"] = "Remplissez la rubrique « {$label} » pour valider votre fiche.";
            $messages["{$field}.string"] = "La rubrique « {$label} » doit être un texte.";
            $messages["{$field}.max"] = 'La rubrique « '.$label.' » ne peut pas dépasser '.self::MAX.' caractères.';
        }
        $data = $request->validate($rules, $messages);

        $sheet = MeditationSheet::create([
            ...$data,
            'user_id' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Fiche de méditation enregistrée.', 'sheet' => $sheet->present()], 201);
    }
}
