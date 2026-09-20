<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    protected $fillable = [
        'title', 'content', 'type', 'target_type', 'target_id', 'due_date', 'created_by', 'is_active',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(ExerciseResponse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
