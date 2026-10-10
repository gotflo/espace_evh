<?php

namespace App\Console\Commands;

use App\Models\SecurityLog;
use Illuminate\Console\Command;

/**
 * Journal de securite prive (operations du compte de gestion, activations, tentatives refusees).
 * Lisible uniquement sur le serveur :
 *   php artisan app:security-log                     50 dernieres lignes
 *   php artisan app:security-log --limit=200 --action=manager.activation --level=alert
 */
class SecurityLogShow extends Command
{
    protected $signature = 'app:security-log {--limit=50} {--action= : debut du code d\'action} {--level= : info, warning ou alert}';

    protected $description = 'Affiche le journal de sécurité privé (compte de gestion principal).';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $rows = SecurityLog::query()
            ->when($this->option('action'), fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $a).'%'))
            ->when($this->option('level'), fn ($q, $l) => $q->where('level', $l))
            ->orderByDesc('id')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->line('Journal de sécurité vide.');

            return self::SUCCESS;
        }

        $this->table(['#', 'Date', 'Niveau', 'Action', 'Compte', 'Objet', 'IP', 'Détails'], $rows->map(fn (SecurityLog $l) => [
            $l->id,
            $l->created_at?->format('Y-m-d H:i:s'),
            $l->level,
            $l->action,
            $l->user_id ? '#'.$l->user_id : '-',
            $l->subject_type ? $l->subject_type.' #'.$l->subject_id : '-',
            $l->ip ?? '-',
            mb_strimwidth((string) json_encode($l->details, JSON_UNESCAPED_UNICODE), 0, 120, '…'),
        ])->all());

        return self::SUCCESS;
    }
}
