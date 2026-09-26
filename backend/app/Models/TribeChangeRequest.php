<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Demande de changement de tribu (approbation des deux tribus ou d'une autorite pastorale). */
class TribeChangeRequest extends Model
{
    protected $fillable = ['user_id', 'from_tribe_id', 'to_tribe_id', 'reason', 'status', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fromTribe(): BelongsTo
    {
        return $this->belongsTo(Tribe::class, 'from_tribe_id');
    }

    public function toTribe(): BelongsTo
    {
        return $this->belongsTo(Tribe::class, 'to_tribe_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TribeChangeApproval::class, 'request_id');
    }

    /** Cotes a valider : l'ancienne tribu (si le membre en a une) et la nouvelle. */
    public function requiredSides(): array
    {
        return $this->from_tribe_id ? ['from', 'to'] : ['to'];
    }

    /** Cotes deja approuves (« both » = autorite pastorale, vaut pour les deux). */
    public function approvedSides(): array
    {
        $sides = $this->approvals->where('decision', 'approved')->pluck('side')->all();

        return in_array('both', $sides, true) ? ['from', 'to'] : array_values(array_unique($sides));
    }
}
