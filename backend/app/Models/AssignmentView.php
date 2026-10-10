<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suivi de lecture d'un devoir (video ou audio) par un membre. */
class AssignmentView extends Model
{
    protected $fillable = [
        'assignment_id', 'user_id', 'segments', 'watched_seconds', 'last_position', 'max_position',
        'seek_count', 'skipped_seconds', 'max_rate', 'started_at', 'last_heartbeat_at', 'completed_at',
    ];

    protected $casts = [
        'segments' => 'array',
        'watched_seconds' => 'integer',
        'last_position' => 'integer',
        'max_position' => 'integer',
        'seek_count' => 'integer',
        'skipped_seconds' => 'integer',
        'max_rate' => 'float',
        'started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
