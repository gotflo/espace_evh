<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exercices video (YouTube) et fermeture automatique :
 * - exercises : video_id (identifiant YouTube, jamais d'URL libre), duree, reponse ecrite
 *   facultative, date et heure de fermeture (closes_at, reprise de l'ancienne echeance) ;
 * - exercise_video_views : suivi du visionnage par fidele (passages reellement regardes,
 *   avances rapides, vitesse, termine ou non).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('video_id', 16)->nullable()->after('type');
            $table->unsignedInteger('video_duration')->nullable()->after('video_id'); // secondes
            $table->boolean('requires_response')->default(true)->after('video_duration');
            $table->dateTime('closes_at')->nullable()->after('due_date');
            $table->index('closes_at');
        });

        // Ancienne echeance (date) : l'exercice se ferme a la fin de ce jour-la.
        DB::table('exercises')->whereNotNull('due_date')->orderBy('id')->each(function ($e) {
            DB::table('exercises')->where('id', $e->id)->update(['closes_at' => substr((string) $e->due_date, 0, 10).' 23:59:00']);
        });

        Schema::create('exercise_video_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('segments')->nullable();               // passages regardes, fusionnes : [[debut, fin], ...]
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('last_position')->default(0);
            $table->unsignedInteger('max_position')->default(0);
            $table->unsignedSmallInteger('seek_count')->default(0);    // avances rapides
            $table->unsignedInteger('skipped_seconds')->default(0);    // duree sautee en avancant
            $table->decimal('max_rate', 3, 2)->default(1);            // vitesse de lecture maximale
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['exercise_id', 'user_id']);
            $table->index(['exercise_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_video_views');
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropIndex(['closes_at']);
            $table->dropColumn(['video_id', 'video_duration', 'requires_response', 'closes_at']);
        });
    }
};
