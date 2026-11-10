<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien familial : conjoint (bidirectionnel une fois confirme) ou enfant.
 * relative_user_id = membre inscrit ; sinon relative_name. Enfant : son age (donne le jour age_on).
 */
class FamilyLink extends Model
{
    protected $fillable = ['user_id', 'relation', 'relative_user_id', 'relative_name', 'birth_year', 'birth_date', 'age', 'age_on', 'status', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime', 'birth_year' => 'integer', 'birth_date' => 'date', 'age' => 'integer', 'age_on' => 'date'];

    /** Age actuel : l'age donne, plus les annees ecoulees depuis le jour ou il a ete donne. */
    public function currentAge(): ?int
    {
        if ($this->age === null) {
            return null;
        }
        $years = $this->age_on ? (int) $this->age_on->copy()->startOfDay()->diffInYears(now()->startOfDay()) : 0;

        return min(120, $this->age + max(0, $years));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relative_user_id');
    }
}
