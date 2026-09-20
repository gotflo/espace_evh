<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles de la plateforme. Configurables (le pasteur peut en creer d'autres).
     * scope_kind indique si le role s'exerce sur une portee : none / tribe / department.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope_kind')->default('none'); // none | tribe | department
            $table->unsignedInteger('rank')->default(0);    // hierarchie (tri, priorite d'affichage)
            $table->boolean('is_system')->default(false);   // role de base non supprimable
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
