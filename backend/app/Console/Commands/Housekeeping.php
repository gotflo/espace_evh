<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menage quotidien (en plus de celui des automatismes) : ce qui grossit sans jamais servir.
 * - jetons de connexion expires, codes SMS de plus de 2 jours (numeros complets) ;
 * - courriels et textos envoyes ou abandonnes depuis plus de 90 jours ;
 * - fichiers du cache expires : le cache en fichiers ne supprime un fichier perime que s'il le relit,
 *   et certaines cles ne sont jamais relues (une par membre et par jour, une par adresse limitee) ;
 *   sans menage, le nombre de fichiers du compte d'hebergement finirait par etre atteint.
 */
class Housekeeping extends Command
{
    protected $signature = 'app:housekeeping {--dry-run : Compter sans rien supprimer}';

    protected $description = 'Menage quotidien : jetons expires, anciens codes et envois, fichiers de cache perimes';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $minutes = (int) config('sanctum.expiration');
        $tokens = DB::table('personal_access_tokens')->where(fn ($q) => $q->where('expires_at', '<', now())
            ->when($minutes > 0, fn ($w) => $w->orWhere('created_at', '<', now()->subMinutes($minutes))));
        $otp = DB::table('otp_codes')->where('created_at', '<', now()->subDays(2));
        $outbox = DB::table('outbound_messages')->whereIn('status', ['sent', 'failed'])->where('updated_at', '<', now()->subDays(90));

        $counts = [
            'jetons' => $dry ? $tokens->count() : $tokens->delete(),
            'codes' => $dry ? $otp->count() : $otp->delete(),
            'envois' => $dry ? $outbox->count() : $outbox->delete(),
            'cache' => $this->pruneFileCache($dry),
        ];
        $this->info(collect($counts)->map(fn ($n, $k) => "{$k} : {$n}")->implode(' · '));

        return self::SUCCESS;
    }

    /** Fichiers du cache dont la date d'expiration (10 premiers caracteres) est passee. */
    private function pruneFileCache(bool $dry): int
    {
        if (config('cache.default') !== 'file') {
            return 0;
        }
        $root = (string) config('cache.stores.file.path');
        if (! is_dir($root)) {
            return 0;
        }
        $now = time();
        $stopAt = microtime(true) + 60; // jamais plus d'une minute (le reste au passage suivant)
        $deleted = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (microtime(true) > $stopAt) {
                break;
            }
            if (! $file->isFile() || $file->getFilename() === '.gitignore') {
                continue;
            }
            $handle = @fopen($file->getPathname(), 'r');
            $expires = $handle ? (int) fread($handle, 10) : 0;
            $handle && fclose($handle);
            // 9999999999 = sans expiration (Cache::forever) : garde.
            if ($expires > 0 && $expires < $now) {
                $deleted += $dry || @unlink($file->getPathname()) ? 1 : 0;
            }
        }

        return $deleted;
    }
}
