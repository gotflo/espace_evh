<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programme hebdomadaire des cultes :
 * - events.remind_all : rappel a toute l'audience avant chaque occurrence (et non seulement
 *   aux inscrits), avec un recapitulatif la veille ;
 * - creation des rendez-vous reguliers de l'eglise (mercredi et dimanche), pour toute l'eglise.
 * Idempotente : un rendez-vous deja present (meme titre, hebdomadaire) n'est pas recree.
 */
return new class extends Migration
{
    /** [jour ISO (1 = lundi), debut, fin, titre, categorie, description] */
    private const SCHEDULE = [
        [3, '19:30', '21:30', "Mercredi de l'intercession", 'priere', "Soirée de prière et d'intercession."],
        [7, '08:30', '09:30', "Temps de prière & d'intercession", 'priere', 'Avant le culte, nous prions ensemble.'],
        [7, '09:30', '12:30', 'Culte de contemplation et de célébration', 'culte', 'Culte unique du dimanche.'],
        [7, '12:30', '13:00', 'Healing Time', 'priere', 'Temps de prière pour la guérison.'],
        [7, '13:00', null, 'Bloom Light · Coin cocktail fraternel', 'autre', 'Moment fraternel après le culte.'],
    ];

    public const LOCATION = '70, rue Racine Est, Chicoutimi';

    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('remind_all')->default(false)->after('is_personal');
        });

        $tz = config('app.timezone', 'America/Toronto');
        $today = Carbon::now($tz)->startOfDay();
        $now = now();

        foreach (self::SCHEDULE as [$isoDay, $start, $end, $title, $category, $description]) {
            $exists = DB::table('events')->where('title', $title)->where('recurrence', 'weekly')->exists();
            if ($exists) {
                DB::table('events')->where('title', $title)->where('recurrence', 'weekly')->update(['remind_all' => true]);

                continue;
            }
            // Premiere occurrence : le prochain jour concerne (aujourd'hui compris).
            $day = $today->copy()->addDays(($isoDay - $today->dayOfWeekIso + 7) % 7);
            [$h, $m] = array_map('intval', explode(':', $start));
            $startsAt = $day->copy()->setTime($h, $m);
            $endsAt = null;
            if ($end) {
                [$eh, $em] = array_map('intval', explode(':', $end));
                $endsAt = $day->copy()->setTime($eh, $em);
            }

            $id = DB::table('events')->insertGetId([
                'title' => $title,
                'description' => $description,
                'category' => $category,
                // Heure locale de l'eglise (comme les dates enregistrees par Eloquent).
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'all_day' => false,
                'recurrence' => 'weekly',
                'recurrence_until' => null,
                'location' => self::LOCATION,
                'is_personal' => false,
                'remind_all' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('publication_scopes')->insert([
                'scopable_type' => 'event', 'scopable_id' => $id, 'scope_type' => 'church', 'scope_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        $titles = array_column(self::SCHEDULE, 3);
        $ids = DB::table('events')->whereIn('title', $titles)->where('recurrence', 'weekly')->whereNull('created_by')->pluck('id');
        DB::table('publication_scopes')->where('scopable_type', 'event')->whereIn('scopable_id', $ids)->delete();
        DB::table('event_participations')->whereIn('event_id', $ids)->delete();
        DB::table('events')->whereIn('id', $ids)->delete();

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('remind_all');
        });
    }
};
