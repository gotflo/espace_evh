<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Suivi de lecture d'une ressource video (RM) par un membre. */
class ResourceView extends Model
{
    protected $fillable = [
        'resolution_resource_id', 'user_id', 'segments', 'watched_seconds', 'last_position', 'max_position',
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
}
