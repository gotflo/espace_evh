<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Jours feries du Quebec / Canada et grandes fetes chretiennes, calcules pour une annee
 * (aucune saisie manuelle : le calendrier se remplit tout seul chaque annee).
 */
class Holidays
{
    /**
     * @return array<int, array{date: string, title: string, kind: string}>
     *                                                                    kind : ferie | fete | chretien
     */
    public static function forYear(int $year): array
    {
        $easter = self::easter($year);
        $d = fn (int $m, int $day) => Carbon::create($year, $m, $day)->toDateString();
        $nthWeekday = fn (int $m, int $weekday, int $n) => self::nthWeekday($year, $m, $weekday, $n)->toDateString();

        // Journee nationale des patriotes : lundi precedant le 25 mai.
        $patriotes = Carbon::create($year, 5, 24);
        while ($patriotes->dayOfWeek !== Carbon::MONDAY) {
            $patriotes->subDay();
        }

        $list = [
            [$d(1, 1), "Jour de l'An", 'ferie'],
            [$d(2, 14), 'Saint-Valentin', 'fete'],
            [$easter->copy()->subDays(7)->toDateString(), 'Dimanche des Rameaux', 'chretien'],
            [$easter->copy()->subDays(2)->toDateString(), 'Vendredi saint', 'ferie'],
            [$easter->toDateString(), 'Pâques', 'chretien'],
            [$easter->copy()->addDay()->toDateString(), 'Lundi de Pâques', 'ferie'],
            [$easter->copy()->addDays(39)->toDateString(), 'Ascension', 'chretien'],
            [$easter->copy()->addDays(49)->toDateString(), 'Pentecôte', 'chretien'],
            [$nthWeekday(5, Carbon::SUNDAY, 2), 'Fête des Mères', 'fete'],
            [$patriotes->toDateString(), 'Journée nationale des patriotes', 'ferie'],
            [$nthWeekday(6, Carbon::SUNDAY, 3), 'Fête des Pères', 'fete'],
            [$d(6, 24), 'Fête nationale du Québec', 'ferie'],
            [$d(7, 1), 'Fête du Canada', 'ferie'],
            [$nthWeekday(9, Carbon::MONDAY, 1), 'Fête du Travail', 'ferie'],
            [$d(9, 30), 'Journée nationale de la vérité et de la réconciliation', 'fete'],
            [$nthWeekday(10, Carbon::MONDAY, 2), "Action de grâce", 'ferie'],
            [$d(11, 11), 'Jour du Souvenir', 'fete'],
            [$d(12, 24), 'Veille de Noël', 'chretien'],
            [$d(12, 25), 'Noël', 'ferie'],
            [$d(12, 26), 'Lendemain de Noël', 'fete'],
            [$d(12, 31), "Veille du Jour de l'An", 'fete'],
        ];

        return array_map(fn ($h) => ['date' => $h[0], 'title' => $h[1], 'kind' => $h[2]], $list);
    }

    /** @return array<int, array{date: string, title: string, kind: string}> */
    public static function between(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($y = $from->year; $y <= $to->year; $y++) {
            foreach (self::forYear($y) as $h) {
                if ($h['date'] >= $from->toDateString() && $h['date'] <= $to->toDateString()) {
                    $out[] = $h;
                }
            }
        }

        return $out;
    }

    /** Dimanche de Paques (algorithme de Meeus / Jones / Butcher, calendrier gregorien). */
    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    private static function nthWeekday(int $year, int $month, int $weekday, int $n): Carbon
    {
        $date = Carbon::create($year, $month, 1)->startOfDay();
        while ($date->dayOfWeek !== $weekday) {
            $date->addDay();
        }

        return $date->addWeeks($n - 1);
    }
}
