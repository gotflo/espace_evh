<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notification personnalisee : envoyee tout de suite, ou programmee (partie a l'heure dite par les automatismes). */
class ScheduledNotification extends Model
{
    public const SCHEDULED = 'scheduled';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['title', 'body', 'url', 'scopes', 'urgency', 'send_at', 'status', 'recipients_count', 'created_by', 'sent_at'];

    protected $casts = [
        'scopes' => 'array',
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
