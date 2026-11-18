<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reaction d'un membre a un message (une seule par membre et par message). */
class ChatReaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'user_id', 'emoji'];
}
