<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\FamilyService;
use App\Services\MembershipService;
use App\Services\Notifier;
use App\Support\Audit;
use App\Support\GemRules;
use App\Support\ImageOptimizer;
use App\Support\ProfileCompletion;
use App\Support\Recipients;
use App\Support\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'], // 5 Mo
            // Champs personnels enrichis
            'email' => ['nullable', 'string', 'max:150', 'email:rfc'],
            'facebook' => ['nullable', 'string', 'max:150'],
            'marital_status' => ['nullable', 'in:celibataire,marie,veuf,divorce,fiance,concubinage'],
            'wedding_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'wedding_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'civility' => ['nullable', 'in:dr,reverend,pasteur,m,mme,mlle'],
            'tshirt_size' => ['nullable', 'in:S,M,L,XL,XXL,XXXL,XXXXL'],
            'year_verse' => ['nullable', 'string', 'max:255'],
        ], [
            'email.email' => 'Cette adresse courriel n\'est pas valide (exemple : marie@gmail.com).',
        ]);
        // Meme forme que l'adresse ajoutee depuis le bandeau : minuscules, sans espaces.
        if (array_key_exists('email', $data)) {
            $data['email'] = filled($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        }

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
        // Inscription : la tribu est obligatoire (sauf pasteurs et administrateur, au service de toute l'eglise).
        if (! $profile->tribe_id && empty($data['tribe_id']) && Registration::requiresTribe($user)) {
            throw ValidationException::withMessages(['tribe_id' => 'Choisissez votre tribu pour terminer votre inscription.']);
        }
        $before = $profile->only(['first_name', 'last_name', 'gender', 'birth_day', 'birth_month', 'tribe_id', 'email', 'marital_status', 'wedding_day', 'wedding_month', 'civility']);

        if ($request->hasFile('photo')) {
            // Photo reduite (640 px, sans metadonnees), puis l'ancienne est supprimee.
            $data['photo_path'] = ImageOptimizer::store($request->file('photo'), 'photos', ImageOptimizer::AVATAR, 'photo');
            if ($profile->photo_path) {
                \Storage::disk('public')->delete($profile->photo_path);
            }
        }

        // Les services (departements) ne se choisissent plus ici : demande depuis « Service », validee par le responsable.
        unset($data['photo']);

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
        // Premiere inscription (jamais enregistree) ou membre deja inscrit qui complete sa tribu.
        $firstRegistration = blank($profile->matricule);
        $oldTribe = $profile->tribe_id;
        // Nouveau compte : le profil reste « non termine » jusqu'a la validation du pasteur (invisible ailleurs).
        $approved = $user->isApproved();
        $data['is_completed'] = $approved;
        $profile->fill($data)->save();
        if ((int) $oldTribe !== (int) $profile->tribe_id) {
            GemRules::afterTribeChange($profile, $user->id);
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

        // Premiere inscription terminee : ses responsables sont prevenus pour l'accueillir.
        // Membre deja inscrit qui vient de choisir sa tribu : les responsables de la tribu sont prevenus.
        if (! $approved) {
            MembershipService::submitted($user);
        } elseif (! $wasCompleted) {
            $profile->load('tribe');
            if ($firstRegistration) {
                Notifier::send(Recipients::watchersOf($user), 'member',
                    'Nouveau membre : '.$profile->full_name,
                    ($profile->tribe ? 'Tribu '.$profile->tribe->name.' · ' : '').'Pensez à lui souhaiter la bienvenue.',
                    '/admin/membres/'.$user->id, ['user_id' => $user->id]);
            } elseif ($profile->tribe) {
                Notifier::send(Recipients::watchersOf($user), 'member',
                    $profile->full_name.' a rejoint la tribu '.$profile->tribe->name,
                    'Sa tribu est maintenant renseignée dans son profil.',
                    '/admin/membres/'.$user->id, ['user_id' => $user->id]);
            }
        }

        return response()->json([
            // Etat relu : en mode d'essai local, l'inscription vient d'etre validee automatiquement.
            'message' => $approved ? 'Profil enregistré.' : ($user->fresh()->isApproved() ? 'Inscription validée (mode essai).' : 'Inscription envoyée au pasteur.'),
            'profile' => $profile->fresh()->load('tribe', 'departments'),
            'completion' => $completion,
            'membership' => ['status' => $user->fresh()->approval_status, 'submitted' => ! $approved || $user->fresh()->isApproved()],
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
