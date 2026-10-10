<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Courriel ou texto en file d'envoi (envoye en arriere-plan, reessaye en cas d'echec, jamais en double). */
class OutboundMessage extends Model
{
    protected $fillable = [
        'channel', 'user_id', 'recipient', 'kind', 'subject', 'view', 'payload', 'dedupe_key',
        'status', 'attempts', 'available_at', 'sent_at', 'last_error',
    ];

    protected $casts = ['payload' => 'array', 'available_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
}
