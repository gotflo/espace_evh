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
        'submitted_at', 'locked_at', 'edit_count',
    ];

    protected $casts = [
        'meditation' => 'integer', 'priere' => 'integer', 'jeune' => 'integer',
        'situation_financiere' => 'integer', 'situation_familiale' => 'integer', 'situation_conjugale' => 'integer',
        'submitted_at' => 'datetime', 'locked_at' => 'datetime', 'edit_count' => 'integer',
    ];

    /** Champs de la fiche (utilises pour l'audit avant/apres). */
    public const FIELDS = [
        'meditation', 'priere', 'jeune', 'sanctification_corps', 'sanctification_ame', 'sanctification_esprit',
        'situation_financiere', 'situation_familiale', 'situation_conjugale', 'comment',
    ];

    public function editRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FissEditRequest::class, 'form_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Score de vie spirituelle (0-100) : moyenne des composantes RENSEIGNEES
     * (meditation, priere, jeune sur 20 ; sanctification corps/ame/esprit : mal 0, moyen 50, bien 100).
     * Une composante absente est ignoree (elle ne compte pas comme 0) : la moyenne n'est pas faussee.
     */
    public function spiritualScore(): ?float
    {
        $parts = [];
        foreach (['meditation', 'priere', 'jeune'] as $k) {
            if ($this->{$k} !== null) {
                $parts[] = min(20, (int) $this->{$k}) / 20 * 100;
            }
        }
        foreach (['sanctification_corps', 'sanctification_ame', 'sanctification_esprit'] as $k) {
            $v = ['mal' => 0, 'moyen' => 50, 'bien' => 100][$this->{$k} ?? ''] ?? null;
            if ($v !== null) {
                $parts[] = $v;
            }
        }

        return $parts ? round(array_sum($parts) / count($parts), 1) : null;
    }

    /** Score de vie sociale (0-100) : situations financiere / familiale / conjugale renseignees. */
    public function socialScore(): ?float
    {
        $parts = array_values(array_filter([
            $this->situation_financiere, $this->situation_familiale, $this->situation_conjugale,
        ], fn ($v) => $v !== null));

        return $parts ? round(array_sum(array_map(fn ($v) => min(20, $v) / 20 * 100, $parts)) / count($parts), 1) : null;
    }

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
