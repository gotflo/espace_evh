<?php

namespace App\Models;

use App\Models\Concerns\HasPublicationScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    use HasPublicationScopes;

    public const SCOPABLE_TYPE = 'announcement';

    protected $fillable = ['title', 'body', 'image_path', 'category', 'created_by'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? '/storage/'.ltrim($this->image_path, '/') : null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_user')->withPivot('read_at');
    }
}
