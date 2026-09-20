<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** On ne demande plus l'annee de naissance : seulement le jour et le mois. */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('birth_day')->nullable()->after('birth_date');   // 1-31
            $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_day');  // 1-12
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['birth_day', 'birth_month']);
        });
    }
};
