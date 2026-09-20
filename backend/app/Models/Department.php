<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'leader_user_id', 'is_active', 'tracks_rehearsal'];

    protected $casts = ['is_active' => 'boolean', 'tracks_rehearsal' => 'boolean'];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class);
    }
}
