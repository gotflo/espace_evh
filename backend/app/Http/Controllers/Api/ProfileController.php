<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /** Profil du fidele connecte. */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->with('tribe', 'departments')->first();

        return response()->json(['profile' => $profile]);
    }

    /** Completer / mettre a jour le profil. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'birth_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'gender' => ['nullable', 'in:homme,femme,autre'],
            'tribe_id' => ['nullable', 'exists:tribes,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'], // 5 Mo
            // Champs personnels enrichis
            'email' => ['nullable', 'email', 'max:150'],
            'facebook' => ['nullable', 'string', 'max:150'],
            'marital_status' => ['nullable', 'in:celibataire,marie,veuf,divorce,fiance,concubinage'],
            'civility' => ['nullable', 'in:dr,reverend,pasteur,m,mme,mlle'],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'tshirt_size' => ['nullable', 'in:S,M,L,XL,XXL,XXXL,XXXXL'],
            'year_verse' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $profile = $user->profile;

        if ($request->hasFile('photo')) {
            // Supprime l'ancienne photo si presente.
            if ($profile->photo_path) {
                \Storage::disk('public')->delete($profile->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        }

        $departmentIds = $data['department_ids'] ?? [];
        unset($data['photo'], $data['department_ids']);

        // Matricule : VHC + 1re lettre Nom + 1re lettre Prenom + jour + mois de naissance (JJMM).
        // Ex. Jean Fidele ne un 15/05 -> VHCFJ1505.
        $day = $data['birth_day'] ?? $profile->birth_day;
        $month = $data['birth_month'] ?? $profile->birth_month;
        $base = $this->matriculeBase($data['last_name'], $data['first_name'], $day, $month);
        $storedBase = preg_replace('/-\d+$/', '', (string) $profile->matricule);
        if ($storedBase !== $base) {
            $data['matricule'] = $this->uniqueMatricule($base, $user->id);
        }

        $data['is_completed'] = true;
        $profile->fill($data)->save();
        $profile->departments()->sync($departmentIds);

        return response()->json([
            'message' => 'Profil enregistre.',
            'profile' => $profile->fresh()->load('tribe', 'departments'),
        ]);
    }

    /** Base du matricule : VHC + 1re lettre Nom + 1re lettre Prenom + JJMM de naissance. */
    private function matriculeBase(?string $lastName, ?string $firstName, ?int $day, ?int $month): string
    {
        $letter = fn (?string $s) => strtoupper(mb_substr(trim((string) $s), 0, 1)) ?: 'X';
        $dd = $day ? str_pad((string) (int) $day, 2, '0', STR_PAD_LEFT) : '00';
        $mm = $month ? str_pad((string) (int) $month, 2, '0', STR_PAD_LEFT) : '00';

        return 'VHC'.$letter($lastName).$letter($firstName).$dd.$mm;
    }

    /** Rend le matricule unique en ajoutant un suffixe si necessaire. */
    private function uniqueMatricule(string $base, int $userId): string
    {
        $matricule = $base;
        $i = 2;
        while (Profile::where('matricule', $matricule)->where('user_id', '!=', $userId)->exists()) {
            $matricule = $base.'-'.$i;
            $i++;
        }

        return $matricule;
    }
}
