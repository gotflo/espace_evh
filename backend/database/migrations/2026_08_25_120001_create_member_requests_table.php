<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Demandes envoyees par un fidele a un responsable (rdv, aide, priere, question...). */
    public function up(): void
    {
        Schema::create('member_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category')->default('question'); // rendez-vous | aide | priere | question | autre
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status')->default('nouvelle'); // nouvelle | en_cours | traitee
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_requests');
    }
};
