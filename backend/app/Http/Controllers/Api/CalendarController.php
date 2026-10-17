<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CalendarController extends Controller
{
    /** Fuseau des heures ecrites dans le flux iCal (celui de l'eglise, avec son bloc VTIMEZONE). */
    private const TZID = 'America/Toronto';

    /**
     * Tout ce qui est programme entre deux dates (vue mois / semaine / liste).
     * only=events : seulement les evenements (encart « Nos rendez-vous » du tableau de bord),
     * sans parcourir les anniversaires de toute l'eglise (appel le plus frequent a l'ouverture).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'only' => ['nullable', 'in:events'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 100, 422, 'Intervalle trop long (100 jours maximum).');

        $user = $request->user();
        $occurrences = CalendarService::occurrences(CalendarService::eventsQuery($user), $from, $to);
        $all = ($data['only'] ?? null) === null;

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => CalendarService::presentOccurrences($user, $occurrences),
            'birthdays' => $all ? CalendarService::birthdays($from, $to) : [],
            'weddings' => $all ? CalendarService::weddings($from, $to) : [],
            'holidays' => $all ? CalendarService::holidays($from, $to) : [],
            'tasks' => $all ? CalendarService::tasks($user, $from, $to) : [],
        ]);
    }

    /** Lien d'abonnement personnel (Google Agenda, Calendrier iPhone, Outlook). */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->calendar_token) {
            $user->forceFill(['calendar_token' => Str::random(48)])->save();
        }

        return response()->json(['url' => $this->feedUrl($user)]);
    }

    /** Regenere le lien (l'ancien cesse de fonctionner). */
    public function resetFeed(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['calendar_token' => Str::random(48)])->save();

        return response()->json(['url' => $this->feedUrl($user)]);
    }

    /** Flux iCalendar (protege par le jeton personnel, sans connexion). */
    public function ics(string $token): Response
    {
        $user = strlen($token) >= 32 ? User::where('calendar_token', $token)->first() : null;
        // Compte bloque, non valide ou compte de gestion : meme reponse qu'un jeton inconnu.
        abort_unless($user && ! $user->blocked_at && $user->isApproved() && ! $user->isPlatformManager(), 404);

        $from = now()->subMonths(2)->startOfDay();
        $events = CalendarService::eventsQuery($user)
            ->where(fn ($q) => $q->where('starts_at', '>=', $from)
                ->orWhere(fn ($r) => $r->where('recurrence', '!=', 'none')
                    ->where(fn ($u) => $u->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $from->toDateString()))))
            ->orderBy('starts_at')->limit(500)->get();

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'vasesdhonneur';
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            "PRODID:-//Vases d'Honneur Chicoutimi//Espace membre//FR",
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape("Vases d'Honneur Chicoutimi"),
            'X-WR-TIMEZONE:'.self::TZID,
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
            // Fuseau de l'eglise (heure de l'Est, changements d'heure en mars et novembre) : les
            // evenements recurrents gardent leur heure locale apres le changement d'heure.
            'BEGIN:VTIMEZONE',
            'TZID:'.self::TZID,
            'X-LIC-LOCATION:'.self::TZID,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'DTSTART:19700308T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'DTSTART:19701101T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];

        foreach ($events as $e) {
            array_push($lines, ...$this->vevent($e, $host));
        }

        // Anniversaires : evenements annuels d'une journee.
        foreach (CalendarService::birthdays(now()->startOfDay(), now()->addYear()->subDay()->endOfDay()) as $b) {
            $date = Carbon::parse($b['date']);
            foreach ($b['people'] as $person) {
                array_push($lines,
                    'BEGIN:VEVENT',
                    'UID:birthday-'.$person['user_id'].'@'.$host,
                    'DTSTAMP:'.$stamp,
                    'DTSTART;VALUE=DATE:'.$date->format('Ymd'),
                    'DTEND;VALUE=DATE:'.$date->copy()->addDay()->format('Ymd'),
                    'RRULE:FREQ=YEARLY',
                    'SUMMARY:'.$this->escape('Anniversaire de '.$person['name']),
                    'TRANSP:TRANSPARENT',
                    'END:VEVENT',
                );
            }
        }

        $lines[] = 'END:VCALENDAR';
        $body = implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="vases-dhonneur.ics"',
        ]);
    }

    /** @return array<int, string> */
    private function vevent(Event $e, string $host): array
    {
        $fmt = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        // Heure locale de l'eglise (et non UTC) : une repetition garde la meme heure apres le changement d'heure.
        $local = fn (Carbon $d) => ';TZID='.self::TZID.':'.$d->copy()->setTimezone(self::TZID)->format('Ymd\THis');
        $out = [
            'BEGIN:VEVENT',
            'UID:event-'.$e->id.'@'.$host,
            'DTSTAMP:'.$fmt($e->updated_at ?? now()),
            'LAST-MODIFIED:'.$fmt($e->updated_at ?? now()),
        ];
        if ($e->all_day) {
            $out[] = 'DTSTART;VALUE=DATE:'.$e->starts_at->format('Ymd');
            $out[] = 'DTEND;VALUE=DATE:'.($e->ends_at ?? $e->starts_at)->copy()->addDay()->format('Ymd');
        } else {
            $out[] = 'DTSTART'.$local($e->starts_at);
            $out[] = 'DTEND'.$local($e->ends_at ?? $e->starts_at->copy()->addHour());
        }
        $rrule = match ($e->recurrence) {
            'daily' => 'FREQ=DAILY',
            'weekly' => 'FREQ=WEEKLY',
            'biweekly' => 'FREQ=WEEKLY;INTERVAL=2',
            'monthly' => 'FREQ=MONTHLY'.$this->monthlyDay((int) $e->starts_at->day),
            default => null,
        };
        if ($rrule) {
            if ($e->recurrence_until) {
                // Debut en date seule : fin en date seule ; sinon fin en UTC (RFC 5545).
                $rrule .= ';UNTIL='.($e->all_day ? $e->recurrence_until->format('Ymd') : $fmt($e->recurrence_until->copy()->endOfDay()));
            }
            $out[] = 'RRULE:'.$rrule;
        }
        $out[] = 'SUMMARY:'.$this->escape($e->title);
        if ($e->description) {
            $out[] = 'DESCRIPTION:'.$this->escape($e->description);
        }
        if ($e->location) {
            $out[] = 'LOCATION:'.$this->escape($e->location);
        }
        $out[] = 'CATEGORIES:'.$this->escape(EventController::CATEGORIES[$e->category] ?? 'Autre');
        if (! $e->all_day) {
            array_push($out, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->escape($e->title), 'TRIGGER:-PT1H', 'END:VALARM');
        }
        $out[] = 'END:VEVENT';

        return $out;
    }

    /**
     * Repetition mensuelle du 29, 30 ou 31 : l'application la ramene au dernier jour des mois plus
     * courts (le 31 janvier donne le 28 fevrier puis le 31 mars). Sans precision, les agendas sautent
     * ces mois : on prend le plus grand jour existant parmi 28..jour.
     */
    private function monthlyDay(int $day): string
    {
        if ($day === 31) {
            return ';BYMONTHDAY=-1';
        }

        return $day >= 29 ? ';BYMONTHDAY='.implode(',', range(28, $day)).';BYSETPOS=-1' : '';
    }

    private function feedUrl(User $user): string
    {
        return rtrim((string) config('app.url'), '/').'/api/calendar/feed/'.$user->calendar_token.'.ics';
    }

    private function escape(string $text): string
    {
        // Aucun retour a la ligne brut (CR, LF) : impossible d'injecter une ligne iCal.
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Lignes de 75 octets maximum (RFC 5545), sans couper un caractere UTF-8. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $chunks = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $max = $chunks === [] ? 75 : 74; // la ligne de continuation commence par un espace
            if (strlen($current) + strlen($char) > $max) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
