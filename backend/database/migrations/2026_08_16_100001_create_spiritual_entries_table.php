<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Journal du parcours spirituel d'un fidele (une ligne = un evenement suivi). */
    public function up(): void
    {
        Schema::create('spiritual_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');          // conversion, priere, jeune, visite... (voir SpiritualCatalog)
            $table->date('entry_date');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['member_user_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spiritual_entries');
    }
};
