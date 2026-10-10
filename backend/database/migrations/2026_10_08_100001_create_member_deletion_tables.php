<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression d'un membre :
 * - member_deletion_requests : demande d'un patriarche ou d'un responsable de departement, approuvee
 *   ou refusee par le Pasteur Resident (le nom est garde : la demande survit a la suppression) ;
 * - blocked_phones : numero d'un compte supprime, refuse pour toute nouvelle inscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // membre vise
            $table->string('member_name', 200);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('blocked_phones', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32)->unique(); // format international (+1418...)
            $table->string('member_name', 200)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_phones');
        Schema::dropIfExists('member_deletion_requests');
    }
};
