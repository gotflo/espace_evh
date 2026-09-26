<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\FamilyService;
use App\Support\Audit;
use App\Support\ProfileCompletion;
use Illuminate\Validation\ValidationException;
use App\Services\Notifier;
use App\Support\Recipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /** Profil du fidele connecte. */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->with('tribe', 'departments')->first();

        return response()->json([
            'profile' => $profile,
            'completion' => $profile ? ProfileCompletion::for($profile) : null,
        ]);
    }

    /** Completer / mettre a jour le profil. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'birth_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'gender' => ['required', 'in:homme,femme'],
            'tribe_id' => ['nullable', 'exists:tribes,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'], // 5 Mo
            // Champs personnels enrichis
            'email' => ['nullable', 'email', 'max:150'],
            'facebook' => ['nullable', 'string', 'max:150'],
            'marital_status' => ['nullable', 'in:celibataire,marie,veuf,divorce,fiance,concubinage'],
            'wedding_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'wedding_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'civility' => ['nullable', 'in:dr,reverend,pasteur,m,mme,mlle'],
            'tshirt_size' => ['nullable', 'in:S,M,L,XL,XXL,XXXL,XXXXL'],
            'year_verse' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $profile = $user->profile;

        // Dates coherentes (29 fevrier accepte, 31 avril refuse).
        foreach ([['birth_day', 'birth_month', 'Date de naissance invalide.'], ['wedding_day', 'wedding_month', 'Date de mariage invalide.']] as [$d, $m, $msg]) {
            if (! empty($data[$d]) && ! empty($data[$m]) && ! checkdate((int) $data[$m], (int) $data[$d], 2024)) {
                throw ValidationException::withMessages([$d => $msg]);
            }
        }
        // La date de mariage ne concerne que les personnes mariees.
        if (($data['marital_status'] ?? $profile->marital_status) !== 'marie') {
            $data['wedding_day'] = null;
            $data['wedding_month'] = null;
        }

        // La tribu ne se change plus librement : apres le premier choix, une demande est necessaire.
        if (array_key_exists('tribe_id', $data)) {
            $requested = $data['tribe_id'] ? (int) $data['tribe_id'] : null;
            if ($profile->tribe_id && $requested !== (int) $profile->tribe_id) {
                throw ValidationException::withMessages([
                    'tribe_id' => 'Pour changer de tribu, faites une demande de changement : vos responsables la valideront.',
                ]);
            }
            if (! $profile->tribe_id && ! $requested) {
                unset($data['tribe_id']);
            }
        }
        $before = $profile->only(['first_name', 'last_name', 'gender', 'birth_day', 'birth_month', 'tribe_id', 'email', 'marital_status', 'wedding_day', 'wedding_month', 'civility']);

        if ($request->hasFile('photo')) {
            // Supprime l'ancienne photo si presente.
            if ($profile->photo_path) {
                \Storage::disk('public')->delete($profile->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        }

        $departmentIds = array_key_exists('department_ids', $data) ? ($data['department_ids'] ?? []) : null;
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

        $wasCompleted = (bool) $profile->is_completed;
        $oldTribe = $profile->tribe_id;
        $data['is_completed'] = true;
        $profile->fill($data)->save();
        $changes = $departmentIds !== null ? $profile->departments()->sync($departmentIds) : ['attached' => []];
        if ((int) $oldTribe !== (int) $profile->tribe_id) {
            \App\Support\GemRules::afterTribeChange($profile, $user->id);
        }
        // Plus marie(e) : le lien conjugal est retire des deux cotes.
        if (($before['marital_status'] ?? null) === 'marie' && $profile->marital_status !== 'marie') {
            FamilyService::unlinkSpouse($user);
        }
        [$old, $new] = Audit::diff($before, $profile->only(array_keys($before)));
        if ($new) {
            Audit::log('profile.updated', $profile, $user->id, $old, $new);
        }
        $completion = ProfileCompletion::for($profile->fresh());
        ProfileCompletion::refresh($profile->fresh());

        // Nouveau(x) departement(s) choisi(s) depuis le profil : ses responsables sont prevenus.
        if ($wasCompleted && ! empty($changes['attached'])) {
            foreach (\App\Models\Department::whereIn('id', $changes['attached'])->get() as $dept) {
                Notifier::send(array_diff(Recipients::departmentLeaders($dept), [$user->id]), 'service',
                    'Nouveau serviteur : '.$profile->full_name,
                    $profile->full_name." a rejoint le service {$dept->name}.", '/admin/membres/'.$user->id);
            }
        }

        // Premiere inscription terminee : ses responsables sont prevenus pour l'accueillir.
        if (! $wasCompleted) {
            $profile->load('tribe');
            Notifier::send(Recipients::watchersOf($user), 'member',
                'Nouveau membre : '.$profile->full_name,
                ($profile->tribe ? 'Tribu '.$profile->tribe->name.' · ' : '').'Pensez à lui souhaiter la bienvenue.',
                '/admin/membres/'.$user->id, ['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Profil enregistré.',
            'profile' => $profile->fresh()->load('tribe', 'departments'),
            'completion' => $completion,
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
