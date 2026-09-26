<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DashboardVerse;
use App\Services\VerseService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bibliotheque des versets du tableau de bord (permission content.manage : PR, PA). */
class VerseController extends Controller
{
    /** Verset du jour (tout membre connecte). */
    public function current(): JsonResponse
    {
        return response()->json(['verse' => VerseService::current()]);
    }

    public function index(): JsonResponse
    {
        $verses = DashboardVerse::with('editor.profile:id,user_id,first_name,last_name', 'creator.profile:id,user_id,first_name,last_name')
            ->orderBy('position')->orderBy('id')->get();
        $today = VerseService::pick(now());

        return response()->json([
            'verses' => $verses->map(fn (DashboardVerse $v) => $this->present($v))->values(),
            'today' => $today,
            'today_is_fallback' => $today['id'] === null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $verse = DB::transaction(function () use ($data, $request) {
            $verse = DashboardVerse::create($data + [
                'position' => (int) DashboardVerse::max('position') + 1,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            Audit::log('verse.created', $verse, null, [], $verse->only(['label', 'reference', 'status', 'starts_at', 'ends_at', 'is_pinned']));

            return $verse;
        });
        VerseService::forget();

        return response()->json([
            'message' => $verse->status === 'published' ? 'Texte publié.' : 'Brouillon enregistré.',
            'verse' => $this->present($verse->fresh()),
        ], 201);
    }

    public function update(Request $request, DashboardVerse $verse): JsonResponse
    {
        $data = $this->validated($request, $verse);
        $before = $verse->only(['label', 'text', 'reference', 'message', 'status', 'starts_at', 'ends_at', 'is_pinned']);
        $verse->update($data + ['updated_by' => $request->user()->id]);
        [$old, $new] = Audit::diff(array_map(fn ($v) => (string) $v, $before), array_map(fn ($v) => (string) $v, $verse->only(array_keys($before))));
        if ($old || $new) {
            Audit::log('verse.updated', $verse, null, $old, $new);
        }
        VerseService::forget();

        return response()->json(['message' => 'Texte mis à jour.', 'verse' => $this->present($verse->fresh())]);
    }

    public function destroy(DashboardVerse $verse): JsonResponse
    {
        Audit::log('verse.deleted', $verse, null, $verse->only(['label', 'text', 'reference', 'status']));
        $verse->delete();
        VerseService::forget();

        return response()->json(['message' => 'Texte supprimé.']);
    }

    /** Nouvel ordre de rotation (liste complete des identifiants). */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                DashboardVerse::whereKey($id)->update(['position' => $i + 1]);
            }
        });
        Audit::log('verse.reordered', null, null, [], ['ids' => $data['ids']]);
        VerseService::forget();

        return response()->json(['message' => 'Ordre enregistré.']);
    }

    /** Historique d'un texte (creation, modifications, publication), du plus recent au plus ancien. */
    public function history(DashboardVerse $verse): JsonResponse
    {
        $logs = AuditLog::with('actor.profile:id,user_id,first_name,last_name')
            ->where('subject_type', 'dashboard_verse')->where('subject_id', $verse->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'label' => AuditLogController::LABELS[$l->action] ?? $l->action,
                'actor' => $l->actor?->profile?->full_name ?: 'Système',
                'old' => $l->old_values,
                'new' => $l->new_values,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['history' => $logs]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DashboardVerse $current = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'text' => ['required', 'string', 'min:5', 'max:1000'],
            'reference' => ['required', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:draft,published'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_pinned' => ['nullable', 'boolean'],
        ], [
            'text.required' => 'Le texte est obligatoire.',
            'reference.required' => 'La référence est obligatoire (ex. Jean 3.16).',
        ]);
        $data['label'] = trim($data['label']);
        $data['text'] = trim($data['text']);
        $data['reference'] = trim($data['reference']);
        $data['message'] = isset($data['message']) && trim($data['message']) !== '' ? trim($data['message']) : null;
        $data['is_pinned'] = (bool) ($data['is_pinned'] ?? false);
        $data['starts_at'] = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $data['ends_at'] = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;

        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at']->lte($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être après le début.']);
        }
        if ($data['status'] === 'published' && $data['ends_at'] && $data['ends_at']->isPast()) {
            throw ValidationException::withMessages(['ends_at' => 'Cette date de fin est déjà passée : le texte ne serait jamais affiché.']);
        }

        // Pas de doublon (meme reference et meme texte).
        // Comparaison en PHP (majuscules, accents et espaces) : identique quelle que soit la base.
        $norm = fn (string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
        $duplicate = DashboardVerse::when($current, fn ($q) => $q->whereKeyNot($current->id))->get(['id', 'label', 'text', 'reference'])
            ->first(fn ($v) => $norm($v->reference) === $norm($data['reference']) && $norm($v->text) === $norm($data['text']));
        if ($duplicate) {
            throw ValidationException::withMessages(['text' => "Ce texte existe déjà (« {$duplicate->label} », {$duplicate->reference})."]);
        }

        // Deux textes mis en avant sur la meme periode : lequel afficher ? On refuse.
        if ($data['status'] === 'published' && $data['is_pinned']) {
            $start = $data['starts_at'] ?? Carbon::create(2000);
            $end = $data['ends_at'] ?? Carbon::create(2999);
            $conflict = DashboardVerse::where('status', 'published')->where('is_pinned', true)
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $end))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->first();
            if ($conflict) {
                throw ValidationException::withMessages(['is_pinned' => "« {$conflict->reference} » est déjà mis en avant sur cette période. Retirez sa mise en avant ou changez les dates."]);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(DashboardVerse $v): array
    {
        return [
            'id' => $v->id,
            'label' => $v->label,
            'text' => $v->text,
            'reference' => $v->reference,
            'message' => $v->message,
            'status' => $v->status,
            'state' => $v->state(),
            'starts_at' => $v->starts_at?->toIso8601String(),
            'ends_at' => $v->ends_at?->toIso8601String(),
            'is_pinned' => $v->is_pinned,
            'position' => $v->position,
            'author' => $v->creator?->profile?->full_name,
            'updated_by' => $v->editor?->profile?->full_name,
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }
}
