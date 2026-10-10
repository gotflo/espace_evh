<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vertumetre : questionnaire mensuel du membre, verrouille a l'envoi (meme logique que la FISS). */
class VertumetreForm extends Model
{
    protected $fillable = ['user_id', 'period', 'answers', 'submitted_at', 'locked_at', 'edit_count'];

    protected $casts = [
        'answers' => 'array',
        'submitted_at' => 'datetime',
        'locked_at' => 'datetime',
        'edit_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function editRequests(): HasMany
    {
        return $this->hasMany(VertumetreEditRequest::class, 'form_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
