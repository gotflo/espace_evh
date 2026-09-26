<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\SpiritualEntry;
use App\Models\User;
use App\Support\SpiritualCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SpiritualController extends Controller
{
    /** Journal + etapes d'un fidele, plus le catalogue pour les formulaires. */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canViewMember($user), 403, 'Hors de votre portée.');
        $entries = SpiritualEntry::with('author.profile')
            ->where('member_user_id', $user->id)
            ->orderByDesc('entry_date')->orderByDesc('id')->get()
            ->map(fn (SpiritualEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'type_label' => SpiritualCatalog::ENTRY_TYPES[$e->type] ?? $e->type,
                'entry_date' => $e->entry_date?->toDateString(),
                'note' => $e->note,
                'author' => $e->author?->profile?->full_name ?: null,
            ]);

        $reached = Milestone::where('member_user_id', $user->id)->get()
            ->keyBy('milestone_key');

        return response()->json([
            'entries' => $entries,
            'milestones' => collect(SpiritualCatalog::MILESTONES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'reached' => $reached->has($key),
                'reached_at' => $reached->get($key)?->reached_at?->toDateString(),
            ])->values(),
            'entry_types' => SpiritualCatalog::entryTypes(),
        ]);
    }

    /** Ajouter une entree au journal. */
    public function storeEntry(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canManageMember($user), 403, 'Consultation seule : action non autorisée.');
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(SpiritualCatalog::ENTRY_TYPES))],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        SpiritualEntry::create([
            'member_user_id' => $user->id,
            'author_user_id' => $request->user()->id,
            'type' => $data['type'],
            'entry_date' => $data['entry_date'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['message' => 'Entrée ajoutée.']);
    }

    /** Supprimer une entree du journal. */
    public function destroyEntry(Request $request, User $user, SpiritualEntry $entry): JsonResponse
    {
        abort_unless($request->user()->canManageMember($user), 403, 'Consultation seule : action non autorisée.');
        abort_unless($entry->member_user_id === $user->id, 404);
        $entry->delete();

        return response()->json(['message' => 'Entrée supprimée.']);
    }

    /** Cocher / decocher une etape franchie. */
    public function toggleMilestone(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canManageMember($user), 403, 'Consultation seule : action non autorisée.');
        $data = $request->validate([
            'milestone_key' => ['required', 'in:'.implode(',', array_keys(SpiritualCatalog::MILESTONES))],
            'reached' => ['required', 'boolean'],
        ]);

        if ($data['reached']) {
            Milestone::updateOrCreate(
                ['member_user_id' => $user->id, 'milestone_key' => $data['milestone_key']],
                ['reached_at' => Carbon::today(), 'recorded_by' => $request->user()->id],
            );
        } else {
            Milestone::where('member_user_id', $user->id)
                ->where('milestone_key', $data['milestone_key'])->delete();
        }

        return response()->json(['message' => 'Étape mise à jour.']);
    }
}
