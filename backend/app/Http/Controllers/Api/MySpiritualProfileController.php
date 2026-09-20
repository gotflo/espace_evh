<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpiritualProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MySpiritualProfileController extends Controller
{
    /** Profil spirituel du fidele connecte (cree vide si inexistant). */
    public function show(Request $request): JsonResponse
    {
        $sp = SpiritualProfile::firstOrCreate(['user_id' => $request->user()->id]);

        return response()->json(['spiritual' => $sp]);
    }

    /** Le fidele met a jour son profil spirituel. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversion_year' => ['nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'conversion_verse' => ['nullable', 'string', 'max:255'],
            'baptism_immersion_date' => ['nullable', 'date', 'before_or_equal:today'],
            'baptism_holy_spirit' => ['nullable', 'in:oui,non,je_ne_sais_pas,autre'],
            'speaks_tongues' => ['nullable', 'boolean'],
            'tongues_since_year' => ['nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'active_member' => ['nullable', 'boolean'],
            'prayer_frequency' => ['nullable', 'in:quotidien,hebdomadaire,rare'],
            'gifts_known' => ['nullable', 'boolean'],
            'gifts_detail' => ['nullable', 'string', 'max:2000'],
            'last_prayer_subject' => ['nullable', 'string', 'max:2000'],
            'joyful_service' => ['nullable', 'string', 'max:2000'],
            'focus_effort' => ['nullable', 'string', 'max:2000'],
        ]);

        $sp = SpiritualProfile::firstOrCreate(['user_id' => $request->user()->id]);
        $sp->fill($data)->save();

        return response()->json(['message' => 'Profil spirituel enregistre.', 'spiritual' => $sp->fresh()]);
    }
}
