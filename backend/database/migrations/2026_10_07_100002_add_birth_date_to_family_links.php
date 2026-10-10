<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enfants : date de naissance complete (jour, mois, annee) et non plus seulement l'annee.
 * L'annee reste enregistree (calculee depuis la date) : les enfants deja saisis avec
 * leur seule annee de naissance restent affiches, le parent est invite a completer la date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_links', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('birth_year');
        });
    }

    public function down(): void
    {
        Schema::table('family_links', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
