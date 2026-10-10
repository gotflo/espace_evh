<?php

namespace App\Support;

use App\Models\SecurityLog;
use App\Models\User;
use App\Services\Monitoring\Monitor;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal de securite prive (table security_logs) : il remplace le journal d'activite ordinaire pour
 * les operations du compte de gestion principal et consigne les activations et tentatives d'usurpation.
 * Les evenements d'alerte sont aussi remontes a la console de supervision (Monitor), sans donnee sensible.
 */
class SecurityJournal
{
    /** @param array<string, mixed> $details */
    public static function record(
        string $action,
        array $details = [],
        string $level = 'info',
        ?User $actor = null,
        ?Model $subject = null,
        ?int $memberUserId = null,
    ): SecurityLog {
        $actor ??= auth()->user();
        $request = app()->runningInConsole() ? null : request();

        $log = SecurityLog::create([
            'user_id' => $actor?->id,
            'level' => in_array($level, ['info', 'warning', 'alert'], true) ? $level : 'info',
            'action' => mb_substr($action, 0, 80),
            'subject_type' => $subject ? Audit::typeOf($subject) : null,
            'subject_id' => $subject?->getKey(),
            'member_user_id' => $memberUserId,
            'details' => $details ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : 'console',
        ]);

        if ($level !== 'info') {
            Monitor::event($level === 'alert' ? 'error' : 'warning', 'security', 'manager.'.mb_substr($action, 0, 40),
                'Compte de gestion : '.$action, [], 'denied', $actor?->id);
        }

        return $log;
    }
}
