<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reponses des fideles aux exercices. */
    public function up(): void
    {
        Schema::create('exercise_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('response');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['exercise_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_responses');
    }
};
