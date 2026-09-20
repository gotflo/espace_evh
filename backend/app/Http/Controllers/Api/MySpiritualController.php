<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\SpiritualEntry;
use App\Support\SpiritualCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MySpiritualController extends Controller
{
    /** Journal spirituel personnel du fidele + ses etapes + catalogue self-service. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

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
                'mine' => $e->author_user_id === $user->id,
            ]);

        $reached = Milestone::where('member_user_id', $user->id)->get()->keyBy('milestone_key');

        return response()->json([
            'entries' => $entries,
            'milestones' => collect(SpiritualCatalog::MILESTONES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'reached' => $reached->has($key),
                'reached_at' => $reached->get($key)?->reached_at?->toDateString(),
            ])->values(),
            'entry_types' => collect(SpiritualCatalog::MEMBER_ENTRY_TYPES)
                ->map(fn ($key) => ['key' => $key, 'label' => SpiritualCatalog::ENTRY_TYPES[$key] ?? $key])->values(),
        ]);
    }

    /** Le fidele ajoute lui-meme une entree (temoignage, priere, besoin...). */
    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', SpiritualCatalog::MEMBER_ENTRY_TYPES)],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        SpiritualEntry::create([
            'member_user_id' => $user->id,
            'author_user_id' => $user->id,
            'type' => $data['type'],
            'entry_date' => $data['entry_date'],
            'note' => $data['note'],
        ]);

        return response()->json(['message' => 'Entree ajoutee a votre journal.'], 201);
    }

    /** Le fidele supprime une de SES propres entrees. */
    public function destroyEntry(Request $request, SpiritualEntry $entry): JsonResponse
    {
        $user = $request->user();
        abort_unless($entry->member_user_id === $user->id && $entry->author_user_id === $user->id, 403);
        $entry->delete();

        return response()->json(['message' => 'Entree supprimee.']);
    }

    /** Le fidele coche / decoche une etape de son parcours. */
    public function toggleMilestone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'milestone_key' => ['required', 'in:'.implode(',', array_keys(SpiritualCatalog::MILESTONES))],
            'reached' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        if ($data['reached']) {
            Milestone::updateOrCreate(
                ['member_user_id' => $user->id, 'milestone_key' => $data['milestone_key']],
                ['reached_at' => Carbon::today(), 'recorded_by' => $user->id],
            );
        } else {
            Milestone::where('member_user_id', $user->id)
                ->where('milestone_key', $data['milestone_key'])->delete();
        }

        return response()->json(['message' => 'Etape mise a jour.']);
    }
}
