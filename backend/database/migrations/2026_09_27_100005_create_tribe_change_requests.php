<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changement de tribu sur demande : le fidele demande, les responsables de l'ancienne
     * et de la nouvelle tribu (ou une autorite pastorale) approuvent, puis le changement
     * est applique. Chaque decision est conservee.
     */
    public function up(): void
    {
        Schema::create('tribe_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_tribe_id')->nullable()->constrained('tribes')->nullOnDelete();
            $table->foreignId('to_tribe_id')->constrained('tribes')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('tribe_change_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('tribe_change_requests')->cascadeOnDelete();
            $table->string('side', 6);        // from | to | both (autorite pastorale)
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 10);   // approved | rejected
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');

            $table->index(['request_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tribe_change_approvals');
        Schema::dropIfExists('tribe_change_requests');
    }
};
