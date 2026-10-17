<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motif d'un blocage de creneau : jusqu'a 500 caracteres (comme le formulaire et le motif d'annulation),
 * au lieu de 255 (un motif plus long etait coupe sans prevenir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_blocks', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_blocks', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->change();
        });
    }
};
