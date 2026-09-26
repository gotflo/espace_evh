<?php

namespace App\Models;

use App\Models\Concerns\HasPublicationScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasPublicationScopes;

    public const SCOPABLE_TYPE = 'exercise';

    protected $fillable = [
        'title', 'content', 'type', 'due_date', 'created_by', 'is_active',
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
