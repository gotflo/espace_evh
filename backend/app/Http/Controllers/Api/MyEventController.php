<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyEventController extends Controller
{
    /** Evenements a venir qui concernent le fidele (portee : tous / sa tribu / ses departements). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        $tribeId = $profile?->tribe_id;
        $gemId = $profile?->gem_id;
        $deptIds = $profile ? $profile->departments()->pluck('departments.id')->all() : [];

        $events = Event::query()
            ->where('starts_at', '>=', now()->startOfDay())
            ->where(function ($q) use ($tribeId, $gemId, $deptIds) {
                $q->where('target_type', 'all')
                    ->orWhere(fn ($t) => $t->where('target_type', 'tribe')->where('target_id', $tribeId))
                    ->orWhere(fn ($g) => $g->where('target_type', 'gem')->where('target_id', $gemId))
                    ->orWhere(fn ($d) => $d->where('target_type', 'department')->whereIn('target_id', $deptIds ?: [0]));
            })
            ->withCount(['participations as going_count' => fn ($q) => $q->where('response', 'present')])
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        // Reponses du fidele pour ces evenements.
        $mine = EventParticipation::where('user_id', $user->id)
            ->whereIn('event_id', $events->pluck('id'))->get()->keyBy('event_id');

        $items = $events->map(fn (Event $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'image_url' => $e->image_url,
            'category' => $e->category,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'location' => $e->location,
            'going_count' => $e->going_count,
            'my_response' => $mine->get($e->id)?->response,
            'my_volunteer' => (bool) ($mine->get($e->id)?->volunteer ?? false),
        ]);

        return response()->json(['events' => $items]);
    }

    /** Le fidele repond a un evenement (present/absent) et se porte volontaire ou non. */
    public function rsvp(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'in:present,absent'],
            'volunteer' => ['nullable', 'boolean'],
        ]);

        EventParticipation::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $request->user()->id],
            ['response' => $data['response'], 'volunteer' => $data['response'] === 'present' ? (bool) ($data['volunteer'] ?? false) : false],
        );

        return response()->json(['message' => 'Réponse enregistrée.']);
    }
}
