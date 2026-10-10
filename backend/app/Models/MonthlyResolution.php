<?php

namespace App\Models;

use App\Support\ResolutionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mes RM : resolutions d'un membre pour un mois (AAAA-MM), et leur bilan fait le mois suivant. */
class MonthlyResolution extends Model
{
    protected $fillable = [
        'user_id', 'period', 'meditation', 'priere', 'jeune', 'sanctification', 'assiduite', 'autres',
        'renewed', 'outcome', 'outcome_at',
    ];

    protected $casts = [
        'renewed' => 'array',
        'outcome' => 'array',
        'outcome_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rubriques renseignees (cle => texte). @return array<string, string> */
    public function filled(): array
    {
        return array_filter($this->only(ResolutionCatalog::keys()), fn ($v) => filled($v));
    }
}
