<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FISS : rubrique « Vie intérieure » (textes libres, facultatifs, confidentiels) :
 * addiction, victoire sur une addiction, saines compagnies, blessure interieure,
 * situations difficiles, pensees de decouragement. Les fiches existantes ne changent pas.
 */
return new class extends Migration
{
    private const COLUMNS = ['addiction', 'addiction_victory', 'companions', 'inner_wound', 'difficult_situations', 'discouragement'];

    public function up(): void
    {
        Schema::table('spiritual_health_forms', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->text($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('spiritual_health_forms', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
