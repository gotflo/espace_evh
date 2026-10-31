<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pour 5 000 membres : signatures du pouls (max(updated_at) lu par l'index au lieu de toute la
 * table), comptes de participations, cloche et journal anti-doublon. Ajout seul, sans verrou long sur
 * MariaDB (InnoDB : ALTER TABLE ... ADD INDEX se fait en ligne), et rien si l'index existe deja.
 */
return new class extends Migration
{
    /** @var array<string, array<int, array<int, string>>> */
    private const INDEXES = [
        'attendances' => [['updated_at']],
        'spiritual_health_forms' => [['updated_at']],
        'event_participations' => [['updated_at'], ['event_id', 'occurs_on', 'response']],
        'profiles' => [['updated_at'], ['created_at']],
        'gem_weekly_reports' => [['updated_at']],
        'member_requests' => [['updated_at']],
        'users' => [['approval_requested_at'], ['approval_decided_at']],
        'user_notifications' => [['user_id', 'created_at']],
        'notification_dispatches' => [['sent_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
                }
            }
        }
    }
};
