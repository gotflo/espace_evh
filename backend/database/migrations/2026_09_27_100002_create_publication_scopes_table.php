<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portee de publication normalisee : une annonce / un evenement / un exercice peut viser
     * plusieurs tribus, GEMs ou departements (ou toute l'eglise). Remplace le couple
     * target_type/target_id (une seule cible) ; les donnees existantes sont reprises.
     * Les evenements personnels (agenda prive) deviennent is_personal = true.
     */
    public function up(): void
    {
        Schema::create('publication_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('scopable_type', 40);   // announcement | event | exercise
            $table->unsignedBigInteger('scopable_id');
            $table->string('scope_type', 20);      // church | tribe | gem | department
            $table->unsignedBigInteger('scope_id')->nullable(); // null pour church

            $table->unique(['scopable_type', 'scopable_id', 'scope_type', 'scope_id'], 'publication_scopes_unique');
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_personal')->default(false)->after('location');
            $table->index(['is_personal', 'created_by']);
        });

        foreach (['announcements' => 'announcement', 'events' => 'event', 'exercises' => 'exercise'] as $table => $type) {
            foreach (DB::table($table)->get(['id', 'target_type', 'target_id']) as $row) {
                if ($type === 'event' && $row->target_type === 'self') {
                    DB::table('events')->where('id', $row->id)->update(['is_personal' => true]);

                    continue;
                }
                $scope = in_array($row->target_type, ['tribe', 'gem', 'department'], true) && $row->target_id
                    ? $row->target_type : 'church';
                DB::table('publication_scopes')->insert([
                    'scopable_type' => $type,
                    'scopable_id' => $row->id,
                    'scope_type' => $scope,
                    'scope_id' => $scope === 'church' ? null : $row->target_id,
                ]);
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['target_type', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['announcements' => 'announcement', 'events' => 'event', 'exercises' => 'exercise'] as $table => $type) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('target_type')->default('all');
                $t->unsignedBigInteger('target_id')->nullable();
            });
            // Reprise : la premiere portee de chaque publication (la plus large information possible).
            foreach (DB::table('publication_scopes')->where('scopable_type', $type)->orderBy('id')->get() as $s) {
                DB::table($table)->where('id', $s->scopable_id)->where('target_type', 'all')->whereNull('target_id')
                    ->update(['target_type' => $s->scope_type === 'church' ? 'all' : $s->scope_type, 'target_id' => $s->scope_id]);
            }
        }
        DB::table('events')->where('is_personal', true)->update(['target_type' => 'self', 'target_id' => DB::raw('created_by')]);
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['is_personal', 'created_by']);
            $table->dropColumn('is_personal');
        });
        Schema::dropIfExists('publication_scopes');
    }
};
