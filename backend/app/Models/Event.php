<?php

namespace App\Models;

use App\Models\Concerns\HasPublicationScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasPublicationScopes;

    public const SCOPABLE_TYPE = 'event';

    public const RECURRENCES = [
        'none' => 'Une seule fois',
        'daily' => 'Chaque jour',
        'weekly' => 'Chaque semaine',
        'biweekly' => 'Toutes les 2 semaines',
        'monthly' => 'Chaque mois',
    ];

    protected $fillable = [
        'title', 'description', 'image_path', 'category', 'starts_at', 'ends_at', 'all_day',
        'recurrence', 'recurrence_until', 'location', 'is_personal', 'remind_all', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'recurrence_until' => 'date:Y-m-d',
        'is_personal' => 'boolean',
        'remind_all' => 'boolean',
    ];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? '/storage/'.ltrim($this->image_path, '/') : null;
    }

    public function isRecurring(): bool
    {
        return ($this->recurrence ?? 'none') !== 'none';
    }

    /**
     * Debuts des occurrences qui chevauchent l'intervalle [from, to].
     * Un evenement simple a une seule occurrence ; un recurrent est deroule jusqu'a
     * recurrence_until (ou la fin de l'intervalle).
     *
     * @return array<int, Carbon>
     */
    public function occurrencesBetween(Carbon $from, Carbon $to): array
    {
        $start = $this->starts_at->copy();
        $duration = $this->ends_at ? max(0, $this->starts_at->diffInSeconds($this->ends_at)) : 0;

        if (! $this->isRecurring()) {
            $end = $start->copy()->addSeconds($duration);

            return $end->gte($from) && $start->lte($to) ? [$start] : [];
        }

        $until = $this->recurrence_until ? $this->recurrence_until->copy()->endOfDay() : null;
        $limit = $until && $until->lt($to) ? $until : $to;
        $windowStart = $from->copy()->subSeconds($duration);

        // On saute directement pres de l'intervalle au lieu d'iterer depuis la 1re occurrence.
        $step = match ($this->recurrence) { 'daily' => 1, 'biweekly' => 14, default => 7 };
        $index = 0;
        if ($start->lt($windowStart)) {
            $index = $this->recurrence === 'monthly'
                ? max(0, (int) floor($start->diffInMonths($windowStart)) - 1)
                : intdiv((int) floor($start->copy()->startOfDay()->diffInDays($windowStart->copy()->startOfDay())), $step);
        }

        $out = [];
        for ($guard = 0; $guard < 400; $guard++, $index++) {
            $occurrence = $this->recurrence === 'monthly'
                ? $this->monthlyOccurrence($start, $index)
                : $start->copy()->addDays($index * $step);
            if ($occurrence->gt($limit)) {
                break;
            }
            if ($occurrence->copy()->addSeconds($duration)->gte($from)) {
                $out[] = $occurrence;
            }
        }

        return $out;
    }

    /** Meme jour du mois (ramene au dernier jour si le mois est plus court). */
    private function monthlyOccurrence(Carbon $start, int $months): Carbon
    {
        $month = $start->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $month->day(min($start->day, $month->daysInMonth))
            ->setTime($start->hour, $start->minute, $start->second);
    }

    /** Libelle des destinataires (« Toute l'église », « Tribus Juda, Lévi »...). */
    public function targetLabel(): string
    {
        return $this->is_personal ? 'Mon agenda (privé)' : $this->audienceLabel();
    }

    /** Evenements visibles par un membre : ceux de sa portee + son agenda personnel. */
    public function scopeForMember(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $pub) => $pub->where('is_personal', false)->visibleTo($user))
            ->orWhere(fn (Builder $mine) => $mine->where('is_personal', true)->where('created_by', $user->id)));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipation::class);
    }
}
