<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enfants : le nom et l'AGE (demande de l'eglise, 09/11), et non plus la date de naissance.
 * - age (annees) et age_on (jour ou l'age a ete donne) : l'age affiche avance d'un an chaque annee depuis ce jour ;
 * - les dates (ou annees) deja saisies sont converties en age au jour de la migration, puis effacees (remplacees).
 * Additive : les anciennes colonnes restent (vides pour les enfants), l'ancien code continue de fonctionner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_links', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('birth_date');
            $table->date('age_on')->nullable()->after('age');
        });

        $today = now()->startOfDay();
        DB::table('family_links')->where('relation', 'child')
            ->where(fn ($q) => $q->whereNotNull('birth_date')->orWhereNotNull('birth_year'))
            ->orderBy('id')->each(function ($link) use ($today) {
                $age = $link->birth_date
                    ? (int) \Illuminate\Support\Carbon::parse($link->birth_date)->startOfDay()->diffInYears($today)
                    : max(0, (int) $today->year - (int) $link->birth_year);
                DB::table('family_links')->where('id', $link->id)->update([
                    'age' => min(99, max(0, $age)), 'age_on' => $today->toDateString(),
                    'birth_date' => null, 'birth_year' => null,
                ]);
            });
    }

    public function down(): void
    {
        // Les dates converties ne sont pas recuperables ici (sauvegarde de la base faite avant la mise en ligne).
        Schema::table('family_links', function (Blueprint $table) {
            $table->dropColumn(['age', 'age_on']);
        });
    }
};
