<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boite d'envoi des notifications push : chaque envoi est d'abord enregistre ici, puis
     * envoye tout de suite (apres la reponse HTTP). Si cet envoi n'a pas pu se faire
     * (processus interrompu par l'hebergeur, service push injoignable...), le cron le rattrape
     * a la minute suivante. Aucun push n'est ainsi perdu sans trace.
     */
    public function up(): void
    {
        Schema::create('push_outbox', function (Blueprint $table) {
            $table->id();
            $table->longText('user_ids');           // destinataires (JSON)
            $table->text('payload');                // titre, texte, lien (JSON)
            $table->string('urgency', 10)->default('normal');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();  // envoi en cours (evite un double envoi)
            $table->timestamp('sent_at')->nullable();
            $table->text('result')->nullable();     // appareils atteints, expires, en echec (JSON)
            $table->timestamp('created_at')->nullable();

            $table->index(['sent_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_outbox');
    }
};
