<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Etapes spirituelles franchies par un fidele (bapteme, rempli de l'Esprit...). */
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('milestone_key');
            $table->date('reached_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_user_id', 'milestone_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
