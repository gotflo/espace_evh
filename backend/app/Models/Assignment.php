<?php

namespace App\Models;

use App\Support\PlainText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Mon devoir (DS) : video ou audio publie par le PR, avec resume et resolutions de chaque membre. */
class Assignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'instructions', 'speaker', 'media_type', 'media_url', 'youtube_id', 'audio_path', 'media_duration',
        'published_at', 'created_by', 'updated_by',
    ];

    protected $casts = ['published_at' => 'datetime', 'media_duration' => 'integer'];

    public function responses(): HasMany
    {
        return $this->hasMany(AssignmentResponse::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(AssignmentView::class);
    }

    /** Lecture mesurable dans l'application (video YouTube ou fichier audio depose) ; un lien externe ne l'est pas. */
    public function isTrackable(): bool
    {
        return (bool) ($this->youtube_id || $this->audio_path);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Devoirs visibles des membres (publies, dans l'ordre du plus recent). */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function getAudioUrlAttribute(): ?string
    {
        return $this->audio_path ? '/storage/'.ltrim($this->audio_path, '/') : null;
    }

    /** Lecture : identifiant YouTube, fichier audio depose ou lien externe. @return array<string, mixed> */
    public function mediaPayload(): array
    {
        return [
            'type' => $this->media_type,
            'youtube_id' => $this->youtube_id,
            'audio_url' => $this->audio_url,
            'link' => $this->youtube_id || $this->audio_path ? null : $this->media_url,
        ];
    }

    /** Titre en texte simple (lettres stylisees et emojis retires), a l'enregistrement comme a la lecture. */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => PlainText::title($v),
            set: fn (?string $v) => PlainText::title($v),
        );
    }
}
