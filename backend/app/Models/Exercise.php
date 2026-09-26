<?php

namespace App\Models;

use App\Models\Concerns\HasPublicationScopes;
use App\Support\YouTube;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasPublicationScopes;

    public const SCOPABLE_TYPE = 'exercise';

    protected $fillable = [
        'title', 'content', 'type', 'video_id', 'video_duration', 'requires_response',
        'due_date', 'closes_at', 'created_by', 'is_active',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'closes_at' => 'datetime',
        'is_active' => 'boolean',
        'requires_response' => 'boolean',
        'video_duration' => 'integer',
    ];

    protected static function booted(): void
    {
        // Une echeance donnee seulement en date ferme l'exercice a la fin de ce jour-la.
        static::saving(function (Exercise $e) {
            if ($e->closes_at === null && $e->due_date !== null) {
                $e->closes_at = $e->due_date->copy()->setTime(23, 59);
            }
        });
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ExerciseResponse::class);
    }

    public function videoViews(): HasMany
    {
        return $this->hasMany(ExerciseVideoView::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVideo(): bool
    {
        return $this->video_id !== null && $this->video_id !== '';
    }

    /** Ferme : la date limite est passee, plus aucune reponse ni visionnage n'est enregistre. */
    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    /** Une reponse ecrite est-elle attendue ? (toujours pour un exercice classique) */
    public function needsResponse(): bool
    {
        return ! $this->isVideo() || $this->requires_response;
    }

    /** @return array<string, mixed>|null */
    public function videoPayload(): ?array
    {
        return $this->isVideo() ? [
            'id' => $this->video_id,
            'duration' => $this->video_duration,
            'thumbnail' => YouTube::thumbnail($this->video_id),
            'url' => YouTube::watchUrl($this->video_id),
        ] : null;
    }
}
