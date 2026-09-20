<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
        });
        // Corps facultatif : on autorise une annonce composee uniquement d'une image.
        Schema::table('announcements', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
