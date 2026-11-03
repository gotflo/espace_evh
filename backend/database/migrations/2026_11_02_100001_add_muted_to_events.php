<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rappels coupes pour un evenement (ex. Bloom Light, a la demande du pasteur) : il reste au
 * calendrier et dans les horaires, mais ne declenche plus aucun message automatique (programme
 * de la veille, rappels, evenement modifie ou annule). Colonne ajoutee en fin de table : aucune
 * reecriture sur MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'muted')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('muted')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'muted')) {
            Schema::table('events', fn (Blueprint $t) => $t->dropColumn('muted'));
        }
    }
};
