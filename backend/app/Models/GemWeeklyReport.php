<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport hebdomadaire d'un Garde pour son GEM (presences au culte et a la rencontre).
 * week_start (lundi) reste une chaine AAAA-MM-JJ : comparaison directe, quelle que soit la base.
 */
class GemWeeklyReport extends Model
{
    protected $fillable = [
        'gem_id', 'week_start', 'author_user_id', 'meeting_held', 'attendance',
        'members_count', 'culte_count', 'meeting_count', 'comment', 'submitted_at',
    ];

    protected $casts = [
        'meeting_held' => 'boolean',
        'attendance' => 'array',
        'members_count' => 'integer',
        'culte_count' => 'integer',
        'meeting_count' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function gem(): BelongsTo
    {
        return $this->belongsTo(Gem::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
