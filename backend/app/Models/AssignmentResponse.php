<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentResponse extends Model
{
    protected $fillable = ['assignment_id', 'user_id', 'summary', 'actions', 'submitted_at'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
