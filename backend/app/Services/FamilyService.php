<?php

namespace App\Services;

use App\Models\FamilyLink;
use App\Models\Profile;
use App\Models\User;
use App\Support\Audit;
use App\Support\ProfileCompletion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Famille : conjoint(e) et enfants.
 *
 * Conjoint inscrit : le membre CHOISIT la personne (jamais de lien cree sur une simple
 * ressemblance de nom). Le lien reste « en attente » jusqu'a ce que l'autre personne le
 * confirme ; il devient alors bidirectionnel (A -> B et B -> A). Si les deux se designent
 * mutuellement, la confirmation est immediate.
 * Conjoint non inscrit : son nom est garde ; a son inscription, une suggestion est proposee
 * (sans lien automatique).
 * Enfants : nom + annee de naissance ; lien vers un profil existant sur confirmation.
 */
class FamilyService
{
    /** Recherche de membres pour designer un conjoint / un enfant (sans coordonnees personnelles). */
    public static function search(User $me, string $q): Collection
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return collect();
        }
        $terms = array_filter(preg_split('/\s+/', mb_strtolower($q)));

        return Profile::where('is_completed', true)->where('user_id', '!=', $me->id)
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $w->where(fn ($x) => $x->whereRaw('LOWER(first_name) LIKE ?', ["%{$t}%"])->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$t}%"]));
                }
            })
            ->with('tribe:id,name')->orderBy('first_name')->limit(10)->get()
            ->map(fn (Profile $p) => [
                'user_id' => $p->user_id,
                'full_name' => $p->full_name,
                'tribe' => $p->tribe?->name,
                'photo_url' => $p->photo_url,
                // Aide a distinguer les homonymes sans exposer de donnees sensibles.
                'birth_month' => $p->birth_month,
                'has_spouse' => FamilyLink::where('user_id', $p->user_id)->where('relation', 'spouse')->where('status', 'confirmed')->exists(),
            ]);
    }

    /**
     * Designe le conjoint : membre inscrit (spouseUserId) ou simple nom.
     * Null + null => aucun conjoint (relation retiree des deux cotes).
     */
    public static function setSpouse(User $me, ?int $spouseUserId, ?string $name): void
    {
        DB::transaction(function () use ($me, $spouseUserId, $name) {
            $current = FamilyLink::where('user_id', $me->id)->where('relation', 'spouse')->first();
            if ($current && $spouseUserId && (int) $current->relative_user_id === $spouseUserId) {
                return; // inchange
            }
            if ($current) {
                self::unlinkSpouse($me);
            }

            $profile = $me->profile;
            if ($spouseUserId) {
                abort_if($spouseUserId === $me->id, 422, 'Vous ne pouvez pas vous désigner vous-même.');
                $other = User::with('profile')->find($spouseUserId);
                abort_unless($other?->profile, 422, 'Personne introuvable.');
                $otherSpouse = FamilyLink::where('user_id', $spouseUserId)->where('relation', 'spouse')->where('status', 'confirmed')->first();
                abort_if($otherSpouse && (int) $otherSpouse->relative_user_id !== $me->id, 422,
                    'Cette personne est déjà liée à un(e) conjoint(e). Vérifiez votre choix ou contactez un responsable.');

                // L'autre personne m'a deja designe : confirmation mutuelle immediate.
                $reverse = FamilyLink::where('user_id', $spouseUserId)->where('relation', 'spouse')
                    ->where('relative_user_id', $me->id)->first();
                $status = $reverse ? 'confirmed' : 'pending';
                FamilyLink::create([
                    'user_id' => $me->id, 'relation' => 'spouse', 'relative_user_id' => $spouseUserId,
                    'relative_name' => $other->profile->full_name, 'status' => $status,
                    'confirmed_at' => $reverse ? now() : null,
                ]);
                if ($reverse) {
                    $reverse->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
                }
                $profile->forceFill(['spouse_name' => $other->profile->full_name])->save();
                Audit::log('family.spouse_designated', $me, $me->id, [], ['spouse_user_id' => $spouseUserId, 'status' => $status]);

                if ($status === 'pending') {
                    Notifier::send([$spouseUserId], 'family', ($profile->full_name ?: 'Un membre').' vous a indiqué comme conjoint(e)',
                        'Confirmez ce lien dans votre profil (ou refusez-le s\'il s\'agit d\'une erreur).', '/mon-profil#famille');
                } else {
                    self::syncMarriage($me, $other);
                    Notifier::send([$spouseUserId], 'family', 'Lien conjugal confirmé', ($profile->full_name).' et vous êtes désormais liés comme conjoints.', '/mon-profil#famille');
                }
            } else {
                $profile->forceFill(['spouse_name' => $name ? mb_substr(trim($name), 0, 150) : null])->save();
                Audit::log('family.spouse_name_set', $me, $me->id, [], ['spouse_name' => $profile->spouse_name]);
            }
            ProfileCompletion::refresh($profile->fresh());
        });
    }

    /** La personne designee confirme (conjoint ou parent d'un enfant inscrit). */
    public static function confirm(User $me, FamilyLink $link): void
    {
        abort_unless((int) $link->relative_user_id === $me->id && $link->status === 'pending', 404);

        DB::transaction(function () use ($me, $link) {
            if ($link->relation === 'spouse') {
                $mine = FamilyLink::where('user_id', $me->id)->where('relation', 'spouse')->first();
                abort_if($mine && $mine->status === 'confirmed' && (int) $mine->relative_user_id !== $link->user_id, 422,
                    'Vous êtes déjà lié(e) à un(e) autre conjoint(e). Retirez d\'abord ce lien dans votre profil.');
                if ($mine && (int) $mine->relative_user_id !== $link->user_id) {
                    $mine->delete();
                }
                $link->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
                $requester = User::with('profile')->find($link->user_id);
                FamilyLink::updateOrCreate(
                    ['user_id' => $me->id, 'relation' => 'spouse'],
                    ['relative_user_id' => $link->user_id, 'relative_name' => $requester->profile?->full_name, 'status' => 'confirmed', 'confirmed_at' => now()],
                );
                self::syncMarriage($me, $requester);
            } else {
                $link->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
            }
            Audit::log('family.link_confirmed', $link, $me->id, ['status' => 'pending'], ['status' => 'confirmed'], ['relation' => $link->relation]);
            Notifier::send([$link->user_id], 'family', 'Lien familial confirmé',
                ($me->profile?->full_name ?: 'La personne').' a confirmé le lien.', '/mon-profil#famille');
        });
    }

    public static function decline(User $me, FamilyLink $link): void
    {
        abort_unless((int) $link->relative_user_id === $me->id && $link->status === 'pending', 404);
        $link->forceFill(['status' => 'declined'])->save();
        Audit::log('family.link_declined', $link, $me->id, ['status' => 'pending'], ['status' => 'declined'], ['relation' => $link->relation]);
        Notifier::send([$link->user_id], 'family', 'Lien familial non confirmé',
            'La personne désignée n\'a pas confirmé le lien. Vérifiez votre choix dans votre profil.', '/mon-profil#famille');
    }

    /** Retire le lien conjugal des deux cotes (changement de situation ou correction). */
    public static function unlinkSpouse(User $me): void
    {
        $links = FamilyLink::where('relation', 'spouse')
            ->where(fn ($q) => $q->where('user_id', $me->id)->orWhere('relative_user_id', $me->id))->get();
        foreach ($links as $l) {
            Audit::log('family.spouse_unlinked', $l, $me->id, ['user_id' => $l->user_id, 'relative_user_id' => $l->relative_user_id, 'status' => $l->status]);
            $l->delete();
        }
        $me->profile?->forceFill(['spouse_name' => null])->save();
    }

    /**
     * Enfants declares (remplace la liste).
     *
     * @param  array<int, array{name: string, birth_year?: int|null, user_id?: int|null}>  $children
     */
    public static function setChildren(User $me, bool $hasChildren, array $children): void
    {
        DB::transaction(function () use ($me, $hasChildren, $children) {
            $before = FamilyLink::where('user_id', $me->id)->where('relation', 'child')->get(['relative_name', 'birth_year', 'relative_user_id'])->toArray();
            $existing = FamilyLink::where('user_id', $me->id)->where('relation', 'child')->get()->keyBy('id');
            FamilyLink::where('user_id', $me->id)->where('relation', 'child')->delete();

            $kept = [];
            foreach ($hasChildren ? $children : [] as $child) {
                $userId = ! empty($child['user_id']) ? (int) $child['user_id'] : null;
                abort_if($userId === $me->id, 422, 'Vous ne pouvez pas vous désigner comme enfant.');
                $wasConfirmed = $userId && $existing->contains(fn ($l) => (int) $l->relative_user_id === $userId && $l->status === 'confirmed');
                $link = FamilyLink::create([
                    'user_id' => $me->id, 'relation' => 'child', 'relative_user_id' => $userId,
                    'relative_name' => mb_substr(trim($child['name']), 0, 150),
                    'birth_year' => $child['birth_year'] ?? null,
                    'status' => $userId && ! $wasConfirmed ? 'pending' : 'confirmed',
                    'confirmed_at' => $userId && ! $wasConfirmed ? null : now(),
                ]);
                if ($userId && ! $wasConfirmed) {
                    Notifier::send([$userId], 'family', ($me->profile?->full_name ?: 'Un membre').' vous a indiqué comme son enfant',
                        'Confirmez ce lien dans votre profil.', '/mon-profil#famille');
                }
                $kept[] = ['relative_name' => $link->relative_name, 'birth_year' => $link->birth_year, 'relative_user_id' => $userId];
            }

            $me->profile->forceFill(['has_children' => $hasChildren, 'children_count' => count($kept)])->save();
            [$old, $new] = Audit::diff(['children' => $before], ['children' => $kept]);
            if ($new) {
                Audit::log('family.children_updated', $me, $me->id, $old, $new);
            }
            ProfileCompletion::refresh($me->profile->fresh());
        });
    }

    /**
     * Vue famille du membre : conjoint, enfants, demandes a confirmer et suggestions
     * (conjoint non inscrit dont le nom correspond a un membre : proposition seulement).
     */
    public static function overview(User $me): array
    {
        $links = FamilyLink::where('user_id', $me->id)->with('relative.profile.tribe')->get();
        $spouse = $links->firstWhere('relation', 'spouse');
        $incoming = FamilyLink::where('relative_user_id', $me->id)->where('status', 'pending')->with('user.profile')->get();

        $suggestions = [];
        $profile = $me->profile;
        if ($profile?->marital_status === 'marie' && ! $spouse && filled($profile->spouse_name)) {
            $suggestions = self::search($me, $profile->spouse_name)->take(5)->values()->all();
        }

        return [
            'spouse' => $spouse ? [
                'id' => $spouse->id,
                'user_id' => $spouse->relative_user_id,
                'name' => $spouse->relative?->profile?->full_name ?: $spouse->relative_name,
                'tribe' => $spouse->relative?->profile?->tribe?->name,
                'photo_url' => $spouse->relative?->profile?->photo_url,
                'status' => $spouse->status,
            ] : null,
            'spouse_name' => $profile?->spouse_name,
            'children' => $links->where('relation', 'child')->map(fn (FamilyLink $l) => [
                'id' => $l->id,
                'name' => $l->relative?->profile?->full_name ?: $l->relative_name,
                'birth_year' => $l->birth_year,
                'user_id' => $l->relative_user_id,
                'status' => $l->status,
            ])->values()->all(),
            'incoming' => $incoming->map(fn (FamilyLink $l) => [
                'id' => $l->id,
                'relation' => $l->relation,
                'from' => $l->user?->profile?->full_name ?: $l->user?->phone,
                'from_user_id' => $l->user_id,
            ])->values()->all(),
            'suggestions' => $suggestions,
        ];
    }

    /** Conjoints confirmes : meme situation et meme date de mariage des deux cotes (si l'un l'a renseignee). */
    private static function syncMarriage(User $a, User $b): void
    {
        $pa = $a->profile()->first();
        $pb = $b->profile()->first();
        foreach ([[$pa, $pb], [$pb, $pa]] as [$p, $other]) {
            $updates = ['marital_status' => 'marie', 'spouse_name' => $other->full_name];
            if (! $p->wedding_day && $other->wedding_day) {
                $updates['wedding_day'] = $other->wedding_day;
                $updates['wedding_month'] = $other->wedding_month;
            }
            $p->forceFill($updates)->save();
            ProfileCompletion::refresh($p->fresh());
        }
    }
}
