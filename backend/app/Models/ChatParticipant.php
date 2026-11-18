<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Place d'un membre dans une discussion : depuis quel message il lit, jusqu'ou il a lu. */
class ChatParticipant extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'joined_message_id', 'last_read_message_id'];

    protected $casts = ['joined_message_id' => 'integer', 'last_read_message_id' => 'integer'];
}
