<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ressources video des RM : meme suivi de lecture que les exercices video et les devoirs (passages
 * reellement lus, avances rapides, vitesse, reprise la ou l'on s'etait arrete, regardee a 95 %).
 * Additif et reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolution_resources', function (Blueprint $table) {
            $table->unsignedInteger('media_duration')->nullable()->after('youtube_id'); // secondes
        });

        Schema::create('resource_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_resource_id')->constrained('resolution_resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('segments')->nullable();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('last_position')->default(0);
            $table->unsignedInteger('max_position')->default(0);
            $table->unsignedSmallInteger('seek_count')->default(0);
            $table->unsignedInteger('skipped_seconds')->default(0);
            $table->decimal('max_rate', 3, 2)->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['resolution_resource_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_views');
        Schema::table('resolution_resources', fn (Blueprint $table) => $table->dropColumn('media_duration'));
    }
};
