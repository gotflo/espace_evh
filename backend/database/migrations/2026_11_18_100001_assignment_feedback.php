<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devoir (DS) : texte enrichi (resume, actions et resolutions), « j'aime » et commentaires du pasteur.
 * - assignment_responses : document de l'editeur a cote du texte brut (les anciennes reponses restent du texte) ;
 * - assignment_response_likes / assignment_comments : vus du membre et de ceux qui ont le droit assignments.feedback
 *   (le Pasteur Resident ; d'autres roles peuvent le recevoir dans l'ecran Roles).
 * Additif et reversible : aucune colonne existante n'est modifiee.
 */
return new class extends Migration
{
    private const PERMISSION = 'assignments.feedback';

    public function up(): void
    {
        Schema::table('assignment_responses', function (Blueprint $table) {
            $table->json('summary_doc')->nullable()->after('summary');
            $table->json('actions_doc')->nullable()->after('actions');
        });

        Schema::create('assignment_response_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('assignment_responses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Auteur de la reponse : sa page se met a jour (pouls personnel) et tout part avec son compte.
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['response_id', 'user_id']);
            $table->index('member_id');
        });

        Schema::create('assignment_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('assignment_responses')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            // Un pasteur dont le compte est supprime : son commentaire reste pour le membre, sans nom.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('body_doc')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['response_id', 'id']);
            $table->index('member_id');
        });

        $def = PermissionCatalog::all()[self::PERMISSION];
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key' => self::PERMISSION, 'name' => $def[0], 'group' => $def['module_label'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Le Pasteur Resident l'a deja par son role (tous les droits) : la case apparait cochee dans l'ecran Roles.
        $permission = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
        if ($role = DB::table('roles')->where('key', 'super_admin')->value('id')) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        if ($permission = DB::table('permissions')->where('key', self::PERMISSION)->value('id')) {
            DB::table('permission_role')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
        Schema::dropIfExists('assignment_comments');
        Schema::dropIfExists('assignment_response_likes');
        Schema::table('assignment_responses', function (Blueprint $table) {
            $table->dropColumn(['summary_doc', 'actions_doc']);
        });
    }
};
