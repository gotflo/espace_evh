<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Champs personnels enrichis (inspires de PDVIE, modernises). */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('matricule')->nullable()->unique()->after('user_id'); // identifiant membre auto
            $table->string('email')->nullable()->after('gender');
            $table->string('facebook')->nullable()->after('email');
            $table->string('marital_status')->nullable()->after('facebook'); // celibataire|marie|veuf|divorce|fiance|concubinage
            $table->string('civility')->nullable()->after('marital_status'); // dr|reverend|pasteur|m|mme|mlle
            $table->unsignedSmallInteger('children_count')->nullable()->after('civility');
            $table->string('tshirt_size', 6)->nullable()->after('children_count'); // S..XXXXL
            $table->string('year_verse')->nullable()->after('tshirt_size'); // verset de l'annee
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['matricule', 'email', 'facebook', 'marital_status', 'civility', 'children_count', 'tshirt_size', 'year_verse']);
        });
    }
};
