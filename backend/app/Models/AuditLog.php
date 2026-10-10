<?php

namespace App\Models;

use App\Support\PlatformManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entree du journal d'audit (lecture seule : jamais modifiee ni supprimee par l'application). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id', 'member_user_id',
        'old_values', 'new_values', 'context', 'ip',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Journal ordinaire : sans les lignes du compte de gestion principal (auteur ou sujet), meme anterieures
     * a son activation. Ses operations sont dans le journal de securite prive.
     */
    public function scopeOrdinary(Builder $query): Builder
    {
        $ids = PlatformManager::userIds();
        if (! $ids) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $ids))
            ->where(fn ($q) => $q->whereNull('member_user_id')->orWhereNotIn('member_user_id', $ids));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }
}
