<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les envois de test des notifications push ne s'affichent plus dans la page Notifications :
 * les « Notification de test » deja enregistrees sont retirees (diagnostic, sans contenu pour le membre).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_notifications')->where('type', 'system')->where('title', 'Notification de test')->delete();
    }

    public function down(): void
    {
        // Rien a restaurer : il s'agissait de messages de test.
    }
};
