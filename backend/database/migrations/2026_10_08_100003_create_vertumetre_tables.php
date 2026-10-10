<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertumetre mensuel : questionnaire du membre (vie de priere et de jeune, meditation de la Parole,
 * fruit de l'Esprit et caractere), une reponse par mois, verrouillee a l'envoi. Meme logique que la
 * FISS : une modification passe par une demande approuvee par le patriarche (2 au plus par mois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vertumetre_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 7); // AAAA-MM : mois evalue
            $table->json('answers');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedTinyInteger('edit_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period']);
            $table->index(['period', 'user_id']);
        });

        Schema::create('vertumetre_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('vertumetre_forms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | used | expired | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('unlock_expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vertumetre_edit_requests');
        Schema::dropIfExists('vertumetre_forms');
    }
};
