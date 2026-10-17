<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Images envoyees par les utilisateurs (photo de profil, annonces, evenements, apparence).
 *
 * L'application reduit deja l'image sur le telephone avant l'envoi (frontend/src/utils/imagePipeline.ts) ;
 * le serveur refait le travail dans tous les cas, pour les anciennes versions de l'application et pour ne
 * jamais garder un fichier lourd :
 * - orientation corrigee (photos de telephone tournees via EXIF) ;
 * - dimensions bornees (plus grand cote) ;
 * - reencodage JPEG : metadonnees retirees (position GPS, appareil...), transparence posee sur du blanc ;
 * - nom de fichier aleatoire, toujours en .jpg.
 * Sans l'extension GD (jamais le cas en production), le fichier est garde tel quel.
 */
class ImageOptimizer
{
    /** Photo de profil : carree a l'affichage, 640 px suffisent largement (affichee en 40 a 120 px). */
    public const AVATAR = 640;

    /** Image d'annonce ou d'evenement. */
    public const CONTENT = 1600;

    /** Image de fond (accueil, ecran de connexion) : grande taille et taille telephone. */
    public const BACKGROUND = 1600;

    public const BACKGROUND_SMALL = 800;

    private const QUALITY = 82;

    /**
     * Pixels au plus (30 Mpx : une photo de telephone de 24 Mpx passe ; l'application reduit de toute facon
     * a 2048 px avant l'envoi). Une image legere mais immense (« bombe de decompression », ex. PNG
     * 7000 x 7000 de 160 Ko) est refusee AVANT d'etre ouverte : son decodage demanderait des centaines de Mo.
     */
    public const MAX_PIXELS = 30_000_000;

    /** GIF garde tel quel (annonces animees) : decode par le navigateur de chaque membre, donc borne aussi. */
    private const GIF_MAX_SIDE = 4096;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * Enregistre l'image reduite sur le disque public et renvoie son chemin (dossier/aleatoire.jpg).
     *
     * @throws ValidationException image illisible (format non pris en charge)
     */
    public static function store(UploadedFile $file, string $directory, int $maxSide, string $field = 'image'): string
    {
        // Sans GD, ou GIF d'une annonce (souvent anime : l'animation serait perdue), le fichier est garde tel quel.
        if (! self::available() || ($maxSide > self::AVATAR && $file->getMimeType() === 'image/gif')) {
            $info = @getimagesize($file->getRealPath());
            if ($info && max($info[0], $info[1]) > self::GIF_MAX_SIDE) {
                throw ValidationException::withMessages([$field => 'Cette image est trop grande : '.self::GIF_MAX_SIDE.' pixels de côté au plus.']);
            }

            return $file->store($directory, 'public');
        }
        $image = self::read($file->getRealPath(), $field);
        $path = $directory.'/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, self::encode(self::fit($image, $maxSide)));

        return $path;
    }

    /**
     * Image de fond : deux tailles (ordinateur et telephone) a partir d'un seul envoi.
     *
     * @return array{large: string, small: string, large_width: int, small_width: int}
     */
    public static function storeBackground(UploadedFile $file, string $directory, string $field = 'image'): array
    {
        if (! self::available()) {
            $path = $file->store($directory, 'public');
            $width = (int) (@getimagesize($file->getRealPath())[0] ?? 1600);

            return ['large' => $path, 'small' => $path, 'large_width' => $width, 'small_width' => $width];
        }
        $image = self::read($file->getRealPath(), $field);
        $name = $directory.'/'.Str::random(32);
        $large = $name.'-1600.jpg';
        $small = $name.'-800.jpg';
        $big = self::fit($image, self::BACKGROUND);
        $little = self::fit($big, self::BACKGROUND_SMALL);
        Storage::disk('public')->put($large, self::encode($big));
        Storage::disk('public')->put($small, self::encode($little));

        return ['large' => $large, 'small' => $small, 'large_width' => imagesx($big), 'small_width' => imagesx($little)];
    }

    /**
     * Reduit une image deja enregistree sur le disque public (commande app:images-optimize) : nouveau fichier
     * .jpg a cote, l'ancien est supprime par l'appelant une fois la base mise a jour. Renvoie
     * [nouveau chemin, ancien poids, nouveau poids], ou null si rien n'est a faire (deja legere et aux bonnes
     * dimensions, GIF, illisible, ou pas plus legere une fois reencodee).
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    public static function shrinkStored(string $path, int $maxSide, bool $dryRun = false, int $minBytes = 200_000): ?array
    {
        $disk = Storage::disk('public');
        if (! self::available() || ! $disk->exists($path)) {
            return null;
        }
        $absolute = $disk->path($path);
        $size = filesize($absolute) ?: 0;
        $info = @getimagesize($absolute);
        if (! $info || $info[2] === IMAGETYPE_GIF || ($size < $minBytes && max($info[0], $info[1]) <= $maxSide)) {
            return null;
        }
        if ($dryRun) {
            return [$path, $size, 0];
        }
        try {
            $bytes = self::encode(self::fit(self::read($absolute, 'image'), $maxSide));
        } catch (ValidationException) {
            return null;
        }
        if (strlen($bytes) >= $size) {
            return null;
        }
        $new = dirname($path).'/'.Str::random(40).'.jpg';
        $disk->put($new, $bytes);

        return [$new, $size, strlen($bytes)];
    }

    /** @return \GdImage image orientee */
    private static function read(string $path, string $field): \GdImage
    {
        $info = @getimagesize($path);
        if ($info && $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$field => 'Cette image est trop grande (plus de 30 millions de pixels) : réduisez-la ou choisissez une autre photo.']);
        }
        // Memoire : l'image decodee (4 octets par pixel, plus une copie tournee) doit tenir dans ce qui reste.
        if ($info && ! self::fitsInMemory((int) $info[0] * (int) $info[1])) {
            throw ValidationException::withMessages([$field => 'Cette image est trop lourde à traiter pour le serveur : réduisez-la ou choisissez une autre photo.']);
        }
        $raw = @file_get_contents($path);
        $image = $raw === false ? false : @imagecreatefromstring($raw);
        if (! $image) {
            throw ValidationException::withMessages([$field => 'Cette image n’a pas pu être lue. Choisissez une photo JPEG, PNG ou WebP.']);
        }

        return self::orient($image, $raw);
    }

    /** Memoire restante suffisante pour decoder puis tourner une image de ce nombre de pixels ? */
    private static function fitsInMemory(int $pixels): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };

        return memory_get_usage(true) + $pixels * 10 < $bytes * 0.9;
    }

    /** Applique l'orientation EXIF (photos de telephone enregistrees « couchees »). */
    private static function orient(\GdImage $image, string $raw): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($raw, "\xFF\xD8")) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($raw));
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated && in_array($orientation, [2, 4, 5, 7], true)) {
            // Miroirs (rares) : retournement puis rotation eventuelle.
            imageflip($rotated, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            $rotated = match ($orientation) {
                5 => imagerotate($rotated, 90, 0),
                7 => imagerotate($rotated, -90, 0),
                default => $rotated,
            };
        }

        return $rotated ?: $image;
    }

    /** Reduit (jamais n'agrandit) pour que le plus grand cote tienne dans $maxSide ; fond blanc sous la transparence. */
    private static function fit(\GdImage $image, int $maxSide): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $ratio = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    private static function encode(\GdImage $image): string
    {
        imageinterlace($image, true); // JPEG progressif : un apercu s'affiche des les premiers octets
        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
