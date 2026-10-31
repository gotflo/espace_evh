<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Courriels et textos : priorite (les messages urgents passent devant une diffusion en cours) ;
 * - push : curseur d'avancement (une diffusion reprend ou elle s'est arretee, sans renvoi) et date
 *   a partir de laquelle la boite d'envoi peut partir (automatismes : tout de suite) ;
 * - annonces : nombre de destinataires garde sur l'annonce (la liste ne recompte plus des milliers de lignes).
 * Colonnes ajoutees en fin de table : aucune reecriture sur MariaDB (ALGORITHM=INSTANT par defaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outbound_messages', 'priority')) {
            Schema::table('outbound_messages', function (Blueprint $table) {
                $table->unsignedTinyInteger('priority')->default(5); // 1 : urgent ; 5 : normal
            });
        }
        if (! Schema::hasIndex('outbound_messages', ['channel', 'status', 'priority', 'available_at'])) {
            Schema::table('outbound_messages', fn (Blueprint $t) => $t->index(['channel', 'status', 'priority', 'available_at']));
        }
        if (! Schema::hasIndex('outbound_messages', ['channel', 'sent_at'])) {
            Schema::table('outbound_messages', fn (Blueprint $t) => $t->index(['channel', 'sent_at']));
        }

        if (! Schema::hasColumn('push_outbox', 'cursor')) {
            Schema::table('push_outbox', function (Blueprint $table) {
                $table->unsignedInteger('cursor')->default(0); // destinataires deja traites
                $table->timestamp('available_at')->nullable(); // null : apres la minute laissee a l'envoi immediat
                $table->string('claim_token', 32)->nullable(); // lot reserve par un passage
            });
        }

        if (! Schema::hasColumn('announcements', 'recipients_count')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->unsignedInteger('recipients_count')->nullable();
            });
            // Annonces existantes : compte fait une fois, annonce par annonce (aucun verrou long).
            DB::table('announcements')->orderBy('id')->select('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('announcements')->where('id', $row->id)->update([
                        'recipients_count' => DB::table('announcement_user')->where('announcement_id', $row->id)->count(),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('announcements', 'recipients_count')) {
            Schema::table('announcements', fn (Blueprint $t) => $t->dropColumn('recipients_count'));
        }
        if (Schema::hasColumn('push_outbox', 'cursor')) {
            Schema::table('push_outbox', fn (Blueprint $t) => $t->dropColumn(['cursor', 'available_at', 'claim_token']));
        }
        if (Schema::hasIndex('outbound_messages', ['channel', 'sent_at'])) {
            Schema::table('outbound_messages', fn (Blueprint $t) => $t->dropIndex(['channel', 'sent_at']));
        }
        if (Schema::hasIndex('outbound_messages', ['channel', 'status', 'priority', 'available_at'])) {
            Schema::table('outbound_messages', fn (Blueprint $t) => $t->dropIndex(['channel', 'status', 'priority', 'available_at']));
        }
        if (Schema::hasColumn('outbound_messages', 'priority')) {
            Schema::table('outbound_messages', fn (Blueprint $t) => $t->dropColumn('priority'));
        }
    }
};
