<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Un seul passage des automatismes a la fois, qu'il vienne du cron ou de l'activite de l'application
 * (TriggerAutomation) : deux passages simultanes enverraient certains rappels en double.
 */
class AutomationLock
{
    /** Le cron a tourne recemment : le declenchement par l'activite web se met en retrait. */
    public const CRON_SEEN = 'automation:cron-seen';

    public static function run(callable $work): void
    {
        $lock = Cache::lock('automation:tick-running', 900);
        if (! $lock->get()) {
            return;
        }
        try {
            $work();
        } finally {
            $lock->release();
        }
    }

    public static function markCronSeen(): void
    {
        Cache::put(self::CRON_SEEN, now()->getTimestamp(), now()->addMinutes(15));
    }

    public static function cronSeen(): bool
    {
        return Cache::has(self::CRON_SEEN);
    }
}
