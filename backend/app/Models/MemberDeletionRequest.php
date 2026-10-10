<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de suppression d'un membre (patriarche, responsable de departement), decidee par le Pasteur Resident. */
class MemberDeletionRequest extends Model
{
    protected $fillable = ['user_id', 'member_name', 'requested_by', 'reason', 'status', 'decided_by', 'decided_at', 'decision_comment'];

    protected $casts = ['decided_at' => 'datetime'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
