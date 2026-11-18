<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Discussion du chat des GEMs : le groupe d'un GEM (type gem) ou une discussion privee entre deux membres
 * d'un meme GEM (type direct). Qui peut lire et ecrire : voir App\Services\ChatService.
 */
class ChatConversation extends Model
{
    public const GEM = 'gem';

    public const DIRECT = 'direct';

    protected $fillable = ['type', 'gem_id', 'user_low_id', 'user_high_id'];

    protected $casts = ['last_message_at' => 'datetime', 'rev' => 'integer', 'last_message_id' => 'integer'];

    public function gem(): BelongsTo
    {
        return $this->belongsTo(Gem::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function isGroup(): bool
    {
        return $this->type === self::GEM;
    }

    /** L'autre membre d'une discussion privee. */
    public function otherUserId(int $userId): ?int
    {
        if ($this->isGroup()) {
            return null;
        }

        return (int) $this->user_low_id === $userId ? (int) $this->user_high_id : (int) $this->user_low_id;
    }
}
