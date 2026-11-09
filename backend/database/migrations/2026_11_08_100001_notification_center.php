<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre de notifications (administration) :
 * - rappels d'un evenement coupes pendant une periode (muted_from / muted_until), en plus de la coupure sans fin ;
 * - notifications personnalisees programmees (envoyees a l'heure dite par les automatismes) ;
 * - suspensions datees des rappels automatiques (automation_pauses).
 * Le centre est reserve au compte de gestion (middleware platform.manager) : aucune permission de role.
 * Additive : l'ancien code continue de fonctionner pendant la mise en ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->date('muted_from')->nullable()->after('muted');
            $table->date('muted_until')->nullable()->after('muted_from');
        });

        Schema::create('scheduled_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('url', 255)->nullable();
            $table->json('scopes');
            $table->string('urgency', 10)->default('normal');
            $table->timestamp('send_at');
            $table->string('status', 12)->default('scheduled'); // scheduled, sent, cancelled
            $table->unsignedInteger('recipients_count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
        });

        // Suspensions datees des rappels automatiques (une etape de app:tick, du jour X au jour Y inclus).
        Schema::create('automation_pauses', function (Blueprint $table) {
            $table->id();
            $table->string('step', 40)->unique();
            $table->date('paused_from');
            $table->date('paused_until');
            $table->string('reason', 160)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_pauses');
        Schema::dropIfExists('scheduled_notifications');
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['muted_from', 'muted_until']);
        });
    }
};
