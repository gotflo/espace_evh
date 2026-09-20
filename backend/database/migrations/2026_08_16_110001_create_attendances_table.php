<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Presences des fideles aux cultes / activites. */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('attended_on');
            $table->string('event')->default('Culte');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_user_id', 'attended_on', 'event']);
            $table->index('attended_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
