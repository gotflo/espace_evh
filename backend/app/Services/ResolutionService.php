<?php

namespace App\Services;

use App\Models\MonthlyResolution;
use App\Models\ResolutionResource;
use App\Models\ResourceView;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\ResolutionCatalog;
use App\Support\YouTube;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mes RM (Résolutions du Mois) :
 * - une fiche par mois, modifiable tout le mois ;
 * - « Je renouvelle » (bouton ou phrase ecrite) reprend la resolution du mois precedent ;
 * - le bilan du mois precedent (atteinte, en partie, pas encore) se fait au debut du mois suivant ;
 * - les rubriques sont eclairees par la derniere FISS (et l'assiduite pointee aux cultes).
 */
class ResolutionService
{
    /** Ecran « Mes RM » du membre. @return array<string, mixed> */
    public static function overview(User $user): array
    {
        $period = now()->format('Y-m');
        $rows = MonthlyResolution::where('user_id', $user->id)->orderByDesc('period')->limit(13)->get();
        $current = $rows->firstWhere('period', $period);
        $previous = $rows->first(fn ($r) => $r->period < $period);

        return [
            'period' => $period,
            'period_label' => FissService::periodLabel($period),
            'verse' => ResolutionCatalog::VERSE,
            'renew_phrase' => ResolutionCatalog::RENEW_PHRASE,
            'fields' => ResolutionCatalog::fields(),
            'outcomes' => ResolutionCatalog::OUTCOMES,
            'current' => $current ? self::present($current) : null,
            'previous' => $previous ? self::present($previous) : null,
            // Bilan a faire : resolutions du mois dernier (exactement, comme review() et « Mon mois »)
            // pas encore evaluees ; une fiche plus ancienne ne demande plus de bilan.
            'review_needed' => $previous !== null && $previous->period === now()->subMonthNoOverflow()->format('Y-m')
                && $previous->outcome === null && $previous->filled() !== [],
            'hints' => self::hints($user),
            'resources' => self::resources(false, $user),
            'history' => $rows->where('period', '!=', $period)->take(12)->map(fn ($r) => self::present($r))->values(),
        ];
    }

    /** Enregistre les resolutions du mois (creation ou modification pendant le mois). */
    public static function save(User $user, array $data): MonthlyResolution
    {
        $period = now()->format('Y-m');

        return DB::transaction(function () use ($user, $data, $period) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $previous = MonthlyResolution::where('user_id', $user->id)->where('period', '<', $period)->orderByDesc('period')->first();

            $values = [];
            $renewed = [];
            foreach (ResolutionCatalog::keys() as $key) {
                $text = isset($data[$key]) ? trim((string) $data[$key]) : '';
                $before = $previous ? trim((string) $previous->{$key}) : '';
                // « Je renouvelle » ecrit en toutes lettres : la resolution du mois precedent est reprise.
                if ($before !== '' && ResolutionCatalog::isRenewPhrase($text)) {
                    $text = $before;
                }
                $values[$key] = $text !== '' ? $text : null;
                if ($text !== '' && $text === $before) {
                    $renewed[] = $key;
                }
            }
            abort_if(array_filter($values) === [], 422, 'Écrivez au moins une résolution (ou renouvelez celles du mois dernier).');

            $resolution = MonthlyResolution::updateOrCreate(
                ['user_id' => $user->id, 'period' => $period],
                $values + ['renewed' => $renewed ?: null],
            );
            ActivityService::touch($user, true, 'resolutions');

            return $resolution;
        });
    }

    /** Bilan des resolutions du mois precedent (modifiable jusqu'a la fin du mois en cours). */
    public static function review(User $user, array $outcome): MonthlyResolution
    {
        $period = now()->format('Y-m');
        $previous = MonthlyResolution::where('user_id', $user->id)->where('period', '<', $period)->orderByDesc('period')->first();
        abort_unless($previous, 404, 'Aucune résolution du mois dernier à évaluer.');
        abort_unless($previous->period === now()->subMonthNoOverflow()->format('Y-m'), 422, 'Le bilan se fait seulement pour les résolutions du mois dernier.');

        $filled = array_keys($previous->filled());
        $clean = array_intersect_key($outcome, array_flip($filled));
        abort_if($clean === [], 422, 'Indiquez au moins un bilan.');
        $previous->forceFill(['outcome' => $clean, 'outcome_at' => now()])->save();

        return $previous;
    }

    /** @return array<string, mixed> */
    public static function present(MonthlyResolution $r): array
    {
        $filled = $r->filled();
        $outcome = $r->outcome ?? [];

        return [
            'id' => $r->id,
            'period' => $r->period,
            'period_label' => FissService::periodLabel($r->period),
            ...$r->only(ResolutionCatalog::keys()),
            'renewed' => $r->renewed ?? [],
            'outcome' => $outcome ?: null,
            'outcome_at' => $r->outcome_at?->toIso8601String(),
            'count' => count($filled),
            'achieved' => count(array_filter($outcome, fn ($v) => $v === 'atteinte')),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Reperes « selon le FISS » : la derniere fiche (ce mois-ci, sinon la precedente) montre les
     * rubriques a fortifier ; l'assiduite vient des presences pointees aux cultes (3 mois).
     *
     * @return array<string, mixed>
     */
    public static function hints(User $user): array
    {
        $form = SpiritualHealthForm::where('user_id', $user->id)->orderByDesc('period')->first();
        $out = ['fiss_period_label' => $form ? FissService::periodLabel($form->period) : null, 'items' => []];
        if ($form) {
            foreach (['meditation', 'priere', 'jeune'] as $k) {
                if ($form->{$k} !== null) {
                    $v = (int) $form->{$k};
                    $out['items'][$k] = ['value' => "{$v}/20", 'level' => $v >= 15 ? 'bon' : ($v >= 10 ? 'moyen' : 'faible')];
                }
            }
            $sanct = array_filter([$form->sanctification_corps, $form->sanctification_ame, $form->sanctification_esprit]);
            if ($sanct) {
                $worst = in_array('mal', $sanct, true) ? 'mal' : (in_array('moyen', $sanct, true) ? 'moyen' : 'bien');
                $out['items']['sanctification'] = [
                    'value' => ['mal' => 'Mal', 'moyen' => 'Moyen', 'bien' => 'Bien'][$worst],
                    'level' => ['mal' => 'faible', 'moyen' => 'moyen', 'bien' => 'bon'][$worst],
                ];
            }
        }
        // Sessions reellement appelees pour son groupe (ses seules lignes donnaient toujours 100 %).
        $culte = AttendanceStats::culte($user, now()->subMonths(3));
        if ($culte['rate'] !== null) {
            $rate = $culte['rate'];
            $present = min($culte['present'], $culte['sessions']);
            $out['items']['assiduite'] = ['value' => "{$present}/{$culte['sessions']} cultes", 'level' => $rate >= 80 ? 'bon' : ($rate >= 50 ? 'moyen' : 'faible')];
        }

        return $out;
    }

    /**
     * Ressources par theme (meditation, priere, jeune, culte) : les membres ne voient que les ressources
     * publiees ; l'onglet de gestion voit aussi les brouillons.
     *
     * @return array<string, mixed>
     */
    public static function resources(bool $withDrafts = false, ?User $viewer = null): array
    {
        $items = ResolutionResource::when(! $withDrafts, fn ($q) => $q->where('status', 'published'))
            ->orderBy('position')->orderBy('id')->get();
        // Membre : ou il en est de chaque video (pourcentage regarde, reprise).
        $views = $viewer ? ResourceView::where('user_id', $viewer->id)->whereIn('resolution_resource_id', $items->pluck('id'))->get()->keyBy('resolution_resource_id') : collect();
        $out = [];
        foreach (ResolutionCatalog::THEMES as $theme => $label) {
            $out[] = [
                'theme' => $theme,
                'label' => $label,
                'items' => $items->where('theme', $theme)->map(fn (ResolutionResource $r) => self::presentResource($r)
                    + ($viewer && $r->youtube_id ? ['watch' => ResourceProgress::stateFor($r, $views->get($r->id))] : []))->values(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function presentResource(ResolutionResource $r): array
    {
        return [
            'id' => $r->id,
            'theme' => $r->theme,
            'title' => $r->title,
            'author' => $r->author,
            'url' => $r->youtube_id ? YouTube::watchUrl($r->youtube_id) : $r->url,
            'youtube_id' => $r->youtube_id,
            'note' => $r->note,
            'position' => $r->position,
            'status' => $r->status ?? 'published',
            'kind' => $r->youtube_id ? 'youtube' : 'link',
            'thumbnail' => $r->youtube_id ? YouTube::thumbnail($r->youtube_id) : null,
        ];
    }

    /** Libelle du mois precedent (« septembre 2026 »). */
    public static function previousLabel(): string
    {
        return Carbon::now()->subMonthNoOverflow()->locale('fr')->isoFormat('MMMM YYYY');
    }
}
