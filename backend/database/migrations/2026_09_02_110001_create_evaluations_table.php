<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Notes / evaluations d'un fidele (notees /20), saisies par un responsable. */
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // le fidele evalue
            $table->string('type')->default('autre');   // devoir|examen|meditation_perso|...
            $table->string('title')->nullable();
            $table->decimal('score', 5, 2);              // note obtenue
            $table->decimal('max_score', 5, 2)->default(20);
            $table->date('evaluated_on');
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'evaluated_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
