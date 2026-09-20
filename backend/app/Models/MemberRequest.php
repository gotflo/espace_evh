<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberRequest extends Model
{
    protected $fillable = [
        'user_id', 'category', 'subject', 'message', 'status',
        'reply', 'replied_by', 'replied_at', 'handled_by', 'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
