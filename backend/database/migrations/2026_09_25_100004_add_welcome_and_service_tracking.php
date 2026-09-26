<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - Nouveaux inscrits : un responsable marque le fidele comme accueilli (suivi d'integration).
     * - Services : date d'entree dans un departement.
     * - Calendrier : jeton personnel pour l'abonnement (Google Agenda, iPhone, Outlook).
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestamp('welcomed_at')->nullable()->after('is_completed');
            $table->foreignId('welcomed_by')->nullable()->after('welcomed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('department_profile', function (Blueprint $table) {
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
        Schema::table('department_profile', function (Blueprint $table) {
            $table->dropTimestamps();
        });
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('welcomed_by');
            $table->dropColumn('welcomed_at');
        });
    }
};
