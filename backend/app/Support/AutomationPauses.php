<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensions datees des rappels automatiques (centre de notifications) : une etape de app:tick ne part pas du
 * jour de debut au jour de fin inclus, puis reprend d'elle-meme. Lu a chaque passage : garde en cache, efface a
 * chaque modification.
 */
class AutomationPauses
{
    private const CACHE_KEY = 'automation:pauses';

    /** @return array<string, array{paused_from: string, paused_until: string, reason: ?string}> etape => periode */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('automation_pauses')) {
                return [];
            }

            return DB::table('automation_pauses')->get(['step', 'paused_from', 'paused_until', 'reason'])
                ->keyBy('step')->map(fn ($r) => [
                    'paused_from' => substr((string) $r->paused_from, 0, 10),
                    'paused_until' => substr((string) $r->paused_until, 0, 10),
                    'reason' => $r->reason,
                ])->all();
        });
    }

    public static function pausedToday(string $step): bool
    {
        $pause = self::all()[$step] ?? null;
        $today = now()->toDateString();

        return $pause !== null && $today >= $pause['paused_from'] && $today <= $pause['paused_until'];
    }

    /** Pose (ou retire, avec $from = null) la periode de suspension d'une etape. */
    public static function set(string $step, ?string $from, ?string $until, ?string $reason, ?int $by): void
    {
        if ($from === null || $until === null) {
            DB::table('automation_pauses')->where('step', $step)->delete();
        } else {
            DB::table('automation_pauses')->updateOrInsert(['step' => $step], [
                'paused_from' => $from, 'paused_until' => $until, 'reason' => $reason, 'updated_by' => $by,
                'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        Cache::forget(self::CACHE_KEY);
    }
}
