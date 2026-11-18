<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat des GEMs : une discussion de groupe par GEM et des discussions privees a deux entre membres d'un
 * meme GEM. Seuls les participants lisent les messages (texte chiffre dans la base, photos hors du dossier
 * public). Additif et reversible : aucune table existante n'est modifiee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // gem | direct
            // Discussion de groupe : son GEM (supprime avec lui).
            $table->foreignId('gem_id')->nullable()->constrained('gems')->cascadeOnDelete();
            // Discussion privee : les deux membres, le plus petit identifiant d'abord (une seule par paire).
            $table->foreignId('user_low_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_high_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Compteur de changements (message, modification, suppression, reaction) : la mise a jour en direct
            // ne relit que ce qui a change depuis le dernier passage.
            $table->unsignedBigInteger('rev')->default(0);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique('gem_id');
            $table->unique(['user_low_id', 'user_high_id']);
            $table->index('user_high_id');
        });

        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Groupe : un membre ne lit que les messages envoyes depuis son arrivee dans le GEM.
            $table->unsignedBigInteger('joined_message_id')->default(0);
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10)->default('text'); // text | photo
            $table->text('body')->nullable(); // chiffre
            $table->string('photo_path')->nullable(); // disque prive
            $table->unsignedSmallInteger('photo_width')->nullable();
            $table->unsignedSmallInteger('photo_height')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            // Identifiant choisi par l'appareil : un envoi repete (reseau coupe) ne cree pas de doublon.
            $table->string('client_id', 40);
            $table->unsignedBigInteger('rev')->default(0);
            $table->boolean('forwarded')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['sender_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'rev']);
        });

        Schema::create('chat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reactions');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
    }
};
