<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiche de sante spirituelle (FISS) verrouillee apres enregistrement.
     * Une modification passe par une demande approuvee par le patriarche (2 demandes max par fiche) ;
     * l'approbation deverrouille la fiche pour une seule modification, qui la reverrouille.
     */
    public function up(): void
    {
        Schema::table('spiritual_health_forms', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('comment');
            $table->timestamp('locked_at')->nullable()->after('submitted_at');
            $table->unsignedTinyInteger('edit_count')->default(0)->after('locked_at'); // modifications appliquees
            $table->index(['period', 'user_id']);
        });

        // Fiches existantes : considerees comme deja soumises et verrouillees.
        DB::table('spiritual_health_forms')->update([
            'submitted_at' => DB::raw('created_at'),
            'locked_at' => DB::raw('created_at'),
        ]);

        Schema::create('fiss_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('spiritual_health_forms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // demandeur (le fidele)
            $table->text('reason');
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | used | expired | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('unlock_expires_at')->nullable(); // fenetre de modification apres approbation
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiss_edit_requests');
        Schema::table('spiritual_health_forms', function (Blueprint $table) {
            $table->dropIndex(['period', 'user_id']);
            $table->dropColumn(['submitted_at', 'locked_at', 'edit_count']);
        });
    }
};
