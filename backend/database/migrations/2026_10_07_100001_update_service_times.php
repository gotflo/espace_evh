<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Horaires des cultes demandes par les pasteurs :
 * - dimanche : le programme commence a 8 h 45 (temps de priere avant le culte, au lieu de 8 h 30) ;
 * - mercredi : plus d'heure de fin affichee.
 * Seuls les rendez-vous d'origine encore a l'ancienne valeur sont modifies : un horaire deja
 * change par un pasteur n'est jamais ecrase. Retour arriere possible (memes conditions).
 */
return new class extends Migration
{
    private const SUNDAY_PRAYER = "Temps de prière & d'intercession";

    private const WEDNESDAY = "Mercredi de l'intercession";

    public function up(): void
    {
        $this->setTime(self::SUNDAY_PRAYER, '08:30', '08:45');

        DB::table('events')->where('title', self::WEDNESDAY)->where('recurrence', 'weekly')->where('remind_all', true)
            ->get(['id', 'starts_at', 'ends_at'])
            ->each(function ($e) {
                if ($e->ends_at && substr((string) $e->ends_at, 11, 5) === '21:30') {
                    DB::table('events')->where('id', $e->id)->update(['ends_at' => null, 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        $this->setTime(self::SUNDAY_PRAYER, '08:45', '08:30');

        DB::table('events')->where('title', self::WEDNESDAY)->where('recurrence', 'weekly')->where('remind_all', true)
            ->whereNull('ends_at')->get(['id', 'starts_at'])
            ->each(function ($e) {
                if (substr((string) $e->starts_at, 11, 5) === '19:30') {
                    DB::table('events')->where('id', $e->id)->update(['ends_at' => substr((string) $e->starts_at, 0, 10).' 21:30:00', 'updated_at' => now()]);
                }
            });
    }

    /** Change l'heure de debut (meme jour) d'un rendez-vous hebdomadaire encore a l'heure $from. */
    private function setTime(string $title, string $from, string $to): void
    {
        DB::table('events')->where('title', $title)->where('recurrence', 'weekly')->where('remind_all', true)
            ->get(['id', 'starts_at'])
            ->each(function ($e) use ($from, $to) {
                if (substr((string) $e->starts_at, 11, 5) === $from) {
                    DB::table('events')->where('id', $e->id)->update([
                        'starts_at' => substr((string) $e->starts_at, 0, 10).' '.$to.':00',
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
