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
#[Hidden(['remember_token', 'calendar_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Un membre devient inactif apres ce nombre de mois sans activite (connexion, FISS, presence). */
    public const INACTIVE_AFTER_MONTHS = 3;

    public const SUPER_ADMIN = 'super_admin';

    /**
     * Droits donnes par la responsabilite d'un departement (anciennement le role « Responsable ») :
     * voir les membres du departement, faire l'appel des repetitions, publier ses evenements.
     */
    public const DEPARTMENT_LEADER_PERMISSIONS = [
        'members.view_scope', 'spiritual.view', 'attendance.view', 'attendance.record',
        'events.manage', 'exercises.respond',
    ];

    /** @var array<int>|null memoisation des departements diriges */
    private ?array $ledDepartmentIdsCache = null;

    private ?Collection $permissionCache = null;

    private ?Collection $permissionCacheFor = null;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'activity_changed_at' => 'datetime',
        ];
    }

    /**
     * Regle d'activite (utilisee par le calcul quotidien) : le remplacement manuel l'emporte,
     * sinon actif si une activite (connexion, utilisation, FISS, presence) date de moins de 3 mois.
     */
    public static function activityFrom(?string $override, ?Carbon $lastActivity): string
    {
        if ($override === 'active' || $override === 'inactive') {
            return $override;
        }

        return $lastActivity && $lastActivity->gte(now()->subMonths(self::INACTIVE_AFTER_MONTHS))
            ? 'active' : 'inactive';
    }

    /** Derniere activite connue : utilisation de l'app, connexion, FISS remplie, presence. */
    public function lastActivityAt(): ?Carbon
    {
        $dates = array_filter([
            $this->last_seen_at,
            $this->last_login_at,
            ($a = $this->attendances()->max('attended_on')) ? Carbon::parse($a) : null,
            ($f = SpiritualHealthForm::where('user_id', $this->id)->max('submitted_at')) ? Carbon::parse($f) : null,
        ]);

        return $dates ? collect($dates)->max() : null;
    }

    /** Compatibilite : derniere fois vu (utilise par les ecrans). */
    public function lastSeenAt(): ?Carbon
    {
        return $this->lastActivityAt();
    }

    /** Statut d'activite effectif : remplacement manuel, sinon statut stocke (mis a jour chaque jour). */
    public function activityStatus(): string
    {
        return in_array($this->activity_override, ['active', 'inactive'], true)
            ? $this->activity_override
            : ($this->activity_status ?: 'active');
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

    public function healthForms(): HasMany
    {
        return $this->hasMany(SpiritualHealthForm::class);
    }

    /** Annonces recues (avec etat lu/non-lu). */
    public function receivedAnnouncements(): BelongsToMany
    {
        return $this->belongsToMany(Announcement::class, 'announcement_user')->withPivot('read_at');
    }

    /** Centre de notifications du fidele. */
    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /** Appareils abonnes aux notifications push. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Liens familiaux declares par ce membre (conjoint, enfants). */
    public function familyLinks(): HasMany
    {
        return $this->hasMany(FamilyLink::class);
    }

    /** Departements dont ce membre est responsable. */
    public function ledDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_leaders')->withTimestamps();
    }

    /** Roles attribues au fidele (avec portee eventuelle : tribu / GEM / departement / fidele). */
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

    /** Ids des departements diriges (memorise pour la requete). */
    public function ledDepartmentIds(): array
    {
        return $this->ledDepartmentIdsCache ??= $this->ledDepartments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Toutes les cles de permissions : roles + responsabilite de departement. */
    public function permissionKeys(): Collection
    {
        // Cache lie a la collection de roles chargee : un rechargement des roles le renouvelle.
        $roles = $this->roles;
        if ($this->permissionCache === null || $this->permissionCacheFor !== $roles) {
            $this->permissionCacheFor = $roles;
            $this->permissionCache = $roles->flatMap(fn (Role $r) => $r->permissions->pluck('key'))
                ->merge($this->ledDepartmentIds() ? self::DEPARTMENT_LEADER_PERMISSIONS : [])
                ->unique()->values();
        }

        return $this->permissionCache;
    }

    /** Le super admin a tous les droits ; sinon on verifie la permission. */
    public function hasPermission(string $key): bool
    {
        return $this->isSuperAdmin() || $this->permissionKeys()->contains($key);
    }

    /** A recharger apres un changement de roles au cours de la meme requete. */
    public function forgetPermissions(): void
    {
        $this->permissionCache = null;
        $this->ledDepartmentIdsCache = null;
        $this->unsetRelation('roles');
    }

    /** Rang le plus eleve parmi les roles de l'utilisateur (0 s'il n'en a pas). */
    public function highestRank(): int
    {
        return (int) ($this->roles->max('rank') ?? 0);
    }

    /** Ids des tribus sur lesquelles l'utilisateur a une portee (un AP peut en avoir plusieurs). */
    public function scopeTribeIds(): array
    {
        return $this->scopeIds('tribe');
    }

    /** Ids des departements sur lesquels l'utilisateur a une portee (roles + departements diriges). */
    public function scopeDepartmentIds(): array
    {
        return array_values(array_unique(array_merge($this->scopeIds('department'), $this->ledDepartmentIds())));
    }

    /** Ids des GEMs sur lesquels l'utilisateur a une portee (Garde). */
    public function scopeGemIds(): array
    {
        return $this->scopeIds('gem');
    }

    /** Ids des fideles confies personnellement (role « Accompagnateur d'un fidele »). */
    public function scopeMemberIds(): array
    {
        return $this->scopeIds('member');
    }

    /** @return array<int> */
    private function scopeIds(string $kind): array
    {
        return $this->roles->where('pivot.scope_kind', $kind)
            ->pluck('pivot.scope_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Peut-il publier (annonces, evenements, exercices) pour toute l'eglise ? */
    public function canBroadcastAll(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('broadcast.all');
    }

    /**
     * Peut-il CONSULTER la fiche de ce membre ?
     * members.view_all (pasteurs) => tout le monde ; sinon uniquement sa portee :
     * ses tribus (un AP ne voit que les tribus qui lui sont assignees), GEMs, departements, fideles confies.
     */
    public function canViewMember(self $target): bool
    {
        if ($this->hasPermission('members.view_all')) {
            return true;
        }
        if (! $this->hasPermission('members.view_scope')) {
            return false;
        }

        return $target->id === $this->id || $this->hasInScope($target);
    }

    /**
     * Peut-il AGIR sur ce membre (noter, suivi spirituel, statut, demandes, accueil) ?
     * Meme portee que la consultation, mais jamais sur soi-meme (sauf autorite pastorale).
     */
    public function canManageMember(self $target): bool
    {
        if ($this->hasPermission('members.view_all')) {
            return true;
        }
        if (! $this->hasPermission('members.view_scope') || $target->id === $this->id) {
            return false;
        }

        return $this->hasInScope($target);
    }

    /** Le membre fait-il partie de la portee de l'utilisateur ? */
    private function hasInScope(self $target): bool
    {
        if (in_array($target->id, $this->scopeMemberIds(), true)) {
            return true;
        }

        $profile = $target->relationLoaded('profile') && $target->profile?->relationLoaded('departments')
            ? $target->profile
            : $target->profile()->with('departments:id')->first();
        if (! $profile) {
            return false;
        }
        if ($profile->gem_id && in_array((int) $profile->gem_id, $this->scopeGemIds(), true)) {
            return true;
        }
        if ($profile->tribe_id && in_array((int) $profile->tribe_id, $this->scopeTribeIds(), true)) {
            return true;
        }

        return $profile->departments->pluck('id')->map(fn ($id) => (int) $id)
            ->intersect($this->scopeDepartmentIds())->isNotEmpty();
    }
}
