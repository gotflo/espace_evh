<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gem extends Model
{
    protected $fillable = ['name', 'tribe_id', 'leader_user_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function tribe(): BelongsTo
    {
        return $this->belongsTo(Tribe::class);
    }

    /** Le GAD (responsable du GEM). */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /** Membres du GEM (profils). */
    public function members(): HasMany
    {
        return $this->hasMany(Profile::class);
    }
}
