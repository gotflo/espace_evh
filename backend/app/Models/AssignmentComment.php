<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commentaire du pasteur sur la reponse d'un membre a son devoir (lu par le membre et le pasteur seulement). */
class AssignmentComment extends Model
{
    protected $fillable = ['response_id', 'member_id', 'author_id', 'body', 'body_doc', 'edited_at'];

    protected $casts = ['body_doc' => 'array', 'edited_at' => 'datetime'];

    public function response(): BelongsTo
    {
        return $this->belongsTo(AssignmentResponse::class, 'response_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
