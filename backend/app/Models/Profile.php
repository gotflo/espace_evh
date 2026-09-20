<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'matricule', 'first_name', 'last_name', 'birth_date', 'birth_day', 'birth_month', 'gender',
        'email', 'facebook', 'marital_status', 'civility', 'children_count', 'tshirt_size', 'year_verse',
        'photo_path', 'tribe_id', 'gem_id', 'joined_at', 'notes', 'is_completed',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'birth_day' => 'integer',
        'birth_month' => 'integer',
        'joined_at' => 'date:Y-m-d',
        'children_count' => 'integer',
        'is_completed' => 'boolean',
    ];

    protected $appends = ['full_name', 'photo_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tribe(): BelongsTo
    {
        return $this->belongsTo(Tribe::class);
    }

    public function gem(): BelongsTo
    {
        return $this->belongsTo(Gem::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getPhotoUrlAttribute(): ?string
    {
        // URL relative volontairement : meme origine que l'app (proxy en dev,
        // meme domaine en prod), plus robuste qu'une URL absolue avec APP_URL.
        return $this->photo_path ? '/storage/'.ltrim($this->photo_path, '/') : null;
    }
}
