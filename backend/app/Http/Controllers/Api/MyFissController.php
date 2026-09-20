<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpiritualHealthForm;
use App\Support\FissCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MyFissController extends Controller
{
    /** Fiche du mois en cours (ou vide), historique, indices de notation, statut de rappel. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = now()->format('Y-m');

        $current = SpiritualHealthForm::where('user_id', $user->id)->where('period', $period)->first();
        $history = SpiritualHealthForm::where('user_id', $user->id)
            ->where('period', '!=', $period)->orderByDesc('period')->limit(12)->get()
            ->map(fn (SpiritualHealthForm $f) => $this->present($f));

        return response()->json([
            'period' => $period,
            'period_label' => Carbon::createFromFormat('Y-m', $period)->locale('fr')->isoFormat('MMMM YYYY'),
            'filled' => (bool) $current,
            'reminder' => $this->reminderStatus((bool) $current),
            'current' => $current ? $this->present($current) : null,
            'history' => $history,
            'indices' => FissCatalog::indices(),
        ]);
    }

    /** Enregistre / met a jour la fiche du mois en cours. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meditation' => ['nullable', 'integer', 'min:0', 'max:20'],
            'priere' => ['nullable', 'integer', 'min:0', 'max:20'],
            'jeune' => ['nullable', 'integer', 'min:0', 'max:20'],
            'sanctification_corps' => ['nullable', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'sanctification_ame' => ['nullable', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'sanctification_esprit' => ['nullable', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'situation_financiere' => ['nullable', 'integer', 'min:0', 'max:20'],
            'situation_familiale' => ['nullable', 'integer', 'min:0', 'max:20'],
            'situation_conjugale' => ['nullable', 'integer', 'min:0', 'max:20'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $form = SpiritualHealthForm::updateOrCreate(
            ['user_id' => $request->user()->id, 'period' => now()->format('Y-m')],
            $data,
        );

        return response()->json(['message' => 'Fiche de sante spirituelle enregistree.', 'current' => $this->present($form)]);
    }

    /** Niveau de rappel selon le jour du mois (aucun si deja remplie). */
    private function reminderStatus(bool $filled): array
    {
        if ($filled) {
            return ['level' => 'none', 'days_left' => null];
        }
        $daysLeft = (int) now()->diffInDays(now()->endOfMonth());
        $day = now()->day;
        $level = $daysLeft <= 3 ? 'urgent' : ($day >= 20 ? 'advance' : 'info');

        return ['level' => $level, 'days_left' => $daysLeft];
    }

    /** @return array<string, mixed> */
    private function present(SpiritualHealthForm $f): array
    {
        return [
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
        ];
    }
}
