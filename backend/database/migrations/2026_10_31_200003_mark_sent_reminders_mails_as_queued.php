<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les courriels des rappels ont maintenant leur propre cle (mail:...). Pour les rappels deja envoyes,
 * ces courriels sont deja partis : la mise a jour ne fait repartir aucun rappel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_dispatches')
            ->where(fn ($q) => $q->where('key', 'like', 'fiss:%')->orWhere('key', 'like', 'leader-report:%'))
            ->orderBy('key')->select(['key', 'sent_at'])
            ->chunk(500, function ($rows) {
                DB::table('notification_dispatches')->insertOrIgnore($rows->map(fn ($row) => [
                    'key' => mb_substr('mail:'.$row->key, 0, 191),
                    'sent_at' => $row->sent_at,
                ])->all());
            });
    }

    public function down(): void
    {
        DB::table('notification_dispatches')->where('key', 'like', 'mail:%')->delete();
    }
};
