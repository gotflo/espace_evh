<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'score', 'max_score', 'evaluated_on', 'comment', 'created_by',
    ];

    protected $casts = [
        'score' => 'float',
        'max_score' => 'float',
        'evaluated_on' => 'date:Y-m-d',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
