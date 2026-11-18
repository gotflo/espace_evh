<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Message du chat. Le texte est chiffre dans la base (cle de l'application) : une copie de la base ne le
 * montre pas. La photo est sur le disque prive, servie seulement aux participants.
 */
class ChatMessage extends Model
{
    public const TEXT = 'text';

    public const PHOTO = 'photo';

    protected $fillable = ['conversation_id', 'sender_id', 'kind', 'body', 'photo_path', 'photo_width', 'photo_height', 'reply_to_id', 'client_id', 'rev', 'forwarded'];

    protected $casts = ['body' => 'encrypted', 'edited_at' => 'datetime', 'deleted_at' => 'datetime', 'rev' => 'integer', 'forwarded' => 'boolean'];

    protected $hidden = ['body', 'photo_path'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
