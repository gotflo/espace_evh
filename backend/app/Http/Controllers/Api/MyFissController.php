<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FissEditRequest;
use App\Models\SpiritualHealthForm;
use App\Services\FissService;
use App\Support\FissCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyFissController extends Controller
{
    /** Fiche du mois (verrouillee ou non), historique, demandes de modification, indices de notation. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = now()->format('Y-m');

        $forms = SpiritualHealthForm::where('user_id', $user->id)->orderByDesc('period')->limit(13)
            ->with(['editRequests' => fn ($q) => $q->latest('id')])->get();
        $current = $forms->firstWhere('period', $period);

        return response()->json([
            'period' => $period,
            'period_label' => FissService::periodLabel($period),
            'filled' => (bool) $current,
            'reminder' => $this->reminderStatus((bool) $current),
            'current' => $current ? $this->present($current) : null,
            'history' => $forms->where('period', '!=', $period)->take(12)->map(fn ($f) => $this->present($f))->values(),
            'indices' => FissCatalog::indices(),
            'inner_life' => FissCatalog::innerLife(),
            'max_requests' => FissEditRequest::MAX_PER_FORM,
        ]);
    }

    /** Enregistre la fiche du mois : elle est aussitot verrouillee. */
    public function store(Request $request): JsonResponse
    {
        $form = FissService::create($request->user(), $this->validated($request));

        return response()->json(['message' => 'Fiche enregistrée. Merci !', 'current' => $this->present($form->load('editRequests'))], 201);
    }

    /** Modifie une fiche deverrouillee (demande approuvee) ; elle est reverrouillee. */
    public function update(Request $request, SpiritualHealthForm $form): JsonResponse
    {
        // Fiche verrouillee : refusee avant meme de lire les reponses.
        FissService::ensureEditable($request->user(), $form);
        $form = FissService::update($request->user(), $form, $this->validated($request));

        return response()->json(['message' => 'Fiche modifiée et reverrouillée.', 'form' => $this->present($form->load('editRequests'))]);
    }

    /** Demande de modification (motif obligatoire, 2 par fiche au maximum). */
    public function requestEdit(Request $request, SpiritualHealthForm $form): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        FissService::requestEdit($request->user(), $form, $data['reason']);

        return response()->json(['message' => 'Demande envoyée à votre patriarche.', 'form' => $this->present($form->fresh()->load('editRequests'))], 201);
    }

    public function cancelRequest(Request $request, FissEditRequest $editRequest): JsonResponse
    {
        FissService::cancel($request->user(), $editRequest);

        return response()->json(['message' => 'Demande annulée.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // Toutes les questions de la fiche sont obligatoires (meme regle que l'ecran), vie interieure comprise ;
        // seul le commentaire est facultatif. Situation conjugale : obligatoire pour un profil « Marié(e) ».
        $married = $request->user()->profile?->marital_status === 'marie';
        $data = $request->validate([
            'meditation' => ['required', 'integer', 'min:0', 'max:20'],
            'priere' => ['required', 'integer', 'min:0', 'max:20'],
            'jeune' => ['required', 'integer', 'min:0', 'max:20'],
            'sanctification_corps' => ['required', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'sanctification_ame' => ['required', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'sanctification_esprit' => ['required', 'in:'.implode(',', FissCatalog::SANCTIFICATION)],
            'situation_financiere' => ['required', 'integer', 'min:0', 'max:20'],
            'situation_familiale' => ['required', 'integer', 'min:0', 'max:20'],
            'situation_conjugale' => [$married ? 'required' : 'nullable', 'integer', 'min:0', 'max:20'],
            'comment' => ['nullable', 'string', 'max:2000'],
            // Vie interieure : fait partie du remplissage de la fiche (« Aucune », « Non »... si rien a signaler).
            ...array_fill_keys(SpiritualHealthForm::INNER_LIFE, ['required', 'string', 'max:3000']),
        ], [
            'required' => 'Répondez à cette question pour enregistrer votre fiche.',
            ...array_fill_keys(array_map(fn ($k) => "$k.required", SpiritualHealthForm::INNER_LIFE),
                'Répondez à cette question de la vie intérieure (« Aucune » ou « Non » si rien à signaler).'),
        ]);
        foreach (SpiritualHealthForm::INNER_LIFE as $key) {
            $data[$key] = trim((string) $data[$key]);
        }

        // Situation conjugale : seulement pour un profil « Marié(e) » ; ignoree sinon.
        if ($request->user()->profile?->marital_status !== 'marie') {
            $data['situation_conjugale'] = null;
        }

        return $data;
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
        $requests = $f->editRequests;
        $open = FissService::openRequest($f);
        $pending = $requests->firstWhere('status', 'pending');
        $used = $requests->where('status', '!=', 'cancelled')->count();

        return [
            'id' => $f->id,
            'period' => $f->period,
            'period_label' => FissService::periodLabel($f->period),
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
            ...$f->only(SpiritualHealthForm::INNER_LIFE),
            'vie_spirituelle_total' => $f->vie_spirituelle_total,
            'vie_sociale_total' => $f->vie_sociale_total,
            'spiritual_score' => $f->spiritualScore(),
            'social_score' => $f->socialScore(),
            'submitted_at' => $f->submitted_at?->toIso8601String(),
            'locked' => $f->isLocked(),
            'edit_count' => (int) $f->edit_count,
            'can_edit_until' => $open?->unlock_expires_at?->toIso8601String(),
            'editable' => $open !== null,
            'requests_used' => $used,
            'requests_left' => max(0, FissEditRequest::MAX_PER_FORM - $used),
            'pending_request' => $pending ? ['id' => $pending->id, 'reason' => $pending->reason, 'created_at' => $pending->created_at->toIso8601String()] : null,
            'last_decision' => ($d = $requests->whereIn('status', ['approved', 'rejected', 'used', 'expired'])->sortByDesc('decided_at')->first())
                ? ['status' => $d->status, 'comment' => $d->decision_comment, 'decided_at' => $d->decided_at?->toIso8601String()] : null,
        ];
    }
}
