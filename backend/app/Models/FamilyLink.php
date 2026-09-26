<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien familial : conjoint (bidirectionnel une fois confirme) ou enfant.
 * relative_user_id = membre inscrit ; sinon relative_name (+ birth_year pour un enfant).
 */
class FamilyLink extends Model
{
    protected $fillable = ['user_id', 'relation', 'relative_user_id', 'relative_name', 'birth_year', 'status', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime', 'birth_year' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relative_user_id');
    }
}
