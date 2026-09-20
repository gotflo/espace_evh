<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tribe extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'patriarch_user_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function patriarch(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patriarch_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Profile::class);
    }
}
