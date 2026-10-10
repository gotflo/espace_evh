<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ProfileCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adresse courriel du membre, ajoutee en un appui (bandeau du tableau de bord, preferences) : sans
 * passer par tout le profil. Elle sert aux devoirs, rappels et messages importants.
 */
class MyEmailController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:150', 'email:rfc']], [
            'email.required' => 'Indiquez votre adresse courriel.',
            'email.email' => 'Cette adresse courriel n\'est pas valide (exemple : marie@gmail.com).',
        ]);
        $profile = $request->user()->profile;
        abort_unless($profile, 409, 'Terminez d\'abord votre inscription.');
        $email = mb_strtolower(trim($data['email']));
        $before = $profile->email;
        $profile->forceFill(['email' => $email])->save();
        Audit::log('profile.updated', $profile, $request->user()->id, ['email' => $before], ['email' => $email]);
        ProfileCompletion::refresh($profile->fresh());

        return response()->json(['message' => 'Adresse enregistrée : vos rappels arriveront aussi par courriel.', 'email' => $email, 'profile' => $profile->fresh()->load('tribe', 'departments')]);
    }
}
