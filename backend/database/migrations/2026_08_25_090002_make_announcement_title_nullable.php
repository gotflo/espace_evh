<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Titre facultatif : permet une annonce composee uniquement d'une image. */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
        });
    }
};
