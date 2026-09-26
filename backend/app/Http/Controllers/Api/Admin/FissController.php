<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\FissCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FissController extends Controller
{
    /** Historique des fiches de sante spirituelle d'un membre (pour le responsable). */
    public function index(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canViewMember($user), 403, 'Accès refusé.');

        $forms = SpiritualHealthForm::where('user_id', $user->id)
            ->orderByDesc('period')->limit(24)->get()
            ->map(fn (SpiritualHealthForm $f) => [
                'id' => $f->id,
                'period' => $f->period,
                'period_label' => Carbon::createFromFormat('Y-m', $f->period)->locale('fr')->isoFormat('MMMM YYYY'),
                'meditation' => $f->meditation,
                'priere' => $f->priere,
                'jeune' => $f->jeune,
                'sanctification_corps' => $f->sanctification_corps,
                'sanctification_ame' => $f->sanctification_ame,
                'sanctification_esprit' => $f->sanctification_esprit,
                'situation_financiere' => $f->situation_financiere,
                'situation_familiale' => $f->situation_familiale,
                'situation_conjugale' => $f->situation_conjugale,
                'comment' => $f->comment,
                'vie_spirituelle_total' => $f->vie_spirituelle_total,
                'vie_sociale_total' => $f->vie_sociale_total,
            ]);

        return response()->json([
            'forms' => $forms,
            'current_period' => now()->format('Y-m'),
            'indices' => FissCatalog::indices(),
        ]);
    }
}
