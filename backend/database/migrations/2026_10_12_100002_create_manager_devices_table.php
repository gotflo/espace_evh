<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appareils de confiance du compte de gestion principal : sur un appareil autorise, le numero suffit
 * (ni code SMS, ni code). Un nouvel appareil s'autorise une seule fois avec un code genere sur le serveur.
 * Seule l'empreinte du jeton de l'appareil est conservee. Additif et reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 255)->nullable(); // navigateur / appareil, pour s'y retrouver
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_devices');
    }
};
