<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evenements recurrents (chaque jour / semaine / 2 semaines / mois) et journee entiere.
     * La reponse d'un fidele (RSVP) porte desormais sur une occurrence precise (occurs_on).
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('all_day')->default(false)->after('ends_at');
            $table->string('recurrence', 20)->default('none')->after('all_day'); // none | daily | weekly | biweekly | monthly
            $table->date('recurrence_until')->nullable()->after('recurrence');
        });

        Schema::table('event_participations', function (Blueprint $table) {
            $table->date('occurs_on')->nullable()->after('user_id');
        });

        // Reponses existantes : elles portent sur la date de l'evenement.
        foreach (DB::table('events')->get(['id', 'starts_at']) as $event) {
            DB::table('event_participations')->where('event_id', $event->id)
                ->update(['occurs_on' => substr((string) $event->starts_at, 0, 10)]);
        }

        // Le nouvel index commence par event_id : il couvre la cle etrangere (MySQL)
        // avant la suppression de l'ancien.
        Schema::table('event_participations', function (Blueprint $table) {
            $table->unique(['event_id', 'user_id', 'occurs_on'], 'event_participations_occurrence_unique');
        });
        Schema::table('event_participations', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('event_participations', function (Blueprint $table) {
            $table->unique(['event_id', 'user_id']);
        });
        Schema::table('event_participations', function (Blueprint $table) {
            $table->dropUnique('event_participations_occurrence_unique');
            $table->dropColumn('occurs_on');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['all_day', 'recurrence', 'recurrence_until']);
        });
    }
};
