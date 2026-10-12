<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Apparence de la plateforme, modifiable sans code (permission settings.manage) :
 * - image de l'accueil (fond du verset, aussi sur l'ecran d'attente d'inscription) et son cadrage ;
 * - photos de l'ecran de connexion (ajout, retrait, ordre, cadrage).
 *
 * Stockee dans platform_settings (cle « appearance ») : le pouls « settings » change a chaque modification,
 * les ecrans ouverts se mettent donc a jour seuls. Sans rien d'enregistre, les images d'origine s'affichent.
 * Les fichiers remplaces ou retires sont supprimes du disque (jamais les images d'origine, servies par le site).
 */
class Appearance
{
    public const KEY = 'appearance';

    public const CACHE_KEY = 'platform:appearance';

    public const MAX_LOGIN_PHOTOS = 8;

    private const DIRECTORY = 'appearance';

    /** Image de l'accueil d'origine (frontend/public/verset). */
    public const DEFAULT_HOME = ['id' => 'origine', 'small' => '/verset/fond-800.jpg', 'large' => '/verset/fond-1600.jpg', 'x' => 62, 'y' => 42, 'builtin' => true];

    /** Photos d'origine de l'ecran de connexion (frontend/public/login). */
    public const DEFAULT_LOGIN = [
        ['id' => 'bible', 'x' => 50, 'y' => 40],
        ['id' => 'louange', 'x' => 50, 'y' => 30],
        ['id' => 'table', 'x' => 50, 'y' => 55],
        ['id' => 'fete', 'x' => 50, 'y' => 35],
    ];

    /** @return array{home: array<string, mixed>|null, login: list<array<string, mixed>>|null} valeurs enregistrees (null = origine) */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $raw = Schema::hasTable('platform_settings') ? DB::table('platform_settings')->where('key', self::KEY)->value('value') : null;
            $data = $raw ? json_decode((string) $raw, true) : [];

            return ['home' => $data['home'] ?? null, 'login' => $data['login'] ?? null];
        });
    }

    /** @return list<array<string, mixed>> photos d'origine de l'ecran de connexion */
    public static function defaultLogin(): array
    {
        return array_map(fn ($p) => $p + ['small' => "/login/{$p['id']}-800.jpg", 'large' => "/login/{$p['id']}-1600.jpg", 'builtin' => true], self::DEFAULT_LOGIN);
    }

    /** @return array<string, mixed> */
    public static function home(): array
    {
        return self::stored()['home'] ?? self::DEFAULT_HOME;
    }

    /** @return list<array<string, mixed>> */
    public static function login(): array
    {
        return self::stored()['login'] ?? self::defaultLogin();
    }

    /**
     * Ce que l'application affiche (public : ecran de connexion compris). Aucune donnee personnelle.
     *
     * @return array<string, mixed>
     */
    public static function client(): array
    {
        // Largeurs reelles (photo en hauteur : la grande taille fait moins de 1600 px de large) pour srcset.
        $image = fn (array $i) => ['id' => $i['id'], 'small' => $i['small'], 'large' => $i['large'], 'position' => "{$i['x']}% {$i['y']}%",
            'widths' => [(int) ($i['small_width'] ?? 800), (int) ($i['large_width'] ?? 1600)]];

        return [
            'home' => $image(self::home()),
            'login' => array_map($image, self::login()),
            'texts' => [
                'login_notice' => (string) PlatformSettings::get('texts.login_notice'),
                'welcome_title' => (string) PlatformSettings::get('texts.welcome_title'),
                'welcome_message' => (string) PlatformSettings::get('texts.welcome_message'),
                'pending_message' => (string) PlatformSettings::get('texts.pending_message'),
            ],
        ];
    }

    /** Ecran d'administration : valeurs actuelles et etat « modifie ». @return array<string, mixed> */
    public static function present(): array
    {
        $stored = self::stored();
        $image = fn (array $i) => ['id' => $i['id'], 'small' => $i['small'], 'large' => $i['large'], 'x' => $i['x'], 'y' => $i['y'], 'builtin' => (bool) ($i['builtin'] ?? false)];

        return [
            'home' => $image(self::home()),
            'home_changed' => $stored['home'] !== null,
            'login' => array_map($image, self::login()),
            'login_changed' => $stored['login'] !== null,
            'max_login_photos' => self::MAX_LOGIN_PHOTOS,
        ];
    }

    /** Nouvelle image de l'accueil (l'ancienne image envoyee est supprimee). */
    public static function setHomeImage(UploadedFile $file, int $x, int $y, ?User $actor): void
    {
        $paths = ImageOptimizer::storeBackground($file, self::DIRECTORY);
        $old = self::stored()['home'];
        self::save(['home' => self::item($paths, $x, $y)] + self::stored(), $actor);
        self::deleteFiles($old);
    }

    /** Recadrage de l'image de l'accueil (sans nouvel envoi). */
    public static function setHomePosition(int $x, int $y, ?User $actor): void
    {
        $home = self::home();
        $data = self::stored();
        $data['home'] = ['x' => $x, 'y' => $y] + $home;
        self::save($data, $actor);
    }

    /** Retour a l'image d'origine. */
    public static function resetHome(?User $actor): void
    {
        $old = self::stored()['home'];
        self::save(['home' => null] + self::stored(), $actor);
        self::deleteFiles($old);
    }

    /** Ajoute une photo a la fin du defilement de l'ecran de connexion. */
    public static function addLoginPhoto(UploadedFile $file, int $x, int $y, ?User $actor): void
    {
        $list = self::login();
        if (count($list) >= self::MAX_LOGIN_PHOTOS) {
            throw ValidationException::withMessages(['image' => 'L’écran de connexion compte déjà '.self::MAX_LOGIN_PHOTOS.' photos : retirez-en une avant d’en ajouter.']);
        }
        $paths = ImageOptimizer::storeBackground($file, self::DIRECTORY);
        $list[] = self::item($paths, $x, $y);
        self::save(['login' => $list] + self::stored(), $actor);
    }

    /**
     * Ordre, cadrage et retrait des photos de l'ecran de connexion : $items = [{id, x, y}] dans l'ordre voulu ;
     * une photo absente de la liste est retiree (son fichier supprime s'il avait ete envoye).
     *
     * @param  list<array{id: string, x: int, y: int}>  $items
     */
    public static function arrangeLogin(array $items, ?User $actor): void
    {
        $current = [];
        foreach (self::login() as $photo) {
            $current[$photo['id']] = $photo;
        }
        $next = [];
        foreach ($items as $item) {
            $photo = $current[$item['id']] ?? null;
            if (! $photo) {
                throw ValidationException::withMessages(['items' => 'Une des photos n’existe plus : rechargez la page.']);
            }
            $next[$photo['id']] = ['x' => (int) $item['x'], 'y' => (int) $item['y']] + $photo;
        }
        if (! $next) {
            throw ValidationException::withMessages(['items' => 'Gardez au moins une photo sur l’écran de connexion.']);
        }
        self::save(['login' => array_values($next)] + self::stored(), $actor);
        foreach (array_diff_key($current, $next) as $removed) {
            self::deleteFiles($removed);
        }
    }

    /** Retour aux photos d'origine (les photos envoyees sont supprimees). */
    public static function resetLogin(?User $actor): void
    {
        $old = self::stored()['login'] ?? [];
        self::save(['login' => null] + self::stored(), $actor);
        foreach ($old as $photo) {
            self::deleteFiles($photo);
        }
    }

    /** @param  array{large: string, small: string, large_width: int, small_width: int}  $paths */
    private static function item(array $paths, int $x, int $y): array
    {
        return ['id' => Str::lower(Str::random(12)), 'small' => '/storage/'.$paths['small'], 'large' => '/storage/'.$paths['large'],
            'small_width' => $paths['small_width'], 'large_width' => $paths['large_width'], 'x' => $x, 'y' => $y];
    }

    private static function save(array $data, ?User $actor): void
    {
        $data = ['home' => $data['home'] ?? null, 'login' => $data['login'] ?? null];
        if ($data['home'] === null && $data['login'] === null) {
            DB::table('platform_settings')->where('key', self::KEY)->delete();
        } else {
            DB::table('platform_settings')->updateOrInsert(['key' => self::KEY], [
                'value' => json_encode($data, JSON_UNESCAPED_SLASHES), 'updated_by' => $actor?->id, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        Cache::forget(self::CACHE_KEY);
        Cache::forget(PlatformSettings::CACHE_KEY);
    }

    /** Supprime les fichiers d'une image envoyee (jamais les images d'origine du site). */
    private static function deleteFiles(?array $image): void
    {
        if (! $image || ! empty($image['builtin'])) {
            return;
        }
        foreach (['small', 'large'] as $size) {
            $url = (string) ($image[$size] ?? '');
            if (str_starts_with($url, '/storage/'.self::DIRECTORY.'/')) {
                Storage::disk('public')->delete(substr($url, strlen('/storage/')));
            }
        }
    }
}
