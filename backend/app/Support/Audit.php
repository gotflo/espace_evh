<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'audit : point d'entree unique pour tracer les actions sensibles.
 * Ne stocke que ce qui est utile (valeurs modifiees, contexte) ; l'IP est conservee pour
 * les actions faites depuis l'application (securite), jamais exposee aux membres.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        ?int $memberUserId = null,
        array $old = [],
        array $new = [],
        array $context = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? self::typeOf($subject) : null,
            'subject_id' => $subject?->getKey(),
            'member_user_id' => $memberUserId,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'context' => $context ?: null,
            'ip' => $request?->ip(),
        ]);
    }

    /**
     * Seules les valeurs qui changent.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            $prev = $before[$key] ?? null;
            if ((string) json_encode($prev) !== (string) json_encode($value)) {
                $old[$key] = $prev;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /** Nom court et stable du type d'objet (independant des noms de classe). */
    public static function typeOf(Model $model): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($model)));
    }
}
