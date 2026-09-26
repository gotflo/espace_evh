<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Tribe;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Portee de publication (annonces, evenements, exercices) :
 * - quelles cibles un auteur peut choisir (toute l'eglise seulement avec broadcast.all ;
 *   sinon uniquement SES tribus, GEMs et departements) ;
 * - validation cote serveur de la selection (impossible de viser une tribu non assignee) ;
 * - destinataires correspondants.
 */
class Audience
{
    public const TYPES = ['church', 'tribe', 'gem', 'department'];

    /**
     * Cibles autorisees pour cet auteur.
     *
     * @return array{church: bool, tribes: array<int, array{id: int, name: string}>, gems: array<int, array{id: int, name: string, tribe_id: int}>, departments: array<int, array{id: int, name: string}>}
     */
    public static function options(User $author): array
    {
        $all = $author->canBroadcastAll();
        $pick = fn ($query, array $ids) => $all ? $query : $query->whereIn('id', $ids ?: [0]);

        return [
            'church' => $all,
            'tribes' => $pick(Tribe::query(), $author->scopeTribeIds())->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'gems' => $pick(Gem::query(), array_merge($author->scopeGemIds(), $all ? [] : Gem::whereIn('tribe_id', $author->scopeTribeIds() ?: [0])->pluck('id')->all()))
                ->orderBy('name')->get(['id', 'name', 'tribe_id'])->toArray(),
            'departments' => $pick(Department::query(), $author->scopeDepartmentIds())->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    /**
     * Valide la selection de l'auteur. Selection vide => sa portee par defaut
     * (toute l'eglise s'il y est autorise, sinon toutes ses tribus / GEMs / departements).
     *
     * @param  array<int, array{type?: string, id?: int|null}>|null  $input
     * @return array<int, array{type: string, id: int|null}>
     */
    public static function resolve(User $author, ?array $input): array
    {
        $options = self::options($author);
        $allowed = [
            'tribe' => array_column($options['tribes'], 'id'),
            'gem' => array_column($options['gems'], 'id'),
            'department' => array_column($options['departments'], 'id'),
        ];

        $scopes = [];
        foreach ($input ?? [] as $item) {
            $type = $item['type'] ?? null;
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(['scopes' => 'Destinataires invalides.']);
            }
            if ($type === 'church') {
                if (! $options['church']) {
                    abort(403, "Vous ne pouvez pas publier pour toute l'église. Choisissez vos tribus, GEMs ou départements.");
                }

                return [['type' => 'church', 'id' => null]]; // toute l'eglise englobe le reste
            }
            if (! $id || ! in_array($id, $allowed[$type], true)) {
                $label = ['tribe' => 'cette tribu', 'gem' => 'ce GEM', 'department' => 'ce département'][$type];
                abort(403, "Vous ne pouvez pas publier pour {$label} : elle ne fait pas partie de votre périmètre.");
            }
            $scopes[$type.':'.$id] = ['type' => $type, 'id' => $id];
        }

        if ($scopes) {
            return array_values($scopes);
        }
        if ($options['church']) {
            return [['type' => 'church', 'id' => null]];
        }
        foreach (['tribe' => $allowed['tribe'], 'gem' => $author->scopeGemIds(), 'department' => $allowed['department']] as $type => $ids) {
            if ($ids) {
                return array_map(fn ($id) => ['type' => $type, 'id' => (int) $id], $ids);
            }
        }

        abort(403, "Vous n'avez pas de périmètre de diffusion (tribu, GEM ou département). Demandez le rôle « Communication (toute l'église) ».");
    }

    /** Lit la selection envoyee (tableau, ou JSON pour les envois multipart avec image). */
    public static function fromRequest(\Illuminate\Http\Request $request): ?array
    {
        $raw = $request->input('scopes');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if ($raw !== null && ! is_array($raw)) {
            throw ValidationException::withMessages(['scopes' => 'Destinataires invalides.']);
        }

        return $raw;
    }

    /**
     * Peut-il gerer (voir dans l'administration, modifier, supprimer) cette publication ?
     * Auteur, diffusion generale, ou toutes ses portees comprises dans celles de l'utilisateur.
     *
     * @param  array<int, array{type: string, id: int|null}>  $scopes
     */
    public static function canManage(User $user, ?int $createdBy, array $scopes): bool
    {
        if ($createdBy && $createdBy === $user->id) {
            return true;
        }
        if ($user->canBroadcastAll()) {
            return true;
        }
        if ($scopes === []) {
            return false;
        }
        $options = self::options($user);
        $allowed = [
            'tribe' => array_column($options['tribes'], 'id'),
            'gem' => array_column($options['gems'], 'id'),
            'department' => array_column($options['departments'], 'id'),
        ];

        return collect($scopes)->every(fn ($s) => $s['type'] !== 'church' && in_array((int) $s['id'], $allowed[$s['type']] ?? [], true));
    }

    /**
     * Membres (profil complete) vises par une liste de portees.
     *
     * @param  array<int, array{type: string, id: int|null}>  $scopes
     * @return array<int>
     */
    public static function userIds(array $scopes): array
    {
        $query = Profile::query()->whereHas('user');
        $church = collect($scopes)->contains(fn ($s) => $s['type'] === 'church');
        if (! $church) {
            $by = fn (string $type) => collect($scopes)->where('type', $type)->pluck('id')->filter()->all();
            $tribes = $by('tribe');
            $gems = $by('gem');
            $depts = $by('department');
            if (! $tribes && ! $gems && ! $depts) {
                return [];
            }
            $query->where(function ($q) use ($tribes, $gems, $depts) {
                $q->whereIn('tribe_id', $tribes ?: [0])
                    ->orWhereIn('gem_id', $gems ?: [0])
                    ->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $depts ?: [0]));
            });
        }

        return $query->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
