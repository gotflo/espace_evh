<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Centre de notifications : une ligne par destinataire (nouvelle annonce, evenement,
     * tache a faire, reponse a une demande, rappel...). Sert aussi de source aux push.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'read_at']);
            $table->index('created_at');
        });

        // Journal anti-doublon des envois automatiques (rappels, anniversaires...).
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('user_notifications');
    }
};
