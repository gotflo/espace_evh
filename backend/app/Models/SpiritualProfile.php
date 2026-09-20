<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpiritualProfile extends Model
{
    protected $fillable = [
        'user_id', 'conversion_year', 'conversion_verse', 'baptism_immersion_date',
        'baptism_holy_spirit', 'speaks_tongues', 'tongues_since_year', 'active_member',
        'prayer_frequency', 'gifts_known', 'gifts_detail', 'last_prayer_subject',
        'joyful_service', 'focus_effort',
    ];

    protected $casts = [
        'baptism_immersion_date' => 'date:Y-m-d',
        'speaks_tongues' => 'boolean',
        'active_member' => 'boolean',
        'gifts_known' => 'boolean',
        'conversion_year' => 'integer',
        'tongues_since_year' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
