<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'audit : trace des actions sensibles (qui, quoi, sur quel objet, avant/apres).
     * Aucune route ne permet de le modifier ni de le supprimer.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // null = systeme
            $table->string('action', 80);                  // ex. fiss.created, tribe_change.approved
            $table->string('subject_type', 80)->nullable(); // ex. spiritual_health_form
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('member_user_id')->nullable()->constrained('users')->nullOnDelete(); // fidele concerne
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['member_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
