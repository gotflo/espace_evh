<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['phone', 'phone_verified_at', 'activity_override', 'last_login_at'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Un fidele est inactif s'il n'a pas ete vu depuis ce nombre de mois. */
    public const INACTIVE_AFTER_MONTHS = 3;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public const SUPER_ADMIN = 'super_admin';

    /**
     * Statut d'activite effectif : le remplacement manuel l'emporte,
     * sinon on calcule d'apres la derniere fois vu (presence ou connexion).
     */
    public static function activityFrom(?string $override, ?Carbon $lastSeen): string
    {
        if ($override === 'active' || $override === 'inactive') {
            return $override;
        }

        return $lastSeen && $lastSeen->gte(now()->subMonths(self::INACTIVE_AFTER_MONTHS))
            ? 'active' : 'inactive';
    }

    /** Derniere fois vu = max(derniere presence, derniere connexion). */
    public function lastSeenAt(): ?Carbon
    {
        $lastAttendance = $this->attendances()->max('attended_on');
        $dates = array_filter([
            $lastAttendance ? Carbon::parse($lastAttendance) : null,
            $this->last_login_at,
        ]);

        return empty($dates) ? null : collect($dates)->max();
    }

    public function activityStatus(): string
    {
        return self::activityFrom($this->activity_override, $this->lastSeenAt());
    }

    /** Profil du fidele (informations personnelles). */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** Profil spirituel du fidele (conversion, baptemes, dons, priere...). */
    public function spiritualProfile(): HasOne
    {
        return $this->hasOne(SpiritualProfile::class);
    }

    /** Presences enregistrees pour ce fidele. */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'member_user_id');
    }

    /** Annonces recues (avec etat lu/non-lu). */
    public function receivedAnnouncements(): BelongsToMany
    {
        return $this->belongsToMany(Announcement::class, 'announcement_user')->withPivot('read_at');
    }

    /** Roles attribues au fidele (avec portee eventuelle : tribu / departement). */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('id', 'scope_kind', 'scope_id')->withTimestamps();
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains('key', $key);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }

    /** Toutes les cles de permissions issues des roles du fidele. */
    public function permissionKeys(): Collection
    {
        return $this->roles->flatMap(fn (Role $r) => $r->permissions->pluck('key'))->unique()->values();
    }

    /** Le super admin a tous les droits ; sinon on verifie la permission. */
    public function hasPermission(string $key): bool
    {
        return $this->isSuperAdmin() || $this->permissionKeys()->contains($key);
    }

    /** Rang le plus eleve parmi les roles de l'utilisateur (0 s'il n'en a pas). */
    public function highestRank(): int
    {
        return (int) ($this->roles->max('rank') ?? 0);
    }

    /** Ids des tribus sur lesquelles l'utilisateur a une portee. */
    public function scopeTribeIds(): array
    {
        return $this->roles->where('pivot.scope_kind', 'tribe')
            ->pluck('pivot.scope_id')->filter()->unique()->values()->all();
    }

    /** Ids des departements sur lesquels l'utilisateur a une portee. */
    public function scopeDepartmentIds(): array
    {
        return $this->roles->where('pivot.scope_kind', 'department')
            ->pluck('pivot.scope_id')->filter()->unique()->values()->all();
    }

    /** Ids des GEMs sur lesquels l'utilisateur a une portee (GAD). */
    public function scopeGemIds(): array
    {
        return $this->roles->where('pivot.scope_kind', 'gem')
            ->pluck('pivot.scope_id')->filter()->unique()->values()->all();
    }

    /**
     * Peut-il consulter / gerer la fiche de ce membre ?
     * view_all => tout le monde ; view_scope => uniquement sa tribu / ses departements.
     */
    public function canViewMember(self $target): bool
    {
        if ($this->hasPermission('members.view_all')) {
            return true;
        }
        if (! $this->hasPermission('members.view_scope')) {
            return false;
        }
        if ($target->id === $this->id) {
            return true;
        }

        $profile = $target->profile()->with('departments:id')->first();
        if (! $profile) {
            return false;
        }
        if ($profile->gem_id && in_array($profile->gem_id, $this->scopeGemIds(), true)) {
            return true;
        }
        if ($profile->tribe_id && in_array($profile->tribe_id, $this->scopeTribeIds(), true)) {
            return true;
        }

        return $profile->departments->pluck('id')->intersect($this->scopeDepartmentIds())->isNotEmpty();
    }
}
