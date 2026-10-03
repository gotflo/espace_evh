<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport hebdomadaire des Gardes : pour chaque GEM et chaque semaine (du lundi au dimanche),
 * la presence des membres au culte et a la rencontre du GEM, avec un mot facultatif.
 * Il est transmis au patriarche et a l'Assistant Pasteur de la tribu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gem_id')->constrained('gems')->cascadeOnDelete();
            $table->date('week_start');                      // lundi de la semaine concernee
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('meeting_held')->default(false); // la rencontre du GEM a eu lieu
            $table->json('attendance');                      // [{user_id, name, culte, rencontre}], noms figes a l'envoi
            $table->unsignedSmallInteger('members_count')->default(0);
            $table->unsignedSmallInteger('culte_count')->default(0);
            $table->unsignedSmallInteger('meeting_count')->default(0);
            $table->text('comment')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->unique(['gem_id', 'week_start']);
            $table->index('week_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_weekly_reports');
    }
};
