<?php

namespace App\Models\Concerns;

use App\Models\Department;
use App\Models\Gem;
use App\Models\PublicationScope;
use App\Models\Tribe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Portee de publication multiple (annonces, evenements, exercices) :
 * toute l'eglise, une ou plusieurs tribus, GEMs ou departements.
 * Le modele declare `public const SCOPABLE_TYPE = '...'`.
 */
trait HasPublicationScopes
{
    public function scopes(): HasMany
    {
        return $this->hasMany(PublicationScope::class, 'scopable_id')->where('scopable_type', static::SCOPABLE_TYPE);
    }

    /**
     * Remplace les portees.
     *
     * @param  array<int, array{type: string, id: int|null}>  $scopes
     */
    public function syncScopes(array $scopes): void
    {
        DB::transaction(function () use ($scopes) {
            PublicationScope::where('scopable_type', static::SCOPABLE_TYPE)->where('scopable_id', $this->id)->delete();
            foreach ($scopes as $s) {
                PublicationScope::create([
                    'scopable_type' => static::SCOPABLE_TYPE,
                    'scopable_id' => $this->id,
                    'scope_type' => $s['type'],
                    'scope_id' => $s['type'] === 'church' ? null : (int) $s['id'],
                ]);
            }
        });
        $this->unsetRelation('scopes');
    }

    /** @return array<int, array{type: string, id: int|null}> */
    public function audienceList(): array
    {
        return $this->scopes->map(fn (PublicationScope $s) => ['type' => $s->scope_type, 'id' => $s->scope_id])->values()->all();
    }

    public function isForWholeChurch(): bool
    {
        return $this->scopes->contains('scope_type', 'church');
    }

    /** « Toute l'église », « Tribus Juda, Lévi », « GEM Béthel · Dépt. Chorale »... */
    public function audienceLabel(): string
    {
        if ($this->isForWholeChurch()) {
            return "Toute l'église";
        }
        $parts = [];
        $names = fn (string $model, string $type) => $model::whereIn('id', $this->scopes->where('scope_type', $type)->pluck('scope_id'))->orderBy('name')->pluck('name')->all();
        if ($t = $names(Tribe::class, 'tribe')) {
            $parts[] = (count($t) > 1 ? 'Tribus ' : 'Tribu ').implode(', ', $t);
        }
        if ($g = $names(Gem::class, 'gem')) {
            $parts[] = (count($g) > 1 ? 'GEMs ' : 'GEM ').implode(', ', $g);
        }
        if ($d = $names(Department::class, 'department')) {
            $parts[] = 'Dépt. '.implode(', ', $d);
        }

        return $parts ? implode(' · ', $parts) : "Toute l'église";
    }

    /**
     * Publications qui concernent ce membre : toute l'eglise, sa tribu, son GEM, ses departements,
     * celles de sa portee de responsable, et celles qu'il a creees.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $profile = $user->profile()->with('departments:id')->first();
        $tribes = array_values(array_unique(array_filter(array_merge([(int) $profile?->tribe_id], $user->scopeTribeIds()))));
        $gems = array_values(array_unique(array_filter(array_merge([(int) $profile?->gem_id], $user->scopeGemIds()))));
        $depts = array_values(array_unique(array_merge(
            $profile ? $profile->departments->pluck('id')->map(fn ($id) => (int) $id)->all() : [],
            $user->scopeDepartmentIds(),
        )));
        $type = static::SCOPABLE_TYPE;
        $table = $this->getTable();

        return $query->where(function (Builder $q) use ($user, $tribes, $gems, $depts, $type, $table) {
            $q->whereExists(function ($s) use ($tribes, $gems, $depts, $type, $table) {
                $s->selectRaw('1')->from('publication_scopes as ps')
                    ->whereColumn('ps.scopable_id', "{$table}.id")->where('ps.scopable_type', $type)
                    ->where(function ($w) use ($tribes, $gems, $depts) {
                        $w->where('ps.scope_type', 'church')
                            ->orWhere(fn ($x) => $x->where('ps.scope_type', 'tribe')->whereIn('ps.scope_id', $tribes ?: [0]))
                            ->orWhere(fn ($x) => $x->where('ps.scope_type', 'gem')->whereIn('ps.scope_id', $gems ?: [0]))
                            ->orWhere(fn ($x) => $x->where('ps.scope_type', 'department')->whereIn('ps.scope_id', $depts ?: [0]));
                    });
            })->orWhere("{$table}.created_by", $user->id);
        });
    }
}
