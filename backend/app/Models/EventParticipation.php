<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventParticipation extends Model
{
    protected $fillable = ['event_id', 'user_id', 'occurs_on', 'response', 'volunteer'];

    // occurs_on reste une chaine 'Y-m-d' (cle de l'occurrence, comparee telle quelle).
    protected $casts = ['volunteer' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
