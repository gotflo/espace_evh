<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpiritualHealthForm extends Model
{
    protected $fillable = [
        'user_id', 'period', 'meditation', 'priere', 'jeune',
        'sanctification_corps', 'sanctification_ame', 'sanctification_esprit',
        'situation_financiere', 'situation_familiale', 'situation_conjugale', 'comment',
    ];

    protected $casts = [
        'meditation' => 'integer', 'priere' => 'integer', 'jeune' => 'integer',
        'situation_financiere' => 'integer', 'situation_familiale' => 'integer', 'situation_conjugale' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Total vie spirituelle (/60) : meditation + priere + jeune. */
    public function getVieSpirituelleTotalAttribute(): int
    {
        return (int) $this->meditation + (int) $this->priere + (int) $this->jeune;
    }

    /** Total vie sociale : financiere + familiale (+ conjugale si renseignee). */
    public function getVieSocialeTotalAttribute(): int
    {
        return (int) $this->situation_financiere + (int) $this->situation_familiale + (int) $this->situation_conjugale;
    }
}
