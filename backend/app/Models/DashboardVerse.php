<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Verset (ou texte biblique) affiche en tete du tableau de bord. */
class DashboardVerse extends Model
{
    protected $fillable = [
        'label', 'text', 'reference', 'message', 'status', 'starts_at', 'ends_at',
        'position', 'is_pinned', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_pinned' => 'boolean',
        'position' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Publies et dans leur periode d'affichage a l'instant donne. */
    public function scopeLiveAt(Builder $q, Carbon $at): Builder
    {
        return $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    /** brouillon | programme | actif | expire (etat lisible pour l'administration). */
    public function state(?Carbon $at = null): string
    {
        $at ??= now();
        if ($this->status !== 'published') {
            return 'draft';
        }
        if ($this->ends_at && $this->ends_at->lt($at)) {
            return 'expired';
        }
        if ($this->starts_at && $this->starts_at->gt($at)) {
            return 'scheduled';
        }

        return 'live';
    }
}
