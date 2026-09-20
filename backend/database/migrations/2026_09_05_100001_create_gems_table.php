<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GEM : petit groupe (3 a 5 membres) a l'interieur d'une tribu, dirige par un GAD.
     * Hierarchie : Tribu -> GEMs -> Membres.
     */
    public function up(): void
    {
        Schema::create('gems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('tribe_id')->constrained('tribes')->cascadeOnDelete();
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete(); // le GAD
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->foreignId('gem_id')->nullable()->after('tribe_id')->constrained('gems')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gem_id');
        });
        Schema::dropIfExists('gems');
    }
};
