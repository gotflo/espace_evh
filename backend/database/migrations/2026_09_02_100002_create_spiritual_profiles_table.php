<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Profil spirituel du fidele (1 pour 1), inspire de PDVIE "Infos spirituelles". */
    public function up(): void
    {
        Schema::create('spiritual_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->unsignedSmallInteger('conversion_year')->nullable();
            $table->string('conversion_verse')->nullable();
            $table->date('baptism_immersion_date')->nullable();
            $table->string('baptism_holy_spirit')->nullable();   // oui|non|je_ne_sais_pas|autre
            $table->boolean('speaks_tongues')->nullable();
            $table->unsignedSmallInteger('tongues_since_year')->nullable();
            $table->boolean('active_member')->nullable();
            $table->string('prayer_frequency')->nullable();      // quotidien|hebdomadaire|rare
            $table->boolean('gifts_known')->nullable();
            $table->text('gifts_detail')->nullable();
            $table->text('last_prayer_subject')->nullable();     // dernier sujet cherche a l'exaucement
            $table->text('joyful_service')->nullable();          // ce qu'il aime faire avec joie dans le Seigneur
            $table->text('focus_effort')->nullable();            // a quoi pense-t-il le plus / fournit le plus d'effort

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spiritual_profiles');
    }
};
