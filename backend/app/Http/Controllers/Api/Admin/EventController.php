<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Models\Tribe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public const CATEGORIES = [
        'culte' => 'Culte',
        'priere' => 'Priere',
        'formation' => 'Formation',
        'reunion' => 'Reunion',
        'sortie' => 'Sortie',
        'autre' => 'Autre',
    ];

    public function index(): JsonResponse
    {
        $items = Event::with('creator.profile')
            ->withCount([
                'participations as going_count' => fn ($q) => $q->where('response', 'present'),
                'participations as volunteer_count' => fn ($q) => $q->where('volunteer', true),
            ])
            ->orderByDesc('starts_at')->get()
            ->map(fn (Event $e) => $this->present($e));

        return response()->json(['events' => $items]);
    }

    /** Liste des participants (presents) et des volontaires d'un evenement. */
    public function participants(Event $event): JsonResponse
    {
        $rows = $event->participations()->with('user.profile')->get();
        $map = fn ($p) => [
            'user_id' => $p->user_id,
            'name' => $p->user?->profile?->full_name ?: ($p->user?->phone ?? 'Inconnu'),
            'volunteer' => $p->volunteer,
        ];

        return response()->json([
            'title' => $event->title,
            'going' => $rows->where('response', 'present')->map($map)->values(),
            'volunteers' => $rows->where('volunteer', true)->map($map)->values(),
            'absent_count' => $rows->where('response', 'absent')->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('events', 'public');
        }
        $event = Event::create($data + ['created_by' => $request->user()->id]);

        return response()->json(['message' => 'Evenement cree.', 'event' => $this->present($event)], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validated($request);
        if ($request->hasFile('image')) {
            if ($event->image_path) {
                \Storage::disk('public')->delete($event->image_path);
            }
            $data['image_path'] = $request->file('image')->store('events', 'public');
        }
        $event->update($data);

        return response()->json(['message' => 'Evenement mis a jour.', 'event' => $this->present($event->fresh())]);
    }

    public function destroy(Event $event): JsonResponse
    {
        if ($event->image_path) {
            \Storage::disk('public')->delete($event->image_path);
        }
        $event->delete();

        return response()->json(['message' => 'Evenement supprime.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:8192'], // 8 Mo
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:200'],
            'target_type' => ['required', 'in:all,tribe,department,gem'],
            'target_id' => ['nullable', 'integer'],
        ]);
        unset($data['image']); // le fichier est traite a part (store/update)

        // Un responsable restreint ne peut cibler que sa propre portee (GEM / tribu / dept).
        if (\App\Support\MemberScope::isScoped($request->user())) {
            [$data['target_type'], $data['target_id']] = \App\Support\MemberScope::primaryScope($request->user());
        }

        if ($data['target_type'] === 'tribe' && ! Tribe::whereKey($data['target_id'] ?? null)->exists()) {
            abort(422, 'Tribu invalide.');
        }
        if ($data['target_type'] === 'department' && ! Department::whereKey($data['target_id'] ?? null)->exists()) {
            abort(422, 'Departement invalide.');
        }
        $data['target_id'] = $data['target_type'] === 'all' ? null : $data['target_id'];

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'image_url' => $e->image_url,
            'category' => $e->category,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'location' => $e->location,
            'target_type' => $e->target_type,
            'target_id' => $e->target_id,
            'target' => $this->targetLabel($e),
            'is_past' => $e->starts_at?->isPast() ?? false,
            'author' => $e->creator?->profile?->full_name ?: null,
            'going_count' => $e->going_count ?? 0,
            'volunteer_count' => $e->volunteer_count ?? 0,
        ];
    }

    private function targetLabel(Event $e): string
    {
        return match ($e->target_type) {
            'tribe' => 'Tribu '.(Tribe::find($e->target_id)?->name ?? '?'),
            'department' => 'Dept. '.(Department::find($e->target_id)?->name ?? '?'),
            'gem' => 'GEM '.(\App\Models\Gem::find($e->target_id)?->name ?? '?'),
            default => "Toute l'eglise",
        };
    }
}
