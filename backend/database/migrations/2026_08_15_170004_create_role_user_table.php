<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribution des roles aux fideles, avec portee optionnelle.
     * scope_kind + scope_id : par ex. patriarche de la tribu #3, responsable du departement #2.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('scope_kind')->nullable();     // null | tribe | department
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'scope_kind', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
