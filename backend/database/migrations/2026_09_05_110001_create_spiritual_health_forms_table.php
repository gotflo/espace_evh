<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Fiche de sante spirituelle (FISS) : auto-evaluation mensuelle du fidele. */
    public function up(): void
    {
        Schema::create('spiritual_health_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 7); // AAAA-MM

            // Vie spirituelle (/20 chacun)
            $table->unsignedTinyInteger('meditation')->nullable();
            $table->unsignedTinyInteger('priere')->nullable();
            $table->unsignedTinyInteger('jeune')->nullable();
            // Sanctification : mal | moyen | bien
            $table->string('sanctification_corps')->nullable();
            $table->string('sanctification_ame')->nullable();
            $table->string('sanctification_esprit')->nullable();

            // Vie sociale (/20 chacun)
            $table->unsignedTinyInteger('situation_financiere')->nullable();
            $table->unsignedTinyInteger('situation_familiale')->nullable();
            $table->unsignedTinyInteger('situation_conjugale')->nullable(); // maries uniquement

            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']); // une fiche par mois
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spiritual_health_forms');
    }
};
