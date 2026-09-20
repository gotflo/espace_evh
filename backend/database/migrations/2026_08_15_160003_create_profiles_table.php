<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil du fidele (1 pour 1 avec un user).
     * Contient les informations completees apres la connexion.
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('first_name')->nullable();      // prenoms
            $table->string('last_name')->nullable();       // nom
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('tribe_id')->nullable()->constrained('tribes')->nullOnDelete();
            // Les departements sont multiples : voir la table pivot department_profile.
            $table->date('joined_at')->nullable();          // date d'arrivee dans l'eglise
            $table->text('notes')->nullable();
            $table->boolean('is_completed')->default(false); // profil complete au moins une fois
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
