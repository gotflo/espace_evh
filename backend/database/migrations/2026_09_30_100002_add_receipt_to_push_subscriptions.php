<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accuse de reception envoye par l'appareil lui-meme (service worker) : le serveur sait
     * si le message est vraiment arrive sur le telephone et s'il a pu etre affiche.
     */
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->timestamp('last_received_at')->nullable()->after('last_used_at');
            $table->string('last_error', 255)->nullable()->after('last_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['last_received_at', 'last_error']);
        });
    }
};
