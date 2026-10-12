<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Appearance;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Apparence de la plateforme (permission settings.manage) : image de l'accueil et photos de l'ecran de
 * connexion. L'image arrive deja reduite par l'application ; le serveur la reduit encore et la decline en
 * deux tailles (ImageOptimizer). Chaque modification est journalisee.
 */
class AppearanceController extends Controller
{
    /** Regles d'une image envoyee. */
    private const IMAGE = ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192', 'dimensions:min_width=400,min_height=200'];

    /** Cadrage : point de l'image garde visible, en % (0 a 100) horizontalement et verticalement. */
    private const POSITION = ['required', 'integer', 'min:0', 'max:100'];

    private const MESSAGES = [
        'image.dimensions' => 'Cette image est trop petite : choisissez une photo d’au moins 400 × 200 pixels.',
        'image.max' => 'Cette image est trop lourde (8 Mo au plus).',
        'image.mimes' => 'Choisissez une photo JPEG, PNG ou WebP.',
        'image.image' => 'Choisissez une photo JPEG, PNG ou WebP.',
    ];

    public function index(): JsonResponse
    {
        return response()->json(Appearance::present());
    }

    public function uploadHome(Request $request): JsonResponse
    {
        $data = $request->validate(['image' => self::IMAGE, 'x' => self::POSITION, 'y' => self::POSITION], self::MESSAGES);
        Appearance::setHomeImage($request->file('image'), (int) $data['x'], (int) $data['y'], $request->user());
        Audit::log('appearance.home_image', null, null, [], ['image' => 'nouvelle', 'cadrage' => "{$data['x']}% {$data['y']}%"]);

        return $this->done('L’image de l’accueil est en place.');
    }

    public function positionHome(Request $request): JsonResponse
    {
        $data = $request->validate(['x' => self::POSITION, 'y' => self::POSITION]);
        $before = Appearance::home();
        Appearance::setHomePosition((int) $data['x'], (int) $data['y'], $request->user());
        Audit::log('appearance.home_position', null, null, ['cadrage' => "{$before['x']}% {$before['y']}%"], ['cadrage' => "{$data['x']}% {$data['y']}%"]);

        return $this->done('Le cadrage est enregistré.');
    }

    public function resetHome(Request $request): JsonResponse
    {
        Appearance::resetHome($request->user());
        Audit::log('appearance.home_reset', null, null, [], ['image' => 'origine']);

        return $this->done('L’image d’origine est rétablie.');
    }

    public function addLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['image' => self::IMAGE, 'x' => self::POSITION, 'y' => self::POSITION], self::MESSAGES);
        Appearance::addLoginPhoto($request->file('image'), (int) $data['x'], (int) $data['y'], $request->user());
        Audit::log('appearance.login_added', null, null, [], ['photos' => count(Appearance::login())]);

        return $this->done('La photo est ajoutée à l’écran de connexion.');
    }

    public function arrangeLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.Appearance::MAX_LOGIN_PHOTOS],
            'items.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'items.*.x' => self::POSITION,
            'items.*.y' => self::POSITION,
        ], ['items.required' => 'Gardez au moins une photo sur l’écran de connexion.', 'items.min' => 'Gardez au moins une photo sur l’écran de connexion.']);
        $before = count(Appearance::login());
        Appearance::arrangeLogin($data['items'], $request->user());
        Audit::log('appearance.login_arranged', null, null, ['photos' => $before], ['photos' => count($data['items'])]);

        return $this->done('L’écran de connexion est enregistré.');
    }

    public function resetLogin(Request $request): JsonResponse
    {
        Appearance::resetLogin($request->user());
        Audit::log('appearance.login_reset', null, null, [], ['photos' => 'origine']);

        return $this->done('Les photos d’origine sont rétablies.');
    }

    private function done(string $message): JsonResponse
    {
        return response()->json(['message' => $message] + Appearance::present());
    }
}
