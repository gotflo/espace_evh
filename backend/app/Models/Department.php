<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active', 'tracks_rehearsal'];

    protected $casts = ['is_active' => 'boolean', 'tracks_rehearsal' => 'boolean'];

    /** Responsables du departement (plusieurs possibles) : ils en ont la charge et les droits associes. */
    public function leaders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_leaders')->withTimestamps();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }
}
