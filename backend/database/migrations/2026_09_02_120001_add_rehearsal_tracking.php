<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suivi des repetitions : seuls certains departements (ex chorale) suivent la
     * ponctualite (retard) et les absences detaillees. Les cultes restent presence/absence simple.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('tracks_rehearsal')->default(false)->after('is_active');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('kind')->default('culte')->after('event');   // culte | repetition
            $table->string('status')->default('present')->after('kind'); // present | retard | absent_justifie | absent
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('tracks_rehearsal');
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['kind', 'status']);
        });
    }
};
