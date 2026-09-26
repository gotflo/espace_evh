<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TribeChangeApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['request_id', 'side', 'approver_id', 'decision', 'comment', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
