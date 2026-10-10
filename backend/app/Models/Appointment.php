<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande ou rendez-vous avec un pasteur. En attente (pending) : dans la file du protocole, triee par
 * priorite ; confirme (confirmed) : un creneau precis, unique (slot_key) ; puis fait, absent ou annule.
 */
class Appointment extends Model
{
    public const ACTIVE = ['pending', 'confirmed'];

    public const URGENCY_WEIGHT = ['urgent' => 3, 'important' => 2, 'normal' => 1];

    protected $fillable = [
        'host_id', 'user_id', 'status', 'starts_at', 'ends_at', 'slot_key', 'preference', 'preferred_date', 'urgency',
        'message', 'staff_note', 'cancel_reason', 'cancelled_by', 'handled_by', 'confirmed_at', 'reminded_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'preferred_date' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
