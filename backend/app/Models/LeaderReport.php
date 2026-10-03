<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rapport mensuel d'une tribu (patriarche) ou d'un departement (responsables). */
class LeaderReport extends Model
{
    public const KINDS = ['tribe', 'department'];

    protected $fillable = ['kind', 'scope_id', 'scope_name', 'period', 'author_user_id', 'status', 'answers', 'souls', 'submitted_at'];

    protected $casts = [
        'scope_id' => 'integer',
        'answers' => 'array',
        'souls' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
