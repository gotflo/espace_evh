<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Programme des cultes : ajout du « Samedi des miracles », chaque samedi de 18 h 30 a 20 h 30,
 * pour toute l'eglise, avec les memes rappels que les autres rendez-vous reguliers.
 * Idempotente : un rendez-vous hebdomadaire du meme titre n'est pas recree.
 */
return new class extends Migration
{
    private const TITLE = 'Samedi des miracles';

    public function up(): void
    {
        if (DB::table('events')->where('title', self::TITLE)->where('recurrence', 'weekly')->exists()) {
            DB::table('events')->where('title', self::TITLE)->where('recurrence', 'weekly')->update(['remind_all' => true]);

            return;
        }

        // Premiere occurrence : le prochain samedi (aujourd'hui compris), heure locale de l'eglise.
        $today = Carbon::now(config('app.timezone', 'America/Toronto'))->startOfDay();
        $day = $today->copy()->addDays((6 - $today->dayOfWeekIso + 7) % 7);
        $now = now();

        $id = DB::table('events')->insertGetId([
            'title' => self::TITLE,
            'description' => 'Chaque samedi, de 18 h 30 à 20 h 30.',
            'category' => 'culte',
            'starts_at' => $day->copy()->setTime(18, 30)->format('Y-m-d H:i:s'),
            'ends_at' => $day->copy()->setTime(20, 30)->format('Y-m-d H:i:s'),
            'all_day' => false,
            'recurrence' => 'weekly',
            'recurrence_until' => null,
            'location' => '70, rue Racine Est, Chicoutimi',
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

    public function down(): void
    {
        $ids = DB::table('events')->where('title', self::TITLE)->where('recurrence', 'weekly')->whereNull('created_by')->pluck('id');
        DB::table('publication_scopes')->where('scopable_type', 'event')->whereIn('scopable_id', $ids)->delete();
        DB::table('event_participations')->whereIn('event_id', $ids)->delete();
        DB::table('events')->whereIn('id', $ids)->delete();
    }
};
