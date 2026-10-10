<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalTestMode;
use App\Support\ProfileCompletion;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Validation des nouveaux comptes par le pasteur.
 *
 * Un nouveau compte remplit son profil, puis attend : il voit un accueil (verset du jour) et peut laisser son
 * adresse courriel. Le pasteur (PR, ou un role avec members.approve) recoit le profil complet par courriel avec
 * « Valider » et « Rejeter » (un appui suffit), et dans Validations. La decision s'applique aussitot au compte et
 * la personne est prevenue (courriel, notification). Tant qu'il n'est pas valide, le profil reste « non termine »
 * (is_completed = false) : le compte n'apparait dans aucune liste, statistique, audience ni relance.
 */
class MembershipService
{
    /** Validite des boutons du courriel (jours). */
    public const MAIL_LINK_DAYS = 30;

    /** @return array<int> PR et roles ayant members.approve. */
    public static function approverIds(): array
    {
        $superAdmin = Role::where('key', User::SUPER_ADMIN)->value('id');
        $byPermission = DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.key', 'members.approve')->pluck('role_user.user_id');

        return DB::table('role_user')->where('role_id', $superAdmin)->pluck('user_id')->merge($byPermission)
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public static function canApprove(User $user): bool
    {
        return $user->hasPermission('members.approve');
    }

    /** Profil envoye : le pasteur est prevenu (une seule fois par inscription). */
    public static function submitted(User $member): void
    {
        $claimed = User::where('id', $member->id)->where('approval_status', User::PENDING)->whereNull('approval_requested_at')
            ->update(['approval_requested_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $member->refresh()->load('profile.tribe', 'profile.departments');
        $name = $member->profile?->full_name ?: $member->phone;
        Audit::log('member.signup_requested', $member->profile, $member->id, [], ['tribe' => $member->profile?->tribe?->name], [], $member);
        Notifier::send(self::approverIds(), 'member', 'Nouvelle inscription : '.$name,
            ($member->profile?->tribe ? 'Tribu '.$member->profile->tribe->name.' · ' : '').'En attente de votre validation.',
            '/admin/validations', ['user_id' => $member->id], 'high');
        self::mailApprovers($member);

        // Essais locaux : validee aussitot (meme traitement que la validation du pasteur).
        if (LocalTestMode::active()) {
            self::approveForLocalTests($member);
        }
    }

    /**
     * Mode d'essai local seulement : validation automatique, par le premier pasteur, avec exactement les
     * memes effets que sa validation (profil termine, responsables prevenus, courriel de bienvenue).
     */
    public static function approveForLocalTests(User $member): void
    {
        if (! LocalTestMode::active()) {
            return;
        }
        $pastor = User::whereIn('id', self::approverIds())->get()->first(fn (User $u) => self::canApprove($u));
        if ($pastor) {
            self::decide($pastor, $member->fresh(), true);
        }
    }

    /** Courriel au(x) pasteur(s) : profil complet, boutons « Valider » et « Rejeter ». */
    public static function mailApprovers(User $member): int
    {
        $queued = 0;
        foreach (User::with('profile')->whereIn('id', self::approverIds())->get() as $pastor) {
            $email = Messenger::emailOf($pastor);
            if (! $email) {
                continue;
            }
            $link = fn (string $decision) => URL::temporarySignedRoute('email.membership', now()->addDays(self::MAIL_LINK_DAYS),
                ['member' => $member->id, 'decision' => $decision, 'pastor' => $pastor->id]);
            $name = $member->profile?->full_name ?: (string) $member->phone;
            $queued += Messenger::queueEmail($email, $pastor->id, 'membership_request', 'Nouveau membre à valider : '.$name,
                'membership-request', [
                    'pastorName' => $pastor->profile?->full_name ?: '',
                    'memberName' => $name,
                    'details' => self::details($member),
                    'photoUrl' => $member->profile?->photo_path ? rtrim((string) config('app.url'), '/').$member->profile->photo_url : null,
                    'initials' => mb_strtoupper(mb_substr((string) $member->profile?->first_name, 0, 1).mb_substr((string) $member->profile?->last_name, 0, 1)),
                    'approveUrl' => $link('approve'),
                    'rejectUrl' => $link('reject'),
                    'days' => self::MAIL_LINK_DAYS,
                ], "membership-request:{$member->id}:{$pastor->id}") ? 1 : 0;
        }

        return $queued;
    }

    /**
     * Decision du pasteur (application ou courriel). Une demande en attente est validee ou rejetee ; un compte
     * rejete peut encore etre valide depuis l'application (erreur corrigee). Jamais deux fois la meme decision.
     */
    public static function decide(User $by, User $member, bool $approve): void
    {
        abort_unless(self::canApprove($by), 403, 'Seul le pasteur valide les nouvelles inscriptions.');
        $from = $approve ? [User::PENDING, User::REJECTED] : [User::PENDING];
        abort_if($member->approval_requested_at === null && $member->approval_status === User::PENDING, 409, 'Cette personne n\'a pas encore envoyé son profil.');
        $now = now();
        $claimed = User::where('id', $member->id)->whereIn('approval_status', $from)->update([
            'approval_status' => $approve ? User::APPROVED : User::REJECTED,
            'approval_decided_at' => $now, 'approval_decided_by' => $by->id, 'updated_at' => $now,
        ]);
        abort_unless($claimed === 1, 409, 'Cette demande a déjà été traitée.');
        $member->refresh()->load('profile.tribe');
        $profile = $member->profile;
        $name = $profile?->full_name ?: $member->phone;

        if ($approve) {
            // Profil « termine » : le membre apparait chez ses responsables et recoit ce qui lui est adresse.
            $profile?->forceFill(['is_completed' => true])->save();
            if ($profile) {
                ProfileCompletion::refresh($profile->fresh());
            }
            Audit::log('member.approved', $profile, $member->id, ['approval_status' => 'pending'], ['approval_status' => 'approved'], [], $by);
            Notifier::send(array_diff(Recipients::watchersOf($member), [$by->id]), 'member', 'Nouveau membre : '.$name,
                ($profile?->tribe ? 'Tribu '.$profile->tribe->name.' · ' : '').'Pensez à lui souhaiter la bienvenue.',
                '/admin/membres/'.$member->id, ['user_id' => $member->id]);
            Notifier::send([$member->id], 'system', 'Votre compte est validé',
                'Bienvenue sur My vasesdhonneur ! Votre espace est ouvert.', '/tableau-de-bord', [], 'high');
        } else {
            Audit::log('member.rejected', $profile, $member->id, ['approval_status' => 'pending'], ['approval_status' => 'rejected'], [], $by);
        }
        // Les autres pasteurs ne voient plus la demande ; la personne recoit un courriel du resultat.
        self::mailDecision($member, $approve);
    }

    private static function mailDecision(User $member, bool $approve): void
    {
        $email = Messenger::emailOf($member);
        if (! $email) {
            return;
        }
        $url = rtrim((string) config('app.url'), '/').($approve ? '/tableau-de-bord' : '/');
        Messenger::queueEmail($email, $member->id, $approve ? 'membership_approved' : 'membership_rejected',
            $approve ? 'Votre compte My vasesdhonneur est validé' : 'Votre demande d\'inscription à My vasesdhonneur',
            $approve ? 'membership-approved' : 'membership-rejected',
            ['firstName' => $member->profile?->first_name ?: '', 'url' => $url],
            "membership-decision:{$member->id}:".($approve ? 'approved' : 'rejected').':'.$member->approval_decided_at?->timestamp);
    }

    /**
     * Profil complet, tel que montre au pasteur (courriel et application).
     *
     * @return list<array{label: string, value: string}>
     */
    public static function details(User $member): array
    {
        $p = $member->profile;
        $p?->loadMissing('tribe', 'departments', 'gem');
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $date = fn (?int $d, ?int $m) => $d && $m ? $d.($d === 1 ? 'er' : '').' '.$months[$m - 1] : null;
        $rows = [
            'Prénoms' => $p?->first_name,
            'Nom' => $p?->last_name,
            'Genre' => ['homme' => 'Homme', 'femme' => 'Femme'][$p?->gender] ?? null,
            'Anniversaire' => $date($p?->birth_day, $p?->birth_month),
            'Téléphone' => $member->phone,
            'Courriel' => Messenger::emailOf($member),
            'Tribu' => $p?->tribe?->name,
            'GEM' => $p?->gem?->name,
            'Départements' => $p && $p->departments->isNotEmpty() ? $p->departments->pluck('name')->implode(', ') : null,
            'État civil' => ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'veuf' => 'Veuf / veuve', 'divorce' => 'Divorcé(e)',
                'fiance' => 'Fiancé(e)', 'concubinage' => 'En concubinage'][$p?->marital_status] ?? null,
            'Mariage' => $date($p?->wedding_day, $p?->wedding_month),
            'Verset de l\'année' => $p?->year_verse,
            'Facebook' => $p?->facebook,
            'Matricule' => $p?->matricule,
            'Demande envoyée' => $member->approval_requested_at ? self::when($member->approval_requested_at) : null,
        ];

        return collect($rows)->filter(fn ($v) => filled($v))->map(fn ($v, $k) => ['label' => $k, 'value' => (string) $v])->values()->all();
    }

    /** « jeudi 9 octobre à 14h05 » */
    public static function when(Carbon $at): string
    {
        return $at->copy()->locale('fr')->isoFormat('dddd D MMMM [à] H[h]mm');
    }

    /** Demande telle que listee dans Validations. */
    public static function present(User $member): array
    {
        $p = $member->profile;

        return [
            'user_id' => $member->id,
            'name' => $p?->full_name ?: $member->phone,
            'first_name' => $p?->first_name,
            'last_name' => $p?->last_name,
            'photo_url' => $p?->photo_url,
            'tribe' => $p?->tribe?->name,
            'status' => $member->approval_status,
            'details' => self::details($member),
            'requested_at' => $member->approval_requested_at?->toIso8601String(),
            'decided_at' => $member->approval_decided_at?->toIso8601String(),
        ];
    }
}
