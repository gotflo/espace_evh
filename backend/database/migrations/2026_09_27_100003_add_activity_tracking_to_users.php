<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activite des membres, stockee (et non plus recalculee a chaque lecture) :
     * - last_seen_at : derniere utilisation de l'application (connexion ou session en cours) ;
     * - activity_status : active | inactive, mis a jour chaque jour par les automatismes ;
     * - activity_changed_at : date du dernier changement (chaque changement est aussi audite).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->string('activity_status', 10)->default('active')->after('activity_override');
            $table->timestamp('activity_changed_at')->nullable()->after('activity_status');
            $table->index('activity_status');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal')->after('type'); // low | normal | high
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['activity_status']);
            $table->dropColumn(['last_seen_at', 'activity_status', 'activity_changed_at']);
        });
    }
};
