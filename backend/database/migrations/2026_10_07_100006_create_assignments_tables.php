<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mon devoir (DS) : le PR publie une video (YouTube) ou un audio (fichier ou lien) ;
 * chaque membre ecrit un resume et ses actions et resolutions.
 * Un devoir retire n'est que masque (deleted_at) : les reponses des membres sont conservees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('instructions')->nullable();
            $table->string('speaker', 120)->nullable();
            $table->string('media_type', 10); // video | audio
            $table->string('media_url', 500)->nullable();
            $table->string('youtube_id', 20)->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('published_at');
        });

        Schema::create('assignment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('summary');
            $table->text('actions');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_responses');
        Schema::dropIfExists('assignments');
    }
};
