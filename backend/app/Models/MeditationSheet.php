<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiche « Meditation de la Parole de Dieu » d'un membre : cinq rubriques, datee par le serveur a l'envoi.
 * Personnelle : lue seulement par le membre, le patriarche de sa tribu et les pasteurs (meditations.view).
 */
class MeditationSheet extends Model
{
    /** Rubriques de la fiche, dans l'ordre du formulaire. */
    public const FIELDS = ['verse', 'observation', 'rhema', 'action', 'prayer'];

    protected $fillable = ['user_id', 'verse', 'observation', 'rhema', 'action', 'prayer', 'submitted_at'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ligne de l'historique (sans le contenu). @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'submitted_at' => $this->submitted_at?->toIso8601String()];
    }

    /** Fiche complete. @return array<string, mixed> */
    public function present(): array
    {
        return $this->summary() + $this->only(self::FIELDS);
    }
}
