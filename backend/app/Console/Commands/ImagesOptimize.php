<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reduit les images deja enregistrees (photos de profil, annonces, evenements) avec les memes regles que
 * les nouveaux envois : seules les images lourdes ou trop grandes sont reecrites (nouveau fichier .jpg,
 * chemin mis a jour en base sans toucher a la date de modification, ancien fichier supprime ensuite).
 * --dry-run : affiche seulement ce qui serait reduit.
 */
class ImagesOptimize extends Command
{
    protected $signature = 'app:images-optimize {--dry-run : Compter sans rien modifier}';

    protected $description = 'Réduit les photos de profil et les images d’annonces ou d’événements trop lourdes';

    /** table => [colonne, plus grand cote]. */
    private const SOURCES = [
        'profiles' => ['photo_path', ImageOptimizer::AVATAR],
        'announcements' => ['image_path', ImageOptimizer::CONTENT],
        'events' => ['image_path', ImageOptimizer::CONTENT],
    ];

    public function handle(): int
    {
        if (! ImageOptimizer::available()) {
            $this->error('Extension GD indisponible : rien n’est fait.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $before = 0;
        $after = 0;
        $count = 0;
        foreach (self::SOURCES as $table => [$column, $max]) {
            $rows = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->get(['id', $column]);
            foreach ($rows as $row) {
                $old = (string) $row->{$column};
                $result = ImageOptimizer::shrinkStored($old, $max, $dry);
                if (! $result) {
                    continue;
                }
                [$new, $oldSize, $newSize] = $result;
                $count++;
                $before += $oldSize;
                $after += $newSize;
                if ($dry) {
                    continue;
                }
                // Mise a jour du seul chemin (meme ligne, date de modification inchangee), puis suppression.
                $updated = DB::table($table)->where('id', $row->id)->where($column, $old)->update([$column => $new]);
                Storage::disk('public')->delete($updated ? $old : $new);
            }
        }
        $mb = fn (int $b) => number_format($b / 1048576, 1, ',', ' ').' Mo';
        $this->info($dry
            ? "{$count} image(s) à réduire ({$mb($before)})."
            : "{$count} image(s) réduite(s) : {$mb($before)} → {$mb($after)}.");

        return self::SUCCESS;
    }
}
