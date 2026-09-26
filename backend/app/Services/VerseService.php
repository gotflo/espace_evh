<?php

namespace App\Services;

use App\Models\DashboardVerse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Choix du verset affiche sur le tableau de bord, sans intervention :
 * 1. un texte « mis en avant » dans sa periode l'emporte (le plus recemment commence) ;
 * 2. sinon, les textes publies et en periode tournent chaque jour, dans l'ordre defini ;
 * 3. sinon, le verset de l'eglise (Actes 20.28) s'affiche : jamais d'encadre vide.
 * Tout le monde voit le meme texte le meme jour.
 */
class VerseService
{
    public const CACHE_KEY = 'dashboard:verse';

    public const FALLBACK = [
        'id' => null,
        'label' => 'Notre appel',
        'text' => "Prenez donc garde à vous-mêmes, et à tout le troupeau au sein duquel le Saint-Esprit vous a établis évêques, pour paître l'Église de Dieu, qu'il s'est acquise par son propre sang.",
        'reference' => 'Actes 20.28',
        'message' => null,
    ];

    /** @return array{id: int|null, label: string, text: string, reference: string, message: string|null} */
    public static function current(): array
    {
        // Cache court, vide a chaque modification : le choix change au plus une fois par jour.
        return Cache::remember(self::CACHE_KEY.':'.now()->toDateString(), 300, fn () => self::pick(now()));
    }

    /** @return array{id: int|null, label: string, text: string, reference: string, message: string|null} */
    public static function pick(Carbon $at): array
    {
        $live = DashboardVerse::liveAt($at)->orderBy('position')->orderBy('id')->get();

        $pinned = $live->where('is_pinned', true)
            ->sortByDesc(fn (DashboardVerse $v) => $v->starts_at?->timestamp ?? 0)->first();
        $verse = $pinned;
        if (! $verse && $live->isNotEmpty()) {
            // Rotation quotidienne : jour n -> texte n modulo le nombre de textes.
            $day = (int) floor($at->copy()->startOfDay()->timestamp / 86400);
            $verse = $live->values()[$day % $live->count()];
        }

        return $verse ? self::present($verse) : self::FALLBACK;
    }

    /** @return array{id: int|null, label: string, text: string, reference: string, message: string|null} */
    public static function present(DashboardVerse $v): array
    {
        return ['id' => $v->id, 'label' => $v->label, 'text' => $v->text, 'reference' => $v->reference, 'message' => $v->message];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY.':'.now()->toDateString());
    }
}
