<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Un fidele peut appartenir a plusieurs departements (relation multiple). */
    public function up(): void
    {
        Schema::create('department_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unique(['profile_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_profile');
    }
};
