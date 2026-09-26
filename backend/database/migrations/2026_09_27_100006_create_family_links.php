<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Famille :
     * - family_links : liens entre personnes (conjoint, enfant). Un lien vers un membre inscrit
     *   reste « pending » tant que l'autre personne ne l'a pas confirme ; le lien conjoint confirme
     *   existe dans les deux sens (A -> B et B -> A). Un enfant non inscrit est un lien sans
     *   relative_user_id (nom + annee de naissance).
     * - profiles : nom du conjoint non inscrit (en attendant son inscription), date de mariage,
     *   « a des enfants », et taux de completion du profil (pour filtrer rapidement).
     */
    public function up(): void
    {
        Schema::create('family_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relation', 10);   // spouse | child
            $table->foreignId('relative_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relative_name', 150)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable(); // enfants
            $table->string('status', 10)->default('confirmed');     // pending | confirmed | declined
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'relation']);
            $table->index(['relative_user_id', 'relation', 'status']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->string('spouse_name', 150)->nullable()->after('marital_status');
            $table->unsignedTinyInteger('wedding_day')->nullable()->after('spouse_name');
            $table->unsignedTinyInteger('wedding_month')->nullable()->after('wedding_day');
            $table->boolean('has_children')->nullable()->after('wedding_month');
            $table->unsignedTinyInteger('completion')->default(0)->after('is_completed');
            $table->index('completion');
        });

        // « A des enfants » deduit du nombre declare auparavant.
        DB::table('profiles')->where('children_count', '>', 0)->update(['has_children' => true]);
        DB::table('profiles')->where('children_count', 0)->update(['has_children' => false]);
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['completion']);
            $table->dropColumn(['spouse_name', 'wedding_day', 'wedding_month', 'has_children', 'completion']);
        });
        Schema::dropIfExists('family_links');
    }
};
