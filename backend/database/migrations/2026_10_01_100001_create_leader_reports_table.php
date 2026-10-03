<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports mensuels des responsables : un rapport par tribu (rempli par le patriarche) et par
 * departement (rempli par ses responsables) pour chaque mois. Les reponses au questionnaire
 * sont gardees telles quelles ; la liste des ames gagnees est figee au moment de l'envoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leader_reports', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);                  // tribe | department
            $table->unsignedBigInteger('scope_id');      // tribu ou departement concerne
            $table->string('scope_name', 80)->nullable(); // nom au moment de l'envoi (reste lisible si l'entite est supprimee)
            $table->string('period', 7);                 // mois concerne, AAAA-MM
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('draft'); // draft | submitted
            $table->json('answers')->nullable();
            $table->json('souls')->nullable();           // ames gagnees, figees a l'envoi
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'scope_id', 'period']);
            $table->index(['period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leader_reports');
    }
};
