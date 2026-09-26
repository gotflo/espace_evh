<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MyEventController extends Controller
{
    /**
     * Evenements a venir qui concernent le fidele (portee : tous / sa tribu / son GEM /
     * ses departements), recurrences deroulees, sur les N prochains jours (30 par defaut).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:120']]);
        $days = (int) ($data['days'] ?? 30);
        $user = $request->user();

        $from = now();
        $to = now()->addDays($days)->endOfDay();
        $occurrences = CalendarService::occurrences(CalendarService::eventsQuery($user), $from, $to)->take(60);

        return response()->json([
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => CalendarService::presentOccurrences($user, $occurrences),
        ]);
    }

    /**
     * Agenda personnel : chaque fidele peut programmer ses propres rendez-vous et rappels
     * depuis le calendrier (visibles par lui seul, avec rappels automatiques).
     */
    public function store(Request $request): JsonResponse
    {
        $event = Event::create($this->personal($request) + [
            'is_personal' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Ajouté à votre agenda.', 'id' => $event->id], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->ownPersonal($request, $event);
        $event->update($this->personal($request));

        return response()->json(['message' => 'Rendez-vous mis à jour.']);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->ownPersonal($request, $event);
        $event->delete();

        return response()->json(['message' => 'Retiré de votre agenda.']);
    }

    private function ownPersonal(Request $request, Event $event): void
    {
        abort_unless($event->is_personal && (int) $event->created_by === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function personal(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(\App\Http\Controllers\Api\Admin\EventController::CATEGORIES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'in:'.implode(',', array_keys(Event::RECURRENCES))],
            'recurrence_until' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
        ]);
        $data['category'] = $data['category'] ?? 'autre';
        $data['all_day'] = (bool) ($data['all_day'] ?? false);
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

    /** Le fidele repond a une occurrence d'evenement (present/absent) et se porte volontaire ou non. */
    public function rsvp(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'in:present,absent'],
            'volunteer' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();

        abort_unless(CalendarService::eventsQuery($user)->whereKey($event->id)->exists(), 403, 'Cet événement ne vous concerne pas.');

        // Occurrence visee : la date fournie (evenement recurrent) ou la date de l'evenement.
        $date = $data['date'] ?? $event->starts_at->toDateString();
        $day = Carbon::parse($date);
        $valid = collect($event->occurrencesBetween($day->copy()->startOfDay(), $day->copy()->endOfDay()))
            ->contains(fn (Carbon $s) => $s->toDateString() === $date);
        abort_unless($valid, 422, "Cet événement n'a pas lieu à cette date.");

        EventParticipation::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id, 'occurs_on' => $date],
            ['response' => $data['response'], 'volunteer' => $data['response'] === 'present' ? (bool) ($data['volunteer'] ?? false) : false],
        );

        $going = EventParticipation::where('event_id', $event->id)->where('occurs_on', $date)
            ->where('response', 'present')->count();

        return response()->json(['message' => 'Réponse enregistrée.', 'going_count' => $going]);
    }
}
