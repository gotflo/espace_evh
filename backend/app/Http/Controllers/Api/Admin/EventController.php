<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public const CATEGORIES = [
        'culte' => 'Culte',
        'priere' => 'Prière',
        'formation' => 'Formation',
        'reunion' => 'Réunion',
        'sortie' => 'Sortie',
        'autre' => 'Autre',
    ];

    /** Evenements que l'utilisateur peut gerer (les siens ou ceux entierement dans sa portee). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = Event::where('is_personal', false)->with('creator.profile', 'scopes')
            ->withCount([
                'participations as going_count' => fn ($q) => $q->where('response', 'present'),
                'participations as volunteer_count' => fn ($q) => $q->where('volunteer', true),
            ])
            ->orderByDesc('starts_at')->limit(300)->get()
            ->filter(fn (Event $e) => Audience::canManage($user, $e->created_by, $e->audienceList()))
            ->map(fn (Event $e) => $this->present($e))->values();

        return response()->json(['events' => $items, 'recurrences' => Event::RECURRENCES]);
    }

    /** Liste des participants (presents) et des volontaires d'un evenement (ou d'une occurrence). */
    public function participants(Request $request, Event $event): JsonResponse
    {
        $this->authorizeManage($request, $event);
        $date = $request->query('date');
        $rows = $event->participations()->with('user.profile')
            ->when($date, fn ($q) => $q->where('occurs_on', $date))
            ->when(! $date && $event->isRecurring(), fn ($q) => $q->where('occurs_on', $this->nextOccurrenceDate($event)))
            ->get();
        $map = fn ($p) => [
            'user_id' => $p->user_id,
            'name' => $p->user?->profile?->full_name ?: ($p->user?->phone ?? 'Inconnu'),
            'volunteer' => $p->volunteer,
        ];

        return response()->json([
            'title' => $event->title,
            'date' => $date ?: ($event->isRecurring() ? $this->nextOccurrenceDate($event) : $event->starts_at->toDateString()),
            'going' => $rows->where('response', 'present')->map($map)->values(),
            'volunteers' => $rows->where('volunteer', true)->map($map)->values(),
            'absent_count' => $rows->where('response', 'absent')->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $scopes = Audience::resolve($request->user(), Audience::fromRequest($request));
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('events', 'public');
        }

        $event = DB::transaction(function () use ($data, $scopes, $request) {
            $event = Event::create($data + ['created_by' => $request->user()->id, 'is_personal' => false]);
            $event->syncScopes($scopes);
            Audit::log('event.created', $event, null, [], ['title' => $event->title, 'starts_at' => $event->starts_at->toIso8601String(), 'scopes' => $scopes]);

            return $event;
        });

        // Toute l'audience de l'evenement est prevenue automatiquement.
        $audience = array_diff(Audience::userIds($scopes), [$request->user()->id]);
        Notifier::send($audience, 'event', 'Nouvel événement : '.$event->title, $this->when($event),
            '/calendrier?date='.$event->starts_at->toDateString(), ['event_id' => $event->id]);

        return response()->json(['message' => 'Événement créé.', 'event' => $this->present($event->load('scopes'))], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorizeManage($request, $event);
        $data = $this->validated($request);
        $scopes = $request->has('scopes') ? Audience::resolve($request->user(), Audience::fromRequest($request)) : null;
        if ($request->hasFile('image')) {
            if ($event->image_path) {
                Storage::disk('public')->delete($event->image_path);
            }
            $data['image_path'] = $request->file('image')->store('events', 'public');
        }

        $before = [$event->starts_at?->toIso8601String(), $event->location, $event->recurrence, $event->all_day];
        DB::transaction(function () use ($event, $data, $scopes) {
            $old = $event->only(['title', 'starts_at', 'ends_at', 'location', 'recurrence']);
            $event->update($data);
            if ($scopes !== null) {
                $event->syncScopes($scopes);
            }
            [$o, $n] = Audit::diff(array_map('strval', $old), array_map('strval', $event->only(['title', 'starts_at', 'ends_at', 'location', 'recurrence'])));
            Audit::log('event.updated', $event, null, $o, $n + ($scopes !== null ? ['scopes' => $scopes] : []));
        });
        $event->refresh()->load('scopes');
        $after = [$event->starts_at?->toIso8601String(), $event->location, $event->recurrence, $event->all_day];

        // Date, lieu ou frequence modifies : les inscrits sont prevenus.
        if ($before !== $after) {
            Notifier::send($this->upcomingParticipants($event), 'event', 'Événement modifié : '.$event->title,
                $this->when($event), '/calendrier?date='.$event->starts_at->toDateString(), ['event_id' => $event->id]);
        }

        return response()->json(['message' => 'Événement mis à jour.', 'event' => $this->present($event)]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorizeManage($request, $event);
        $participants = $this->upcomingParticipants($event);
        $title = $event->title;
        $wasUpcoming = $event->isRecurring() || $event->starts_at->isFuture();

        Audit::log('event.deleted', $event, null, ['title' => $title, 'starts_at' => $event->starts_at->toIso8601String(), 'scopes' => $event->audienceList()]);
        if ($event->image_path) {
            Storage::disk('public')->delete($event->image_path);
        }
        $event->scopes()->delete();
        $event->delete();

        if ($wasUpcoming) {
            Notifier::send($participants, 'event', 'Événement annulé : '.$title,
                "L'événement auquel vous étiez inscrit a été annulé.", '/calendrier');
        }

        return response()->json(['message' => 'Événement supprimé.']);
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
            'all_day' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'in:'.implode(',', array_keys(Event::RECURRENCES))],
            'recurrence_until' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
            'remind_all' => ['nullable', 'boolean'],
        ]);
        unset($data['image']); // le fichier est traite a part (store/update)

        $data['all_day'] = (bool) ($data['all_day'] ?? false);
        // Rappel a tous avant chaque occurrence : reserve aux rendez-vous recurrents (cultes...).
        $data['remind_all'] = (bool) ($data['remind_all'] ?? false) && ($data['recurrence'] ?? 'none') !== 'none';
        $data['recurrence'] = $data['recurrence'] ?? 'none';
        if ($data['recurrence'] === 'none') {
            $data['recurrence_until'] = null;
        }
        // Fin de repetition : au plus tot le jour du debut (comparaison sur la date seule).
        if (! empty($data['recurrence_until']) && Carbon::parse($data['recurrence_until'])->toDateString() < Carbon::parse($data['starts_at'])->toDateString()) {
            throw ValidationException::withMessages(['recurrence_until' => 'La fin de la répétition doit être après le début.']);
        }
        if ($data['all_day']) {
            $data['starts_at'] = Carbon::parse($data['starts_at'])->startOfDay();
            $data['ends_at'] = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'])->startOfDay() : null;
        }

        return $data;
    }

    private function authorizeManage(Request $request, Event $event): void
    {
        abort_if($event->is_personal, 404);
        abort_unless(Audience::canManage($request->user(), $event->created_by, $event->audienceList()), 403, 'Cet événement est hors de votre périmètre.');
    }

    /** @return array<int> fideles inscrits (presents) aux occurrences a venir. */
    private function upcomingParticipants(Event $event): array
    {
        return EventParticipation::where('event_id', $event->id)->where('response', 'present')
            ->where('occurs_on', '>=', now()->toDateString())->pluck('user_id')->unique()->values()->all();
    }

    private function nextOccurrenceDate(Event $event): string
    {
        $next = $event->occurrencesBetween(now()->startOfDay(), now()->addYear())[0] ?? $event->starts_at;

        return $next->toDateString();
    }

    /** « dimanche 6 septembre a 19 h 00 · Temple · chaque semaine » */
    private function when(Event $e): string
    {
        $date = $e->starts_at->locale('fr')->isoFormat('dddd D MMMM');
        $parts = [$e->all_day ? $date : $date.' à '.$e->starts_at->format('H\hi')];
        if ($e->location) {
            $parts[] = $e->location;
        }
        if ($e->isRecurring()) {
            $parts[] = mb_strtolower(Event::RECURRENCES[$e->recurrence]);
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    private function present(Event $e): array
    {
        $next = $e->isRecurring() ? ($e->occurrencesBetween(now(), now()->addYear())[0] ?? null) : null;

        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'image_url' => $e->image_url,
            'category' => $e->category,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'all_day' => (bool) $e->all_day,
            'recurrence' => $e->recurrence ?? 'none',
            'recurrence_label' => Event::RECURRENCES[$e->recurrence ?? 'none'] ?? null,
            'recurrence_until' => $e->recurrence_until?->toDateString(),
            'remind_all' => (bool) $e->remind_all,
            'next_occurrence' => $next?->toIso8601String(),
            'location' => $e->location,
            'scopes' => $e->audienceList(),
            'target' => $e->targetLabel(),
            // Un evenement recurrent reste « a venir » tant qu'il a une prochaine occurrence.
            'is_past' => $e->isRecurring() ? $next === null : ($e->ends_at ?? $e->starts_at)?->isPast() ?? false,
            'author' => $e->creator?->profile?->full_name ?: null,
            'going_count' => $e->going_count ?? 0,
            'volunteer_count' => $e->volunteer_count ?? 0,
        ];
    }
}
