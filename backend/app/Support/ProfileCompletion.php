<?php

namespace App\Support;

use App\Models\FamilyLink;
use App\Models\Profile;

/**
 * Completion du profil : liste des informations necessaires (questionnaire dynamique :
 * les questions sur le conjoint, le mariage et les enfants n'apparaissent que si elles
 * s'appliquent). Le pourcentage est stocke dans profiles.completion pour filtrer vite.
 */
class ProfileCompletion
{
    /**
     * @return array{percent: int, missing: array<int, array{key: string, label: string}>, recommended: array<int, array{key: string, label: string}>}
     */
    /**
     * @param  array{spouse: bool, children: int}|null  $family  liens familiaux deja connus
     *                                                        (calcul groupe) ; sinon lus en base.
     */
    public static function for(Profile $p, ?array $family = null): array
    {
        $checks = [
            'first_name' => ['Prénom', filled($p->first_name)],
            'last_name' => ['Nom', filled($p->last_name)],
            'gender' => ['Genre', filled($p->gender)],
            'birthday' => ['Jour et mois de naissance', $p->birth_day && $p->birth_month],
            'tribe' => ['Tribu', (bool) $p->tribe_id],
            'marital_status' => ['Situation matrimoniale', filled($p->marital_status)],
            'has_children' => ['Avez-vous des enfants ?', $p->has_children !== null],
        ];

        if ($p->marital_status === 'marie') {
            $hasSpouse = filled($p->spouse_name) || ($family !== null ? $family['spouse'] : FamilyLink::where('user_id', $p->user_id)->where('relation', 'spouse')
                ->whereIn('status', ['pending', 'confirmed'])->exists());
            $checks['spouse'] = ['Nom du conjoint(e)', $hasSpouse];
            $checks['wedding_date'] = ['Jour et mois du mariage', $p->wedding_day && $p->wedding_month];
        }
        if ($p->has_children) {
            $count = $family !== null ? $family['children'] : FamilyLink::where('user_id', $p->user_id)->where('relation', 'child')->count();
            $checks['children'] = ['Nom et année de naissance des enfants', $count > 0 && (! $p->children_count || $count >= $p->children_count)];
        }

        $missing = [];
        foreach ($checks as $key => [$label, $ok]) {
            if (! $ok) {
                $missing[] = ['key' => $key, 'label' => $label];
            }
        }
        $recommended = [];
        if (! $p->photo_path) {
            $recommended[] = ['key' => 'photo', 'label' => 'Photo de profil'];
        }
        if (! filled($p->email)) {
            $recommended[] = ['key' => 'email', 'label' => 'Adresse e-mail'];
        }

        return [
            'percent' => (int) round((count($checks) - count($missing)) / count($checks) * 100),
            'missing' => $missing,
            'recommended' => $recommended,
        ];
    }

    /** Recalcule et enregistre le pourcentage (sans toucher a updated_at). */
    /**
     * Recalcul groupe (tache quotidienne) : les liens familiaux d'un lot de profils sont lus
     * en 2 requetes au lieu d'une ou deux par profil.
     *
     * @param  iterable<Profile>  $profiles
     */
    public static function refreshMany(iterable $profiles): int
    {
        $list = collect($profiles);
        $ids = $list->pluck('user_id')->all();
        $spouses = FamilyLink::whereIn('user_id', $ids)->where('relation', 'spouse')
            ->whereIn('status', ['pending', 'confirmed'])->pluck('user_id')->flip();
        $children = FamilyLink::whereIn('user_id', $ids)->where('relation', 'child')
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $changed = 0;
        foreach ($list as $p) {
            $percent = self::for($p, ['spouse' => isset($spouses[$p->user_id]), 'children' => (int) ($children[$p->user_id] ?? 0)])['percent'];
            if ((int) $p->completion !== $percent) {
                $p->forceFill(['completion' => $percent])->saveQuietly();
                $changed++;
            }
        }

        return $changed;
    }

    public static function refresh(Profile $p): int
    {
        $percent = self::for($p)['percent'];
        if ((int) $p->completion !== $percent) {
            $p->forceFill(['completion' => $percent])->saveQuietly();
        }

        return $percent;
    }
}
