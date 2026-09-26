<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FissEditRequest;
use App\Models\SpiritualHealthForm;
use App\Services\FissService;
use App\Support\FissCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        return $request->validate([
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
