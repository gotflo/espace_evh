<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de modification d'une FISS verrouillee (traitee par le patriarche). */
class FissEditRequest extends Model
{
    public const MAX_PER_FORM = 2;

    /** Duree pendant laquelle la fiche reste deverrouillee apres approbation. */
    public const UNLOCK_DAYS = 7;

    protected $fillable = [
        'form_id', 'user_id', 'reason', 'status', 'decided_by', 'decided_at',
        'decision_comment', 'unlock_expires_at', 'used_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'unlock_expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(SpiritualHealthForm::class, 'form_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Approuvee et encore utilisable (fenetre ouverte, pas encore utilisee). */
    public function isOpen(): bool
    {
        return $this->status === 'approved' && ! $this->used_at
            && (! $this->unlock_expires_at || $this->unlock_expires_at->isFuture());
    }
}
