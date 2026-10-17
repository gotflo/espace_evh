<?php

namespace App\Models;

use App\Support\PlatformManager;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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

    /** Validation d'un nouveau compte par le pasteur. */
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    /**
     * Droits donnes par la responsabilite d'un departement (anciennement le role « Responsable ») :
     * voir les membres du departement, faire l'appel des repetitions, publier ses evenements.
     */
    public const DEPARTMENT_LEADER_PERMISSIONS = [
        'members.view_scope', 'spiritual.view', 'attendance.view', 'attendance.record',
        'events.manage', 'events.delete', 'events.participants', 'exercises.respond', 'departments.approve',
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
            'notification_prefs' => 'array',
            'blocked_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'approval_decided_at' => 'datetime',
            'absence_notified_at' => 'datetime',
            'platform_manager_at' => 'datetime',
        ];
    }

    /** Compte valide par le pasteur (tous les comptes d'avant la validation le sont). */
    public function isApproved(): bool
    {
        return ($this->approval_status ?? self::APPROVED) === self::APPROVED;
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

        return $lastActivity && $lastActivity->gte(now()->subMonths(PlatformSettings::int('members.inactive_after_months')))
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

    /**
     * Compte de gestion principal active (numero reserve + OTP + code d'activation). Ses droits ne
     * dependent d'aucun role : toutes les permissions de gestion, aucune permission confidentielle.
     */
    public function isPlatformManager(): bool
    {
        return $this->platform_manager_at !== null && PlatformManager::isReservedPhone($this->phone);
    }

    /** Comptes ordinaires seulement (sans le compte de gestion principal). */
    public function scopeExceptPlatformManagers(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.platform_manager_at');
    }

    /** Pasteur Resident (role). Jamais pour le compte de gestion, meme si un role lui etait reste attache. */
    public function isSuperAdmin(): bool
    {
        return ! $this->isPlatformManager() && $this->hasRole(self::SUPER_ADMIN);
    }

    /** Ids des departements diriges (memorise pour la requete). */
    public function ledDepartmentIds(): array
    {
        if ($this->isPlatformManager()) {
            return [];
        }

        return $this->ledDepartmentIdsCache ??= $this->ledDepartments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Toutes les cles de permissions : roles + responsabilite de departement. */
    public function permissionKeys(): Collection
    {
        if ($this->isPlatformManager()) {
            return collect(PlatformManager::permissions());
        }
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
        // Compte de gestion : liste fermee de permissions de gestion (refus par defaut, aucune donnee confidentielle).
        if ($this->isPlatformManager()) {
            return PlatformManager::allows($key);
        }

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
        if ($this->isPlatformManager()) {
            return [];
        }

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
    public function canViewMember(self $target, ?string $permission = null): bool
    {
        // Le compte de gestion principal n'est pas un membre : il n'a pas de fiche a consulter.
        if ($target->isPlatformManager()) {
            return false;
        }
        if ($permission && ! $this->hasPermission($permission)) {
            return false;
        }
        if ($this->hasPermission('members.view_all')) {
            return true;
        }
        if (! $this->hasPermission('members.view_scope')) {
            return false;
        }

        return $target->id === $this->id || $this->hasInScope($target, $permission);
    }

    /**
     * Peut-il AGIR sur ce membre (noter, suivi spirituel, statut, demandes, accueil) ?
     * Meme portee que la consultation, mais jamais sur soi-meme (sauf autorite pastorale).
     * Avec une permission : seulement dans la portee des roles qui la donnent (voir scopesFor).
     */
    public function canManageMember(self $target, ?string $permission = null): bool
    {
        if ($target->isPlatformManager()) {
            return false;
        }
        if ($permission && ! $this->hasPermission($permission)) {
            return false;
        }
        if ($this->hasPermission('members.view_all')) {
            return true;
        }
        if (! $this->hasPermission('members.view_scope') || $target->id === $this->id) {
            return false;
        }

        return $this->hasInScope($target, $permission);
    }

    /**
     * Portees ou une permission s'applique : celles des roles qui la donnent, plus les departements
     * diriges pour les droits de responsable de departement. Une permission de Garde ne vaut donc que
     * sur son GEM, jamais sur les membres d'un departement qu'il dirige par ailleurs. Sans permission :
     * toutes ses portees (consultation de la fiche).
     *
     * @return array{tribe: array<int>, gem: array<int>, department: array<int>, member: array<int>}
     */
    public function scopesFor(?string $permission = null): array
    {
        if (! $permission) {
            return ['tribe' => $this->scopeTribeIds(), 'gem' => $this->scopeGemIds(),
                'department' => $this->scopeDepartmentIds(), 'member' => $this->scopeMemberIds()];
        }
        $scopes = ['tribe' => [], 'gem' => [], 'department' => [], 'member' => []];
        if ($this->isPlatformManager()) {
            return $scopes;
        }
        foreach ($this->roles as $role) {
            $kind = $role->pivot->scope_kind;
            if ($kind && isset($scopes[$kind]) && $role->pivot->scope_id && $role->permissions->contains('key', $permission)) {
                $scopes[$kind][] = (int) $role->pivot->scope_id;
            }
        }
        if (in_array($permission, self::DEPARTMENT_LEADER_PERMISSIONS, true)) {
            $scopes['department'] = array_merge($scopes['department'], $this->ledDepartmentIds());
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $scopes);
    }

    /**
     * Tribus dont l'utilisateur voit les fiches de sante spirituelle (FISS) : null = toutes
     * (autorite pastorale), sinon les tribus dont il est responsable (patriarche, Assistant Pasteur).
     * Un Garde, un responsable de departement ou un accompagnateur suivent leurs membres SANS voir
     * leurs fiches : la portee GEM / departement / fidele confie ne donne jamais acces aux FISS.
     *
     * @return array<int>|null
     */
    public function fissTribeIds(): ?array
    {
        if (! $this->hasPermission('spiritual.view')) {
            return [];
        }

        return $this->hasPermission('members.view_all') ? null : $this->scopeTribeIds();
    }

    /** Peut-il voir les FISS de ce membre (contenu, fiche du mois remplie ou non, historique) ? */
    public function canViewFissOf(self $target): bool
    {
        if ($target->isPlatformManager()) {
            return false;
        }
        $tribeIds = $this->fissTribeIds();
        if ($tribeIds === null) {
            return true;
        }
        $tribeId = $target->relationLoaded('profile') ? $target->profile?->tribe_id : $target->profile()->value('tribe_id');

        return $tribeId && in_array((int) $tribeId, $tribeIds, true);
    }

    /** Le membre fait-il partie de la portee de l'utilisateur (pour cette permission, si elle est donnee) ? */
    private function hasInScope(self $target, ?string $permission = null): bool
    {
        $scopes = $this->scopesFor($permission);
        if (in_array($target->id, $scopes['member'], true)) {
            return true;
        }

        $profile = $target->relationLoaded('profile') && $target->profile?->relationLoaded('departments')
            ? $target->profile
            : $target->profile()->with('departments:id')->first();
        if (! $profile) {
            return false;
        }
        if ($profile->gem_id && in_array((int) $profile->gem_id, $scopes['gem'], true)) {
            return true;
        }
        if ($profile->tribe_id && in_array((int) $profile->tribe_id, $scopes['tribe'], true)) {
            return true;
        }

        return $profile->departments->pluck('id')->map(fn ($id) => (int) $id)
            ->intersect($scopes['department'])->isNotEmpty();
    }
}
