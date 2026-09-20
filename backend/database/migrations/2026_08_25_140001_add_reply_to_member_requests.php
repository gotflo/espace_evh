<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reponse d'un responsable a une demande de fidele. */
    public function up(): void
    {
        Schema::table('member_requests', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('status');
            $table->foreignId('replied_by')->nullable()->after('reply')->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable()->after('replied_by');
        });
    }

    public function down(): void
    {
        Schema::table('member_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replied_by');
            $table->dropColumn(['reply', 'replied_at']);
        });
    }
};
