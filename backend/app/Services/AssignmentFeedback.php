<?php

namespace App\Services;

use App\Models\AssignmentComment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentResponseLike;
use App\Models\User;
use App\Support\Audit;
use App\Support\RichText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retours du pasteur sur les devoirs (DS) : « j'aime » et commentaires.
 * - Qui : le droit assignments.feedback (le Pasteur Resident), sur les reponses qu'il lit deja (memes regles que le
 *   suivi des devoirs), jamais sur sa propre reponse, jamais le compte de gestion ;
 * - qui les voit : le membre et les personnes qui ont ce droit (choix du 18/11 : ni le patriarche ni l'AP) ;
 * - le membre est prevenu : commentaire dans la cloche, sur le telephone et par courriel ; « j'aime » dans la
 *   cloche et sur le telephone, une seule fois par reponse. Le texte du commentaire n'est jamais dans la notification.
 */
class AssignmentFeedback
{
    public const COMMENT_MAX = 2000;

    public const PERMISSION = 'assignments.feedback';

    /** Peut-il aimer et commenter (et voir les retours) en general ? */
    public static function enabledFor(User $viewer): bool
    {
        return ! $viewer->isPlatformManager() && $viewer->hasPermission(self::PERMISSION) && $viewer->fissTribeIds() !== [];
    }

    /** Peut-il aimer ou commenter cette reponse ? (memes membres que le suivi des devoirs, sauf lui-meme) */
    public static function canAct(User $viewer, AssignmentResponse $response): bool
    {
        if (! self::enabledFor($viewer) || (int) $response->user_id === (int) $viewer->id) {
            return false;
        }
        $member = $response->relationLoaded('user') ? $response->user : $response->user()->with('profile')->first();

        return $member !== null && $member->profile?->is_completed && $viewer->canViewFissOf($member);
    }

    /** Erreur 404 (on ne dit pas que la reponse existe) si le pasteur ne peut pas agir sur elle. */
    public static function ensure(User $viewer, AssignmentResponse $response): void
    {
        abort_unless(self::canAct($viewer, $response), 404, 'Réponse introuvable.');
        $assignment = $response->assignment;
        abort_if(! $assignment || $assignment->trashed(), 422, 'Ce devoir a été retiré : il n’est plus visible des membres.');
    }

    // ------------------------------------------------------------------ Actions

    /** Aime (ou n'aime plus) la reponse. Le membre est prevenu une seule fois par reponse. */
    public static function like(User $pastor, AssignmentResponse $response, bool $liked): void
    {
        self::ensure($pastor, $response);
        if (! $liked) {
            AssignmentResponseLike::where('response_id', $response->id)->where('user_id', $pastor->id)->delete();
            // L'avis « X aime votre devoir » encore non lu disparait : il ne serait plus vrai.
            DB::table('user_notifications')->where('user_id', $response->user_id)->where('type', 'assignment_like')
                ->whereNull('read_at')->where('data->response_id', $response->id)->delete();

            return;
        }
        $like = AssignmentResponseLike::firstOrCreate(['response_id' => $response->id, 'user_id' => $pastor->id], ['member_id' => $response->user_id]);
        if ($like->wasRecentlyCreated && Notifier::once("assignment-like:{$response->id}:{$pastor->id}")) {
            $title = $response->assignment?->title ?? 'votre devoir';
            Notifier::send([$response->user_id], 'assignment_like', self::nameOf($pastor).' aime votre devoir',
                '« '.self::short($title).' »', '/mon-devoir?d='.$response->assignment_id,
                ['response_id' => $response->id, 'assignment_id' => $response->assignment_id]);
        }
    }

    /** Ajoute un commentaire : le membre le voit dans son devoir et en est prevenu (cloche, telephone, courriel). */
    public static function comment(User $pastor, AssignmentResponse $response, mixed $doc, ?string $text): AssignmentComment
    {
        self::ensure($pastor, $response);
        [$doc, $text] = self::body($doc, $text);
        $comment = AssignmentComment::create([
            'response_id' => $response->id, 'member_id' => $response->user_id, 'author_id' => $pastor->id,
            'body' => $text, 'body_doc' => $doc,
        ]);
        Audit::log('assignment.comment_added', $response, (int) $response->user_id, [], [], ['assignment_id' => $response->assignment_id], $pastor);

        $member = User::with('profile')->find($response->user_id);
        $title = $response->assignment?->title ?? 'votre devoir';
        $url = '/mon-devoir?d='.$response->assignment_id;
        $name = self::nameOf($pastor);
        Notifier::send([$response->user_id], 'assignment_comment', 'Commentaire de '.$name,
            'Sur votre devoir « '.self::short($title).' ». Touchez pour le lire.', $url,
            ['response_id' => $response->id, 'assignment_id' => $response->assignment_id, 'comment_id' => $comment->id], 'high');
        if ($member) {
            $link = rtrim((string) config('app.url'), '/').$url;
            Messenger::toMember($member, 'assignment_comment', "assignment-comment:{$comment->id}", [
                'subject' => $name.' a commenté votre devoir',
                'view' => 'assignment-comment',
                'data' => ['firstName' => $member->profile?->first_name ?: 'cher membre', 'pastor' => $name, 'title' => $title, 'url' => $link],
            ], "My vasesdhonneur : {$name} a commente votre devoir \"".self::short($title, 50)."\". Lisez-le ici : {$link}");
        }

        return $comment;
    }

    /** Modifie son propre commentaire (le membre voit « modifié », sans nouvelle notification). */
    public static function edit(User $pastor, AssignmentComment $comment, mixed $doc, ?string $text): AssignmentComment
    {
        abort_unless((int) $comment->author_id === (int) $pastor->id, 403, 'Vous ne pouvez modifier que vos commentaires.');
        self::ensure($pastor, $comment->response);
        [$doc, $text] = self::body($doc, $text);
        if ($text !== $comment->body || $doc !== $comment->body_doc) {
            $comment->forceFill(['body' => $text, 'body_doc' => $doc, 'edited_at' => now()])->save();
        }

        return $comment;
    }

    /** Supprime son propre commentaire : il disparait aussi des avis non lus et des courriels pas encore partis. */
    public static function delete(User $pastor, AssignmentComment $comment): void
    {
        abort_unless((int) $comment->author_id === (int) $pastor->id, 403, 'Vous ne pouvez supprimer que vos commentaires.');
        abort_unless(self::canAct($pastor, $comment->response), 404, 'Commentaire introuvable.');
        DB::transaction(function () use ($comment, $pastor) {
            DB::table('user_notifications')->where('user_id', $comment->member_id)->where('type', 'assignment_comment')
                ->whereNull('read_at')->where('data->comment_id', $comment->id)->delete();
            DB::table('outbound_messages')->where('dedupe_key', "assignment-comment:{$comment->id}")->where('status', 'pending')->delete();
            Audit::log('assignment.comment_deleted', $comment->response, (int) $comment->member_id, [], [], ['assignment_id' => $comment->response?->assignment_id], $pastor);
            $comment->delete();
        });
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private static function body(mixed $doc, ?string $text): array
    {
        if ($doc !== null) {
            [$doc, $text] = RichText::clean($doc, self::COMMENT_MAX, 'body');
        } else {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
            if (mb_strlen($text) > self::COMMENT_MAX) {
                throw ValidationException::withMessages(['body' => 'Ce commentaire est trop long : '.self::COMMENT_MAX.' caractères au plus.']);
            }
        }
        if (trim($text) === '') {
            throw ValidationException::withMessages(['body' => 'Écrivez votre commentaire.']);
        }

        return [$doc, $text];
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * Retours sur des reponses : ceux du pasteur qui regarde (aime-t-il ?) et l'ensemble des « j'aime » et
     * commentaires. Pour le membre lui-meme, comme pour le pasteur.
     *
     * @param  Collection<int, AssignmentResponse>  $responses
     * @return array<int, array<string, mixed>> par identifiant de reponse
     */
    public static function forResponses(Collection $responses, User $viewer): array
    {
        $ids = $responses->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $likes = AssignmentResponseLike::whereIn('response_id', $ids)->with('user.profile:id,user_id,first_name,last_name,photo_path')
            ->orderBy('id')->get()->groupBy('response_id');
        $comments = AssignmentComment::whereIn('response_id', $ids)->with('author.profile:id,user_id,first_name,last_name,photo_path')
            ->orderBy('id')->get()->groupBy('response_id');
        $out = [];
        foreach ($ids as $id) {
            $own = $likes->get($id, collect());
            $out[$id] = [
                'liked' => $own->contains('user_id', $viewer->id),
                'likes' => $own->map(fn (AssignmentResponseLike $l) => [
                    'name' => self::nameOf($l->user),
                    'photo_url' => $l->user?->profile?->photo_url,
                    'is_me' => (int) $l->user_id === (int) $viewer->id,
                ])->values()->all(),
                'comments' => $comments->get($id, collect())->map(fn (AssignmentComment $c) => self::present($c, $viewer))->values()->all(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function present(AssignmentComment $c, User $viewer): array
    {
        return [
            'id' => $c->id,
            'author' => $c->author ? self::nameOf($c->author) : 'Ancien pasteur',
            'photo_url' => $c->author?->profile?->photo_url,
            'mine' => (int) $c->author_id === (int) $viewer->id,
            'body' => $c->body,
            'body_doc' => $c->body_doc,
            'edited' => $c->edited_at !== null,
            'at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** Nom affiche d'un pasteur (Pasteur Resident sans profil : son role). */
    private static function nameOf(?User $user): string
    {
        return trim((string) $user?->profile?->full_name) ?: 'Votre pasteur';
    }

    private static function short(string $text, int $length = 70): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }

    /** Reponse d'un membre a un devoir, chargee avec ce qu'il faut pour les droits. */
    public static function response(int|string $id): AssignmentResponse
    {
        $response = ctype_digit((string) $id) ? AssignmentResponse::with(['user.profile', 'assignment'])->find((int) $id) : null;
        abort_unless($response, 404, 'Réponse introuvable.');

        return $response;
    }

    public static function findComment(int|string $id): AssignmentComment
    {
        $comment = ctype_digit((string) $id) ? AssignmentComment::with(['response.user.profile', 'response.assignment'])->find((int) $id) : null;
        abort_unless($comment && $comment->response, 404, 'Commentaire introuvable.');

        return $comment;
    }
}
