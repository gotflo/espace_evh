<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courriels et textos :
 * - outbound_messages : file d'envoi (courriel ou texto), envoyee chaque minute en arriere-plan, avec
 *   reprise en cas d'echec et une cle anti-doublon (dedupe_key) ;
 * - appointment_digests : courriel unique « liste des rendez-vous du jour » envoye au pasteur (et sa
 *   seule mise a jour eventuelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 8); // email | sms
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient', 190); // adresse courriel ou numero (+1418...)
            $table->string('kind', 40); // appointments_digest, deletion_request, assignment, fiss_reminder, report_reminder
            $table->string('subject', 200)->nullable();
            $table->string('view', 80)->nullable(); // gabarit du courriel
            $table->json('payload')->nullable(); // donnees du gabarit, ou texte du texto
            $table->string('dedupe_key', 190)->nullable()->unique();
            $table->string('status', 10)->default('pending'); // pending | sending | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['kind', 'created_at']);
        });

        Schema::create('appointment_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('signature', 64)->nullable(); // liste envoyee (pour detecter un changement)
            $table->string('reason', 12)->nullable(); // full | deadline
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('updated_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['host_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_digests');
        Schema::dropIfExists('outbound_messages');
    }
};
