<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpiritualEntry extends Model
{
    protected $fillable = ['member_user_id', 'author_user_id', 'type', 'entry_date', 'note'];

    protected $casts = ['entry_date' => 'date:Y-m-d'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
