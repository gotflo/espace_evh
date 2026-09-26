<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\ExerciseVideoView;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\Holidays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tout ce qui est programme, au meme endroit : evenements (y compris recurrents),
 * anniversaires, jours feries, echeances des taches (exercices, FISS).
 * Sert au calendrier, a la liste « evenements a venir » et aux rappels automatiques.
 */
class CalendarService
{
    /**
     * Evenements visibles par un membre : toute l'eglise, sa tribu, son GEM, ses departements,
     * ceux de sa portee de responsable, ceux qu'il a crees, et son agenda personnel.
     */
    public static function eventsQuery(User $user): Builder
    {
        return Event::query()->forMember($user)->with('scopes');
    }

    /**
     * Occurrences des evenements (deroulement des recurrences) dans [from, to].
     *
     * @return Collection<int, array{event: Event, start: Carbon, end: Carbon|null}>
     */
    public static function occurrences(Builder $events, Carbon $from, Carbon $to): Collection
    {
        $candidates = (clone $events)
            ->where('starts_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->where(fn ($single) => $single->where('recurrence', 'none')
                    ->where(fn ($w) => $w->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from)))
                    ->orWhere(fn ($rec) => $rec->where('recurrence', '!=', 'none')
                        ->where(fn ($u) => $u->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $from->toDateString())));
            })
            ->get();

        $out = collect();
        foreach ($candidates as $event) {
            $duration = $event->ends_at ? $event->starts_at->diffInSeconds($event->ends_at) : null;
            foreach ($event->occurrencesBetween($from, $to) as $start) {
                $out->push([
                    'event' => $event,
                    'start' => $start,
                    'end' => $duration !== null ? $start->copy()->addSeconds($duration) : null,
                ]);
            }
        }

        return $out->sortBy(fn ($o) => $o['start']->timestamp)->values();
    }

    /**
     * Mise en forme des occurrences pour l'interface, avec la reponse du fidele
     * et le nombre d'inscrits pour chaque occurrence.
     *
     * @param  Collection<int, array{event: Event, start: Carbon, end: Carbon|null}>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    public static function presentOccurrences(User $user, Collection $occurrences): array
    {
        if ($occurrences->isEmpty()) {
            return [];
        }

        $eventIds = $occurrences->map(fn ($o) => $o['event']->id)->unique()->values();
        $dates = $occurrences->map(fn ($o) => $o['start']->toDateString())->unique()->values();

        $participations = EventParticipation::whereIn('event_id', $eventIds)
            ->whereIn('occurs_on', $dates)->get(['event_id', 'user_id', 'occurs_on', 'response', 'volunteer']);
        $going = $participations->where('response', 'present')
            ->countBy(fn ($p) => $p->event_id.'|'.$p->occurs_on);
        $mine = $participations->where('user_id', $user->id)
            ->keyBy(fn ($p) => $p->event_id.'|'.$p->occurs_on);

        // Qui peut modifier quoi : son agenda personnel, ce qu'on a cree, ou (avec events.manage)
        // les evenements entierement compris dans sa portee de diffusion.
        $editable = fn (Event $e) => self::canEdit($user, $e);

        return $occurrences->map(function ($o) use ($going, $mine, $editable) {
            /** @var Event $e */
            $e = $o['event'];
            $date = $o['start']->toDateString();
            $key = $e->id.'|'.$date;

            return [
                'key' => 'event-'.$e->id.'-'.$date,
                'kind' => 'event',
                'event_id' => $e->id,
                'occurs_on' => $date,
                'title' => $e->title,
                'description' => $e->description,
                'image_url' => $e->image_url,
                'category' => $e->category,
                'location' => $e->location,
                'starts_at' => $o['start']->toIso8601String(),
                'ends_at' => $o['end']?->toIso8601String(),
                'all_day' => (bool) $e->all_day,
                'recurring' => $e->isRecurring(),
                'recurrence' => $e->recurrence ?? 'none',
                'recurrence_label' => Event::RECURRENCES[$e->recurrence ?? 'none'] ?? null,
                'target' => $e->targetLabel(),
                'scopes' => $e->is_personal ? [] : $e->audienceList(),
                'recurrence_until' => $e->recurrence_until?->toDateString(),
                'series_starts_at' => $e->starts_at->toIso8601String(),
                'series_ends_at' => $e->ends_at?->toIso8601String(),
                'personal' => (bool) $e->is_personal,
                'remind_all' => (bool) $e->remind_all,
                'can_edit' => $editable($e),
                'going_count' => (int) ($going[$key] ?? 0),
                'my_response' => $mine->get($key)?->response,
                'my_volunteer' => (bool) ($mine->get($key)?->volunteer ?? false),
            ];
        })->all();
    }

    /**
     * Un utilisateur peut-il modifier cet evenement ? Auteur ; sinon events.manage et toutes les
     * portees de l'evenement comprises dans ce qu'il a le droit de viser.
     */
    public static function canEdit(User $user, Event $e): bool
    {
        if ((int) $e->created_by === $user->id) {
            return true;
        }
        if ($e->is_personal || ! $user->hasPermission('events.manage')) {
            return false;
        }
        if ($user->canBroadcastAll()) {
            return true;
        }
        $options = \App\Support\Audience::options($user);
        $allowed = [
            'tribe' => array_column($options['tribes'], 'id'),
            'gem' => array_column($options['gems'], 'id'),
            'department' => array_column($options['departments'], 'id'),
        ];
        $scopes = $e->audienceList();

        return $scopes !== [] && collect($scopes)->every(fn ($s) => $s['type'] !== 'church' && in_array((int) $s['id'], $allowed[$s['type']] ?? [], true));
    }

    /**
     * Anniversaires de mariage (jour + mois) : un seul element par couple confirme.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function weddings(Carbon $from, Carbon $to): array
    {
        $profiles = Profile::where('is_completed', true)->where('marital_status', 'marie')
            ->whereNotNull('wedding_day')->whereNotNull('wedding_month')
            ->get(['user_id', 'first_name', 'last_name', 'wedding_day', 'wedding_month', 'spouse_name']);
        $spouses = \App\Models\FamilyLink::where('relation', 'spouse')->where('status', 'confirmed')
            ->whereIn('user_id', $profiles->pluck('user_id'))->pluck('relative_user_id', 'user_id');

        $out = [];
        $seen = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            foreach ($profiles as $p) {
                $partner = $spouses[$p->user_id] ?? null;
                $coupleKey = $partner ? min($p->user_id, $partner).'-'.max($p->user_id, $partner) : 'u'.$p->user_id;
                $day = (int) $p->wedding_day;
                $month = (int) $p->wedding_month;
                if (! checkdate($month, $day, $year)) {
                    if (! ($month === 2 && $day === 29)) {
                        continue;
                    }
                    $day = 28;
                }
                $date = Carbon::create($year, $month, $day)->toDateString();
                if ($date < $from->toDateString() || $date > $to->toDateString() || isset($seen[$coupleKey.$date])) {
                    continue;
                }
                $seen[$coupleKey.$date] = true;
                $partnerName = $partner ? $profiles->firstWhere('user_id', $partner)?->full_name : $p->spouse_name;
                $names = array_values(array_filter([$p->full_name, $partnerName]));
                $out[] = [
                    'key' => 'wedding-'.$coupleKey.'-'.$date,
                    'kind' => 'wedding',
                    'title' => 'Anniversaire de mariage de '.implode(' et ', $names),
                    'date' => $date,
                    'all_day' => true,
                    'people' => array_values(array_filter([
                        ['user_id' => $p->user_id, 'name' => $p->full_name],
                        $partner ? ['user_id' => $partner, 'name' => $partnerName] : null,
                    ])),
                ];
            }
        }
        usort($out, fn ($x, $y) => strcmp($x['date'], $y['date']));

        return $out;
    }

    /**
     * Anniversaires (jour + mois du profil), regroupes par jour.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function birthdays(Carbon $from, Carbon $to): array
    {
        $profiles = Profile::where('is_completed', true)
            ->whereNotNull('birth_day')->whereNotNull('birth_month')
            ->get(['id', 'user_id', 'first_name', 'last_name', 'birth_day', 'birth_month', 'photo_path']);

        $byDate = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            foreach ($profiles as $p) {
                $month = (int) $p->birth_month;
                $day = (int) $p->birth_day;
                if (! checkdate($month, $day, $year)) {
                    if ($month === 2 && $day === 29) {
                        $day = 28; // 29 fevrier fete le 28 les annees non bissextiles
                    } else {
                        continue;
                    }
                }
                $date = Carbon::create($year, $month, $day)->toDateString();
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $byDate[$date][] = ['user_id' => $p->user_id, 'name' => $p->full_name];
                }
            }
        }
        ksort($byDate);

        $out = [];
        foreach ($byDate as $date => $people) {
            $names = array_column($people, 'name');
            $out[] = [
                'key' => 'birthday-'.$date,
                'kind' => 'birthday',
                'title' => 'Anniversaire de '.self::joinNames($names),
                'date' => $date,
                'all_day' => true,
                'people' => $people,
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function holidays(Carbon $from, Carbon $to): array
    {
        return array_map(fn ($h) => [
            'key' => 'holiday-'.$h['date'].'-'.md5($h['title']),
            'kind' => 'holiday',
            'holiday_kind' => $h['kind'],
            'title' => $h['title'],
            'date' => $h['date'],
            'all_day' => true,
        ], Holidays::between($from, $to));
    }

    /**
     * Echeances personnelles : exercices a rendre et fiche FISS du mois.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tasks(User $user, Carbon $from, Carbon $to): array
    {
        $out = [];
        $exercises = self::exercisesFor($user)
            ->whereNotNull('closes_at')
            ->whereBetween('closes_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();
        $responses = ExerciseResponse::where('user_id', $user->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');
        $views = ExerciseVideoView::where('user_id', $user->id)->whereIn('exercise_id', $exercises->pluck('id'))->get()->keyBy('exercise_id');
        foreach ($exercises as $ex) {
            $out[] = [
                'key' => 'task-'.$ex->id,
                'kind' => 'task',
                'title' => ($ex->isVideo() ? 'À regarder : ' : 'À rendre : ').$ex->title,
                'date' => $ex->closes_at->toDateString(),
                'time' => $ex->closes_at->format('H:i'),
                'all_day' => true,
                'done' => ExerciseProgress::isDone($ex, $views->get($ex->id), $responses->get($ex->id)),
                'url' => '/exercices/'.$ex->id,
            ];
        }

        // Date limite de la FISS : dernier jour de chaque mois de l'intervalle.
        $month = $from->copy()->startOfMonth();
        while ($month->lte($to)) {
            $deadline = $month->copy()->endOfMonth()->startOfDay();
            if ($deadline->betweenIncluded($from->copy()->startOfDay(), $to)) {
                $filled = SpiritualHealthForm::where('user_id', $user->id)->where('period', $month->format('Y-m'))->exists();
                $out[] = [
                    'key' => 'fiss-'.$month->format('Y-m'),
                    'kind' => 'task',
                    'title' => 'Fiche FISS de '.$month->locale('fr')->isoFormat('MMMM'),
                    'date' => $deadline->toDateString(),
                    'all_day' => true,
                    'done' => $filled,
                    'url' => '/ma-fiche',
                ];
            }
            $month->addMonth();
        }

        return $out;
    }

    /** Exercices actifs qui s'adressent a ce fidele. */
    public static function exercisesFor(User $user): Builder
    {
        return Exercise::where('is_active', true)->visibleTo($user);
    }

    /** « A », « A et B », « A, B et C » */
    public static function joinNames(array $names): string
    {
        $names = array_values(array_filter($names));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return implode(', ', $names).' et '.$last;
    }
}
