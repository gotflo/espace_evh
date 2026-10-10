<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mes RM (Résolutions du Mois) :
 * - monthly_resolutions : une fiche par membre et par mois (meditation, priere, jeune,
 *   sanctification, assiduite aux cultes, autres) ; « renewed » = resolutions reprises du mois
 *   precedent (« Je renouvelle ») ; « outcome » = bilan fait le mois suivant (atteinte, en partie, non) ;
 * - resolution_resources : ressources proposees par le PR (lien ou video, nom de l'auteur) par theme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 7); // AAAA-MM
            $table->text('meditation')->nullable();
            $table->text('priere')->nullable();
            $table->text('jeune')->nullable();
            $table->text('sanctification')->nullable();
            $table->text('assiduite')->nullable();
            $table->text('autres')->nullable();
            $table->json('renewed')->nullable();
            $table->json('outcome')->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
            $table->index('period');
        });

        Schema::create('resolution_resources', function (Blueprint $table) {
            $table->id();
            $table->string('theme', 20); // meditation | priere | jeune | culte
            $table->string('title', 150);
            $table->string('author', 120);
            $table->string('url', 500);
            $table->string('youtube_id', 20)->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['theme', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resolution_resources');
        Schema::dropIfExists('monthly_resolutions');
    }
};
