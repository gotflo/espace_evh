<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** « J'aime » du pasteur sur la reponse d'un membre (un par personne et par reponse). */
class AssignmentResponseLike extends Model
{
    protected $fillable = ['response_id', 'user_id', 'member_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
