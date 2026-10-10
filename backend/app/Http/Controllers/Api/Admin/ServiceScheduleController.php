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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horaires des cultes (PR, PA) : les rendez-vous hebdomadaires de toute l'eglise, avec rappel
 * a tous (programme la veille, rappel 30 min avant). Ecran simple : jour, heure de debut,
 * heure de fin facultative, intitule. Sous le capot ce sont des evenements recurrents.
 */
class ServiceScheduleController extends Controller
{
    public const LOCATION = '70, rue Racine Est, Chicoutimi';

    private const DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function index(): JsonResponse
    {
        return response()->json(['services' => $this->services()->map(fn (Event $e) => $this->present($e))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        // Premiere occurrence : le prochain jour choisi (aujourd'hui compris).
        $today = now()->startOfDay();
        $day = $today->copy()->addDays(($data['day'] - $today->dayOfWeekIso + 7) % 7);

        $event = DB::transaction(function () use ($data, $day, $request) {
            $event = Event::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'location' => $data['location'] ?? self::LOCATION,
                'all_day' => false,
                'recurrence' => 'weekly',
                'remind_all' => true,
                'is_personal' => false,
                'created_by' => $request->user()->id,
            ] + $this->times($day, $data));
            $event->syncScopes([['type' => 'church', 'id' => null]]);
            Audit::log('service.created', $event, null, [], $this->snapshot($event));

            return $event;
        });
        $this->announce($request, $data, "Nouveau rendez-vous : {$event->title}", $this->line($event));

        return response()->json(['message' => 'Rendez-vous ajouté.', 'service' => $this->present($event)], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->ensureService($event);
        $data = $this->validated($request);

        DB::transaction(function () use ($event, $data) {
            $before = $this->snapshot($event);
            // Meme semaine d'origine : l'historique du calendrier reste coherent ; seuls le jour
            // et les heures changent pour toutes les occurrences.
            $anchor = $event->starts_at->copy()->startOfDay()->addDays($data['day'] - $event->starts_at->dayOfWeekIso);
            $event->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'location' => $data['location'] ?? $event->location,
            ] + $this->times($anchor, $data));
            [$old, $new] = Audit::diff($before, $this->snapshot($event));
            if ($new) {
                Audit::log('service.updated', $event, null, $old, $new);
            }
        });
        $event->refresh();
        $this->announce($request, $data, "Horaire modifié : {$event->title}", $this->line($event));

        return response()->json(['message' => 'Horaire mis à jour.', 'service' => $this->present($event)]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->ensureService($event);
        $line = $this->line($event);
        $title = $event->title;
        DB::transaction(function () use ($event) {
            Audit::log('service.deleted', $event, null, $this->snapshot($event));
            EventParticipation::where('event_id', $event->id)->delete();
            $event->scopes()->delete();
            $event->delete();
        });
        if ($request->boolean('notify')) {
            Notifier::send(array_diff(Audience::userIds([['type' => 'church', 'id' => null]]), [$request->user()->id]),
                'event', "Rendez-vous retiré : {$title}", "Ce rendez-vous n'a plus lieu ({$line}).", '/calendrier');
        }

        return response()->json(['message' => 'Rendez-vous retiré.']);
    }

    /** @return Collection<int, Event> */
    private function services()
    {
        return Event::where('remind_all', true)->where('is_personal', false)->where('recurrence', 'weekly')
            ->with('scopes')->get()
            ->filter(fn (Event $e) => $e->isForWholeChurch())
            ->sortBy(fn (Event $e) => $e->starts_at->dayOfWeekIso * 10000 + (int) $e->starts_at->format('Hi'))
            ->values();
    }

    private function ensureService(Event $event): void
    {
        $event->loadMissing('scopes');
        abort_unless($event->remind_all && ! $event->is_personal && $event->recurrence === 'weekly' && $event->isForWholeChurch(), 404,
            'Ce rendez-vous ne fait pas partie des horaires des cultes.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(EventController::CATEGORIES))],
            'location' => ['nullable', 'string', 'max:200'],
            'day' => ['required', 'integer', 'min:1', 'max:7'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['nullable', 'date_format:H:i'],
            'notify' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['end']) && $data['end'] <= $data['start']) {
            throw ValidationException::withMessages(['end' => "L'heure de fin doit être après l'heure de début."]);
        }
        $data['category'] ??= 'culte';

        return $data;
    }

    /** @return array{starts_at: Carbon, ends_at: ?Carbon} */
    private function times(Carbon $day, array $data): array
    {
        [$h, $m] = array_map('intval', explode(':', $data['start']));
        $endsAt = null;
        if (! empty($data['end'])) {
            [$eh, $em] = array_map('intval', explode(':', $data['end']));
            $endsAt = $day->copy()->setTime($eh, $em);
        }

        return ['starts_at' => $day->copy()->setTime($h, $m), 'ends_at' => $endsAt];
    }

    /** Toute l'eglise est prevenue si le pasteur l'a demande. */
    private function announce(Request $request, array $data, string $title, string $body): void
    {
        if (! empty($data['notify'])) {
            Notifier::send(array_diff(Audience::userIds([['type' => 'church', 'id' => null]]), [$request->user()->id]),
                'event', $title, $body, '/calendrier', ['kind' => 'service']);
        }
    }

    /** « Chaque dimanche à 08h45 » ou « Chaque mercredi de 19h30 à 21h00 ». */
    private function line(Event $e): string
    {
        $day = self::DAYS[$e->starts_at->dayOfWeekIso];

        return $e->ends_at
            ? "Chaque {$day} de {$e->starts_at->format('H\hi')} à {$e->ends_at->format('H\hi')}"
            : "Chaque {$day} à {$e->starts_at->format('H\hi')}";
    }

    /** @return array<string, mixed> */
    private function snapshot(Event $e): array
    {
        return [
            'title' => $e->title,
            'day' => self::DAYS[$e->starts_at->dayOfWeekIso],
            'start' => $e->starts_at->format('H:i'),
            'end' => $e->ends_at?->format('H:i'),
            'location' => $e->location,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'category' => $e->category,
            'location' => $e->location,
            'day' => $e->starts_at->dayOfWeekIso,
            'day_label' => self::DAYS[$e->starts_at->dayOfWeekIso],
            'start' => $e->starts_at->format('H:i'),
            'end' => $e->ends_at?->format('H:i'),
        ];
    }
}
