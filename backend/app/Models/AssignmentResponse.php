<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentResponse extends Model
{
    protected $fillable = ['assignment_id', 'user_id', 'summary', 'summary_doc', 'actions', 'actions_doc', 'submitted_at'];

    protected $casts = ['submitted_at' => 'datetime', 'summary_doc' => 'array', 'actions_doc' => 'array'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(AssignmentResponseLike::class, 'response_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AssignmentComment::class, 'response_id');
    }
}
