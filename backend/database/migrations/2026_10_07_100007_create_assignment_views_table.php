<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mon devoir (DS) : meme suivi de lecture que les exercices video, pour la video et pour l'audio
 * (passages reellement lus, avances rapides, vitesse, reprise, termine a 95 %).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->unsignedInteger('media_duration')->nullable()->after('audio_path'); // secondes
        });

        Schema::create('assignment_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('segments')->nullable();               // passages lus, fusionnes : [[debut, fin], ...]
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

            $table->unique(['assignment_id', 'user_id']);
            $table->index(['assignment_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_views');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('media_duration');
        });
    }
};
