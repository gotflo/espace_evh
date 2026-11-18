<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\User;
use App\Support\Audit;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Chat des GEMs. Regles, appliquees a chaque appel (rien n'est deduit de ce que l'appareil envoie) :
 * - discussion de groupe : seuls les membres ACTUELS du GEM (profil complete place dans le GEM, et son Garde) ;
 *   celui qui quitte le GEM n'y a plus acces, celui qui arrive ne lit que les messages envoyes depuis son arrivee ;
 * - discussion privee : seulement entre deux membres d'un meme GEM ; s'ils ne partagent plus de GEM, l'historique
 *   reste lisible par eux deux mais plus personne ne peut ecrire ;
 * - personne d'autre ne lit : ni responsable, ni pasteur, ni compte de gestion (aucune adresse d'administration) ;
 * - le texte est chiffre dans la base, les photos sont sur le disque prive ; les membres sont designes par une
 *   cle opaque (jamais leur identifiant de compte).
 */
class ChatService
{
    /** Messages par page (du plus recent au plus ancien). */
    public const PAGE = 40;

    public const MAX_BODY = 4000;

    /** Un message se modifie pendant ce delai apres l'envoi. */
    public const EDIT_MINUTES = 15;

    public const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '🎉'];

    /** Photo du chat : plus grand cote, apres reduction. */
    public const PHOTO_SIDE = 1600;

    public const PHOTO_DIR = 'chat';

    private const TYPING_SECONDS = 6;

    private const ONLINE_SECONDS = 25;

    private const VIEWING_SECONDS = 10;

    /** Deux messages rapproches ne font vibrer le telephone qu'une fois par discussion sur cette duree. */
    private const PUSH_QUIET_SECONDS = 15;

    /** Changements renvoyes au plus par une mise a jour en direct (au-dela, l'ecran recharge la discussion). */
    private const SYNC_LIMIT = 150;

    /** Recherche : messages relus au plus (les plus recents d'abord), resultats rendus au plus. */
    public const SEARCH_SCAN = 3000;

    public const SEARCH_RESULTS = 40;

    /** Messages de part et d'autre d'un message retrouve (recherche). */
    private const AROUND = 20;

    // ------------------------------------------------------------------ Membres et acces

    /** Cle opaque d'un membre dans le chat (stable, impossible a relier a son identifiant sans la cle du serveur). */
    public static function key(int $userId): string
    {
        return substr(hash_hmac('sha256', 'chat-member:'.$userId, (string) config('app.key')), 0, 20);
    }

    /**
     * GEMs dont le membre fait partie : celui ou il est place (profil complete) et ceux dont il est le Garde.
     *
     * @return array<int>
     */
    public static function gemIds(User $user): array
    {
        $profile = $user->profile;
        $ids = Gem::where('leader_user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($profile?->gem_id && $profile->is_completed && Gem::whereKey($profile->gem_id)->exists()) {
            $ids[] = (int) $profile->gem_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Membres actuels d'un GEM (les memes que la page « Mon GEM ») : profils completes places dans le GEM, et son Garde.
     *
     * @return array<int>
     */
    public static function memberIds(Gem $gem): array
    {
        $ids = GemReportService::members($gem)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($gem->leader_user_id) {
            $ids[] = (int) $gem->leader_user_id;
        }

        return array_values(array_unique($ids));
    }

    /** Les deux membres partagent-ils un GEM aujourd'hui ? */
    public static function shareGem(User $a, User $b): bool
    {
        return array_intersect(self::gemIds($a), self::gemIds($b)) !== [];
    }

    /** Discussion de groupe d'un GEM (creee au premier acces), participants mis a jour. */
    public static function groupFor(Gem $gem): ChatConversation
    {
        $conversation = ChatConversation::firstOrCreate(['type' => ChatConversation::GEM, 'gem_id' => $gem->id]);
        self::syncGroup($conversation, $gem);

        return $conversation;
    }

    /**
     * Participants du groupe = membres actuels du GEM. Un arrivant est inscrit avec le dernier message deja envoye
     * (il lira les suivants) ; celui qui est parti est retire (s'il revient, il repart du message courant).
     */
    public static function syncGroup(ChatConversation $conversation, ?Gem $gem = null): void
    {
        $gem ??= $conversation->gem;
        if (! $gem) {
            return;
        }
        $members = self::memberIds($gem);
        $rows = ChatParticipant::where('conversation_id', $conversation->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $now = now();
        foreach (array_diff($members, $rows) as $userId) {
            $from = (int) ($conversation->last_message_id ?? 0);
            DB::table('chat_participants')->insertOrIgnore([
                'conversation_id' => $conversation->id, 'user_id' => $userId,
                'joined_message_id' => $from, 'last_read_message_id' => $from, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($gone = array_diff($rows, $members)) {
            ChatParticipant::where('conversation_id', $conversation->id)->whereIn('user_id', $gone)->delete();
            // Leur notification de cette discussion n'a plus d'objet.
            DB::table('user_notifications')->whereIn('user_id', $gone)->where('type', 'chat')->where('url', '/chat/'.$conversation->id)->delete();
        }
    }

    /**
     * Discussions que le membre peut ouvrir : le groupe de chacun de ses GEMs, et ses discussions privees.
     *
     * @return Collection<int, ChatConversation>
     */
    public static function conversationsFor(User $user): Collection
    {
        $groups = collect();
        foreach (Gem::whereIn('id', self::gemIds($user) ?: [0])->get() as $gem) {
            $groups->push(self::groupFor($gem)->setRelation('gem', $gem));
        }
        $directs = ChatConversation::where('type', ChatConversation::DIRECT)
            ->where(fn ($q) => $q->where('user_low_id', $user->id)->orWhere('user_high_id', $user->id))->get();

        return $groups->concat($directs)->values();
    }

    /** La discussion, si le membre y a acces ; sinon « introuvable » (on ne dit pas qu'elle existe). */
    public static function find(User $user, int|string $id): ChatConversation
    {
        $conversation = ctype_digit((string) $id) ? ChatConversation::find((int) $id) : null;
        abort_unless($conversation && self::canRead($user, $conversation), 404, 'Discussion introuvable.');
        if ($conversation->isGroup()) {
            self::syncGroup($conversation);
        }

        return $conversation;
    }

    public static function canRead(User $user, ChatConversation $conversation): bool
    {
        if ($conversation->isGroup()) {
            return $conversation->gem_id && in_array((int) $conversation->gem_id, self::gemIds($user), true);
        }

        return in_array((int) $user->id, [(int) $conversation->user_low_id, (int) $conversation->user_high_id], true);
    }

    public static function canSend(User $user, ChatConversation $conversation): bool
    {
        if (! self::canRead($user, $conversation)) {
            return false;
        }
        if ($conversation->isGroup()) {
            return true;
        }
        $other = User::with('profile')->find($conversation->otherUserId((int) $user->id));

        return $other !== null && self::shareGem($user, $other);
    }

    /** Place du membre dans la discussion (creee pour une discussion privee ouverte pour la premiere fois). */
    public static function participant(User $user, ChatConversation $conversation): ChatParticipant
    {
        if ($conversation->isGroup()) {
            // Jamais de place creee « depuis le debut » pour un arrivant : syncGroup l'inscrit au message courant.
            $conversation->refresh();
            self::syncGroup($conversation);

            return ChatParticipant::where('conversation_id', $conversation->id)->where('user_id', $user->id)->firstOrFail();
        }

        return ChatParticipant::firstOrCreate(['conversation_id' => $conversation->id, 'user_id' => $user->id]);
    }

    /**
     * Ouvre (ou cree) la discussion privee avec un membre de l'un de ses GEMs, designe par sa cle.
     */
    public static function directWith(User $user, string $key): ChatConversation
    {
        $candidates = [];
        foreach (Gem::whereIn('id', self::gemIds($user) ?: [0])->get() as $gem) {
            $candidates = array_merge($candidates, self::memberIds($gem));
        }
        $otherId = collect($candidates)->unique()->first(fn ($id) => $id !== (int) $user->id && hash_equals(self::key($id), $key));
        abort_unless($otherId, 404, 'Ce membre ne fait pas partie de votre GEM.');

        [$low, $high] = [min((int) $user->id, $otherId), max((int) $user->id, $otherId)];
        $conversation = ChatConversation::firstOrCreate(['type' => ChatConversation::DIRECT, 'user_low_id' => $low, 'user_high_id' => $high]);
        foreach ([$low, $high] as $id) {
            DB::table('chat_participants')->insertOrIgnore([
                'conversation_id' => $conversation->id, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $conversation;
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * Liste des discussions du membre, la plus recente d'abord, et les membres de ses GEMs a qui il peut ecrire.
     *
     * @return array<string, mixed>
     */
    public static function inbox(User $user): array
    {
        self::seen($user);
        $conversations = self::conversationsFor($user);
        $mine = ChatParticipant::where('user_id', $user->id)->whereIn('conversation_id', $conversations->pluck('id'))->get()->keyBy('conversation_id');
        $unread = self::unreadCounts($user, $conversations->pluck('id')->all());
        $last = ChatMessage::whereIn('id', $conversations->pluck('last_message_id')->filter())->get()->keyBy('id');

        // Identite de tous les membres utiles, en une lecture.
        $gems = Gem::whereIn('id', self::gemIds($user) ?: [0])->orderBy('name')->get();
        $memberIds = [];
        foreach ($gems as $gem) {
            $memberIds[$gem->id] = self::memberIds($gem);
        }
        $peerIds = $conversations->map(fn (ChatConversation $c) => $c->otherUserId((int) $user->id))->filter();
        $people = self::profiles(collect($memberIds)->flatten()->merge($peerIds)->merge($last->pluck('sender_id'))->unique()->all());
        $shared = collect($memberIds)->flatten()->unique()->flip();

        $rows = $conversations->filter(fn (ChatConversation $c) => $c->isGroup() || $c->last_message_id)
            ->map(function (ChatConversation $c) use ($user, $mine, $unread, $last, $people, $shared) {
                $peerId = $c->otherUserId((int) $user->id);
                $message = $c->last_message_id ? $last->get($c->last_message_id) : null;
                // Un arrivant ne voit pas l'apercu d'un message anterieur a son arrivee.
                if ($message && $c->isGroup() && $message->id <= (int) ($mine->get($c->id)?->joined_message_id ?? 0)) {
                    $message = null;
                }

                return [
                    'id' => $c->id,
                    'type' => $c->type,
                    'title' => $c->isGroup() ? ($c->gem?->label() ?? 'GEM') : ($people->get($peerId)?->full_name ?: 'Ancien membre'),
                    'photo_url' => $c->isGroup() ? null : $people->get($peerId)?->photo_url,
                    'peer' => $peerId ? self::key($peerId) : null,
                    'online' => $peerId ? self::isOnline($peerId) : false,
                    'can_send' => $c->isGroup() || ($peerId && isset($shared[$peerId])),
                    'unread' => (int) ($unread[$c->id] ?? 0),
                    'last' => $message ? [
                        'text' => self::excerpt($message, 90),
                        'kind' => $message->kind,
                        'deleted' => $message->deleted_at !== null,
                        'mine' => (int) $message->sender_id === (int) $user->id,
                        'sender' => $people->get($message->sender_id)?->first_name,
                        'at' => $message->created_at?->toIso8601String(),
                    ] : null,
                    'sort' => $message?->created_at?->getTimestamp() ?? 0,
                ];
            })
            ->sortBy([['sort', 'desc'], ['type', 'desc'], ['title', 'asc']])->values()
            ->map(fn (array $row) => array_diff_key($row, ['sort' => 1]))->all();

        $directs = $conversations->where('type', ChatConversation::DIRECT)
            ->mapWithKeys(fn (ChatConversation $c) => [$c->otherUserId((int) $user->id) => $c->id]);
        $contacts = [];
        foreach ($gems as $gem) {
            foreach ($memberIds[$gem->id] as $id) {
                if ($id === (int) $user->id || isset($contacts[$id]) || ! $people->get($id)) {
                    continue;
                }
                $contacts[$id] = [
                    'key' => self::key($id),
                    'name' => $people->get($id)->full_name,
                    'photo_url' => $people->get($id)->photo_url,
                    'is_garde' => (int) $gem->leader_user_id === $id,
                    'gem' => $gem->label(),
                    'conversation_id' => $directs->get($id),
                ];
            }
        }

        return [
            'me' => self::key((int) $user->id),
            'conversations' => $rows,
            'contacts' => collect($contacts)->sortBy([['is_garde', 'desc'], ['name', 'asc']])->values()->all(),
            'unread' => array_sum($unread),
        ];
    }

    /**
     * Messages non lus par discussion (messages des autres, non supprimes, apres le dernier lu et apres l'arrivee).
     *
     * @param  array<int>  $conversationIds
     * @return array<int, int>
     */
    public static function unreadCounts(User $user, array $conversationIds): array
    {
        if (! $conversationIds) {
            return [];
        }

        return DB::table('chat_participants as p')
            ->join('chat_messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $user->id)->whereIn('p.conversation_id', $conversationIds)
            ->whereColumn('m.id', '>', 'p.last_read_message_id')->whereColumn('m.id', '>', 'p.joined_message_id')
            ->where('m.sender_id', '!=', $user->id)->whereNull('m.deleted_at')
            ->groupBy('p.conversation_id')->selectRaw('p.conversation_id as c, count(*) as n')
            ->pluck('n', 'c')->map(fn ($n) => (int) $n)->all();
    }

    /** Total des messages non lus (pastille du menu), sans creer ni modifier quoi que ce soit. */
    public static function unreadTotal(User $user): int
    {
        // Une seule lecture (appelee par le pouls de chaque membre) : ses discussions privees, le groupe du GEM
        // ou il est place et ceux qu'il mene. Une place restee dans un GEM quitte ne compte pas.
        $profile = $user->profile;
        $gemId = $profile?->is_completed ? (int) $profile->gem_id : 0;

        return (int) DB::table('chat_participants as p')
            ->join('chat_conversations as c', 'c.id', '=', 'p.conversation_id')
            ->join('chat_messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $user->id)
            ->where(fn ($q) => $q->where('c.type', ChatConversation::DIRECT)->orWhere('c.gem_id', $gemId)
                ->orWhereIn('c.gem_id', DB::table('gems')->where('leader_user_id', $user->id)->select('id')))
            ->whereColumn('m.id', '>', 'p.last_read_message_id')->whereColumn('m.id', '>', 'p.joined_message_id')
            ->where('m.sender_id', '!=', $user->id)->whereNull('m.deleted_at')
            ->count();
    }

    /**
     * Une page de messages, du plus ancien au plus recent : les plus recents, ceux d'avant $before, ceux d'apres
     * $after, ou ceux qui entourent $around (message retrouve par la recherche).
     *
     * @return array<string, mixed>
     */
    public static function page(User $user, ChatConversation $conversation, ?int $before = null, ?int $after = null, ?int $around = null): array
    {
        self::seen($user, $conversation);
        $me = self::participant($user, $conversation);
        $more = null;
        $newer = null;
        if ($around) {
            $old = self::visible($conversation, $me)->where('id', '<=', $around)->orderByDesc('id')->limit(self::AROUND + 2)->get();
            abort_unless($old->first()?->id === $around, 404, 'Message introuvable.');
            $new = self::visible($conversation, $me)->where('id', '>', $around)->orderBy('id')->limit(self::AROUND + 1)->get();
            $more = $old->count() > self::AROUND + 1;
            $newer = $new->count() > self::AROUND;
            $messages = $old->take(self::AROUND + 1)->reverse()->concat($new->take(self::AROUND))->values();
        } elseif ($after) {
            $messages = self::visible($conversation, $me)->where('id', '>', $after)->orderBy('id')->limit(self::PAGE + 1)->get();
            $newer = $messages->count() > self::PAGE;
            $messages = $messages->take(self::PAGE)->values();
        } else {
            $messages = self::visible($conversation, $me)
                ->when($before, fn ($q) => $q->where('id', '<', $before))
                ->orderByDesc('id')->limit(self::PAGE + 1)->get();
            $more = $messages->count() > self::PAGE;
            $newer = $before ? null : false;
            $messages = $messages->take(self::PAGE)->reverse()->values();
        }

        return self::state($user, $conversation, $me) + [
            'messages' => self::presentMany($messages, $user),
            'people' => self::people($user, $conversation, $messages),
            // null : cette page ne dit rien de ce cote (l'ecran garde ce qu'il savait).
            'has_more' => $more,
            'has_newer' => $newer,
            'first_unread_id' => $before || $after || $around ? null : $messages->first(fn (ChatMessage $m) => $m->id > $me->last_read_message_id
                && (int) $m->sender_id !== (int) $user->id && ! $m->deleted_at)?->id,
            'edit_minutes' => self::EDIT_MINUTES,
            'reactions' => self::REACTIONS,
            'max_length' => self::MAX_BODY,
        ];
    }

    /**
     * Recherche dans une discussion. Le texte etant chiffre dans la base, les messages que ce membre peut lire
     * sont relus un a un (les plus recents d'abord, SEARCH_SCAN au plus) ; majuscules et accents ne comptent pas.
     *
     * @return array<string, mixed>
     */
    public static function search(User $user, ChatConversation $conversation, string $query): array
    {
        $me = self::participant($user, $conversation);
        $needle = self::fold($query);
        $found = collect();
        $scanned = 0;
        $cursor = null;
        while ($scanned < self::SEARCH_SCAN && $found->count() < self::SEARCH_RESULTS) {
            $lot = ChatMessage::where('conversation_id', $conversation->id)
                ->when($conversation->isGroup(), fn ($q) => $q->where('id', '>', $me->joined_message_id))
                ->whereNull('deleted_at')->whereNotNull('body')
                ->when($cursor, fn ($q) => $q->where('id', '<', $cursor))
                ->orderByDesc('id')->limit(min(300, self::SEARCH_SCAN - $scanned))->get();
            if ($lot->isEmpty()) {
                break;
            }
            foreach ($lot as $m) {
                $scanned++;
                $cursor = $m->id;
                if (str_contains(self::fold((string) $m->body), $needle)) {
                    $found->push($m);
                    if ($found->count() >= self::SEARCH_RESULTS) {
                        break;
                    }
                }
            }
            if ($lot->count() < 300) {
                break;
            }
        }
        $profiles = self::profiles($found->pluck('sender_id')->unique()->all());

        return [
            'results' => $found->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'sender' => (int) $m->sender_id === (int) $user->id ? 'Vous' : ($profiles->get($m->sender_id)?->full_name ?: 'Ancien membre'),
                'kind' => $m->kind,
                'text' => self::around((string) $m->body, $query),
                'at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            // Tout n'a pas pu etre relu (discussion tres longue) ou la liste est coupee : l'ecran le dit.
            'partial' => $found->count() >= self::SEARCH_RESULTS || $scanned >= self::SEARCH_SCAN,
        ];
    }

    /** Minuscules sans accents, pour comparer « priere » et « Prière ». */
    private static function fold(string $text): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii($text));
    }

    /** Extrait d'un message autour du mot cherche. */
    private static function around(string $body, string $query): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        $at = mb_stripos($text, trim($query));
        if ($at === false) {
            $at = (int) mb_strpos(self::fold($text), self::fold($query));
        }
        $start = max(0, $at - 40);

        return ($start > 0 ? '…' : '').mb_strimwidth(mb_substr($text, $start), 0, 140, '…');
    }

    /**
     * Mise a jour en direct : ce qui a change depuis $rev (nouveaux messages, modifications, suppressions,
     * reactions), qui a lu jusqu'ou, qui ecrit.
     *
     * @return array<string, mixed>
     */
    public static function sync(User $user, ChatConversation $conversation, int $rev): array
    {
        self::seen($user, $conversation);
        $me = self::participant($user, $conversation);
        $changed = self::visible($conversation, $me)->where('rev', '>', $rev)->orderBy('rev')->limit(self::SYNC_LIMIT + 1)->get();
        $reload = $changed->count() > self::SYNC_LIMIT;

        return self::state($user, $conversation, $me) + [
            'reload' => $reload,
            'messages' => $reload ? [] : self::presentMany($changed, $user),
            'people' => $reload ? (object) [] : self::people($user, $conversation, $changed),
            'unread_total' => self::unreadTotal($user),
        ];
    }

    /** Etat courant d'une discussion : compteur, droit d'ecrire, lectures des autres, qui ecrit. */
    private static function state(User $user, ChatConversation $conversation, ChatParticipant $me): array
    {
        $conversation->refresh();
        $others = ChatParticipant::where('conversation_id', $conversation->id)->where('user_id', '!=', $user->id)->get();
        $peerId = $conversation->otherUserId((int) $user->id);
        $typing = $others->filter(fn (ChatParticipant $p) => Cache::has(self::typingKey($conversation->id, (int) $p->user_id)))
            ->map(fn (ChatParticipant $p) => self::key((int) $p->user_id))->values()->all();

        return [
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'title' => $conversation->isGroup()
                    ? ($conversation->gem?->label() ?? 'GEM')
                    : (Profile::where('user_id', $peerId)->first()?->full_name ?: 'Ancien membre'),
                'photo_url' => $conversation->isGroup() ? null : Profile::where('user_id', $peerId)->first()?->photo_url,
                'peer' => $peerId ? self::key($peerId) : null,
                'members' => $conversation->isGroup() ? $others->count() + 1 : 2,
                'can_send' => self::canSend($user, $conversation),
                'online' => $peerId ? self::isOnline($peerId) : false,
            ],
            'me' => self::key((int) $user->id),
            'rev' => (int) $conversation->rev,
            'last_read_id' => (int) $me->last_read_message_id,
            // Jusqu'ou chaque autre participant a lu : sert aux coches « lu ».
            'reads' => $others->mapWithKeys(fn (ChatParticipant $p) => [self::key((int) $p->user_id) => (int) $p->last_read_message_id])->all(),
            'typing' => $typing,
        ];
    }

    /** Messages que ce participant peut lire (groupe : depuis son arrivee). */
    private static function visible(ChatConversation $conversation, ChatParticipant $me)
    {
        return ChatMessage::where('conversation_id', $conversation->id)
            ->when($conversation->isGroup(), fn ($q) => $q->where('id', '>', $me->joined_message_id))
            ->with(['reactions', 'replyTo']);
    }

    /** Un message de la discussion, visible par ce membre ; sinon « introuvable ». */
    public static function message(User $user, int|string $id): ChatMessage
    {
        $message = ctype_digit((string) $id) ? ChatMessage::with('conversation')->find((int) $id) : null;
        abort_unless($message && $message->conversation && self::canRead($user, $message->conversation), 404, 'Message introuvable.');
        if ($message->conversation->isGroup()) {
            $me = self::participant($user, $message->conversation);
            abort_unless($message->id > $me->joined_message_id, 404, 'Message introuvable.');
        }

        return $message;
    }

    // ------------------------------------------------------------------ Ecriture

    /**
     * Envoie un message (texte, ou photo avec legende facultative). Un meme envoi repete par l'appareil
     * (meme client_id) rend le message deja enregistre, sans doublon ni nouvelle notification.
     *
     * @param  array{client_id: string, body?: ?string, reply_to?: ?int}  $data
     */
    public static function send(User $user, ChatConversation $conversation, array $data, ?UploadedFile $photo = null): ChatMessage
    {
        $existing = ChatMessage::where('sender_id', $user->id)->where('client_id', $data['client_id'])->first();
        if ($existing) {
            abort_unless((int) $existing->conversation_id === (int) $conversation->id, 422, 'Envoi invalide.');

            return $existing->load('reactions', 'replyTo');
        }
        abort_unless(self::canSend($user, $conversation), 403, 'Vous ne pouvez plus écrire dans cette discussion : vous n’êtes plus dans le même GEM.');

        $body = self::clean($data['body'] ?? null);
        if ($body === '' && ! $photo) {
            throw ValidationException::withMessages(['body' => 'Écrivez un message.']);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'Ce message est trop long : '.self::MAX_BODY.' caractères au plus.']);
        }
        $me = self::participant($user, $conversation);
        $replyTo = null;
        if (! empty($data['reply_to'])) {
            $replyTo = ChatMessage::where('conversation_id', $conversation->id)->whereKey((int) $data['reply_to'])
                ->when($conversation->isGroup(), fn ($q) => $q->where('id', '>', $me->joined_message_id))->value('id');
            if (! $replyTo) {
                throw ValidationException::withMessages(['reply_to' => 'Le message auquel vous répondez est introuvable.']);
            }
        }
        $stored = $photo ? ImageOptimizer::storePrivate($photo, self::PHOTO_DIR.'/'.$conversation->id, self::PHOTO_SIDE) : null;

        $message = self::persist($user, $conversation, [
            'kind' => $stored ? ChatMessage::PHOTO : ChatMessage::TEXT,
            'body' => $body !== '' ? $body : null,
            'photo_path' => $stored['path'] ?? null,
            'photo_width' => $stored['width'] ?? null,
            'photo_height' => $stored['height'] ?? null,
            'reply_to_id' => $replyTo,
            'client_id' => $data['client_id'],
        ]);
        Cache::forget(self::typingKey($conversation->id, (int) $user->id));

        return $message;
    }

    /**
     * Transfere un message (texte, ou photo avec sa legende) vers une autre de ses discussions. Il faut pouvoir
     * lire le message d'origine et ecrire dans la discussion d'arrivee ; la photo est copiee (chaque discussion
     * garde son propre fichier) et le message porte la mention « Transféré », sans dire d'ou il vient.
     */
    public static function forward(User $user, ChatMessage $source, ChatConversation $target, string $clientId): ChatMessage
    {
        $existing = ChatMessage::where('sender_id', $user->id)->where('client_id', $clientId)->first();
        if ($existing) {
            abort_unless((int) $existing->conversation_id === (int) $target->id, 422, 'Envoi invalide.');

            return $existing->load('reactions', 'replyTo');
        }
        abort_if($source->deleted_at !== null, 422, 'Ce message a été supprimé.');
        abort_unless(self::canSend($user, $target), 403, 'Vous ne pouvez pas écrire dans cette discussion.');
        $photo = null;
        if ($source->photo_path) {
            abort_unless(Storage::disk('local')->exists($source->photo_path), 422, 'La photo de ce message n’est plus disponible.');
            $photo = self::PHOTO_DIR.'/'.$target->id.'/'.\Illuminate\Support\Str::random(40).'.jpg';
            Storage::disk('local')->copy($source->photo_path, $photo);
        }

        return self::persist($user, $target, [
            'kind' => $source->kind,
            'body' => $source->body,
            'photo_path' => $photo,
            'photo_width' => $photo ? $source->photo_width : null,
            'photo_height' => $photo ? $source->photo_height : null,
            'client_id' => $clientId,
            'forwarded' => true,
        ]);
    }

    /**
     * Enregistre un message : compteur de la discussion avance, participants du groupe a jour, auteur marque
     * comme ayant tout lu, autres participants prevenus. Une photo deja enregistree est effacee en cas d'echec.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function persist(User $user, ChatConversation $conversation, array $attributes): ChatMessage
    {
        try {
            $message = DB::transaction(function () use ($user, $conversation, $attributes) {
                $locked = ChatConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                if ($locked->isGroup()) {
                    self::syncGroup($locked);
                }
                $message = ChatMessage::create($attributes + [
                    'conversation_id' => $locked->id,
                    'sender_id' => $user->id,
                    'rev' => $locked->rev + 1,
                ]);
                $locked->forceFill(['rev' => $locked->rev + 1, 'last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();
                // L'auteur a forcement lu tout ce qui precede.
                ChatParticipant::where('conversation_id', $locked->id)->where('user_id', $user->id)
                    ->update(['last_read_message_id' => $message->id, 'updated_at' => now()]);

                return $message;
            });
        } catch (\Throwable $e) {
            if (! empty($attributes['photo_path'])) {
                Storage::disk('local')->delete($attributes['photo_path']);
            }
            throw $e;
        }
        self::clearBell((int) $user->id, (int) $conversation->id);
        self::notify($user, $conversation->refresh(), $message);

        return $message->load('reactions', 'replyTo');
    }

    /** Modifie son propre message (texte ou legende), dans le delai, s'il n'est pas supprime. */
    public static function edit(User $user, ChatMessage $message, ?string $body): ChatMessage
    {
        abort_unless((int) $message->sender_id === (int) $user->id, 403, 'Vous ne pouvez modifier que vos messages.');
        abort_if($message->deleted_at !== null, 422, 'Ce message a été supprimé.');
        abort_unless($message->created_at->gt(now()->subMinutes(self::EDIT_MINUTES)), 422,
            'Un message ne se modifie que pendant '.self::EDIT_MINUTES.' minutes après l’envoi.');
        abort_unless(self::canSend($user, $message->conversation), 403, 'Vous ne pouvez plus écrire dans cette discussion.');
        $body = self::clean($body);
        if ($body === '' && $message->kind === ChatMessage::TEXT) {
            throw ValidationException::withMessages(['body' => 'Écrivez un message.']);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'Ce message est trop long : '.self::MAX_BODY.' caractères au plus.']);
        }
        if ($body === (string) $message->body) {
            return $message->load('reactions', 'replyTo');
        }
        self::change($message, fn () => $message->forceFill(['body' => $body !== '' ? $body : null, 'edited_at' => now()]));

        return $message->load('reactions', 'replyTo');
    }

    /** Supprime son propre message pour tout le monde : texte, photo et reactions effaces, il reste une trace vide. */
    public static function delete(User $user, ChatMessage $message): ChatMessage
    {
        abort_unless((int) $message->sender_id === (int) $user->id, 403, 'Vous ne pouvez supprimer que vos messages.');
        if ($message->deleted_at !== null) {
            return $message->load('reactions', 'replyTo');
        }
        $path = $message->photo_path;
        self::change($message, function () use ($message) {
            ChatReaction::where('message_id', $message->id)->delete();
            $message->forceFill(['body' => null, 'photo_path' => null, 'photo_width' => null, 'photo_height' => null, 'deleted_at' => now()]);
        });
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return $message->load('reactions', 'replyTo');
    }

    /** Pose, change ou retire (emoji null) sa reaction a un message. */
    public static function react(User $user, ChatMessage $message, ?string $emoji): ChatMessage
    {
        abort_if($message->deleted_at !== null, 422, 'Ce message a été supprimé.');
        abort_unless(self::canSend($user, $message->conversation), 403, 'Vous ne pouvez plus réagir dans cette discussion.');
        if ($emoji !== null && ! in_array($emoji, self::REACTIONS, true)) {
            throw ValidationException::withMessages(['emoji' => 'Réaction inconnue.']);
        }
        self::change($message, function () use ($user, $message, $emoji) {
            if ($emoji === null) {
                ChatReaction::where('message_id', $message->id)->where('user_id', $user->id)->delete();
            } else {
                ChatReaction::updateOrCreate(['message_id' => $message->id, 'user_id' => $user->id], ['emoji' => $emoji]);
            }
        });

        return $message->load('reactions', 'replyTo');
    }

    /** Applique un changement a un message et avance le compteur de la discussion (les autres ecrans le verront). */
    private static function change(ChatMessage $message, callable $apply): void
    {
        DB::transaction(function () use ($message, $apply) {
            $locked = ChatConversation::whereKey($message->conversation_id)->lockForUpdate()->firstOrFail();
            $apply();
            $message->forceFill(['rev' => $locked->rev + 1])->save();
            $locked->forceFill(['rev' => $locked->rev + 1])->save();
        });
    }

    /**
     * Marque la discussion comme lue jusqu'a ce message (jamais en arriere, jamais au-dela du dernier message).
     * Tout lu : la notification de cette discussion dans la cloche est marquee lue aussi.
     *
     * @return array{0: int, 1: int} dernier message lu, notifications de la cloche marquees lues
     */
    public static function markRead(User $user, ChatConversation $conversation, int $messageId): array
    {
        $me = self::participant($user, $conversation);
        $last = (int) ($conversation->last_message_id ?? 0);
        $upTo = min($messageId, $last);
        if ($upTo > $me->last_read_message_id) {
            $me->forceFill(['last_read_message_id' => $upTo])->save();
        }
        $cleared = $me->last_read_message_id >= $last ? self::clearBell((int) $user->id, (int) $conversation->id) : 0;

        return [(int) $me->last_read_message_id, $cleared];
    }

    /** Notification de la cloche de cette discussion : lue. Retourne le nombre de notifications marquees. */
    private static function clearBell(int $userId, int $conversationId): int
    {
        return DB::table('user_notifications')->where('user_id', $userId)->where('type', 'chat')
            ->where('url', '/chat/'.$conversationId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public static function typing(User $user, ChatConversation $conversation): void
    {
        self::seen($user, $conversation);
        if (self::canSend($user, $conversation)) {
            Cache::put(self::typingKey($conversation->id, (int) $user->id), 1, self::TYPING_SECONDS);
        }
    }

    /**
     * Signalement : le membre choisit de montrer CE message au Pasteur Resident (lui seul le recoit, avec le motif).
     * Le reste de la discussion demeure prive.
     */
    public static function report(User $user, ChatMessage $message, string $reason): void
    {
        abort_if((int) $message->sender_id === (int) $user->id, 422, 'Vous ne pouvez pas signaler votre propre message.');
        abort_if($message->deleted_at !== null, 422, 'Ce message a été supprimé.');
        $key = "chat:reported:{$message->id}:{$user->id}";
        abort_unless(Cache::add($key, 1, now()->addDay()), 422, 'Vous avez déjà signalé ce message.');

        $conversation = $message->conversation;
        $people = self::profiles([(int) $user->id, (int) $message->sender_id]);
        $where = $conversation->isGroup() ? ($conversation->gem?->label() ?? 'GEM') : 'discussion privée';
        $content = $message->kind === ChatMessage::PHOTO
            ? '(photo)'.($message->body ? ' « '.mb_substr((string) $message->body, 0, 300).' »' : '')
            : '« '.mb_substr((string) $message->body, 0, 300).' »';
        Notifier::send(MemberDeletionService::residentPastors(), 'chat_report', 'Message signalé · '.$where,
            ($people->get($user->id)?->full_name ?: 'Un membre').' signale un message de '
            .($people->get($message->sender_id)?->full_name ?: 'un membre').' du '.$message->created_at->locale('fr')->isoFormat('D MMMM à HH:mm')
            .' : '.$content.' Motif : '.$reason, '/notifications', [], 'high');
        Audit::log('chat.message_reported', null, (int) $message->sender_id, [], [], ['where' => $where], $user);
    }

    // ------------------------------------------------------------------ Notifications

    /**
     * Previent les autres participants par les notifications generales de l'application :
     * - la cloche : UNE notification par discussion tant qu'elle n'est pas lue, mise a jour a chaque message
     *   (« 3 nouveaux messages ») au lieu d'en empiler ; elle se marque lue des que la discussion est lue ;
     * - le telephone : un push par discussion (le suivant remplace le precedent), sauf si le membre a coupe
     *   « Messages du chat » dans ses preferences ; une rafale ne fait vibrer qu'une fois.
     * Rien pour celui qui a la discussion ouverte a l'ecran. Le texte du message n'est jamais dans la
     * notification (il resterait en clair dans la base et sur l'ecran verrouille).
     */
    private static function notify(User $sender, ChatConversation $conversation, ChatMessage $message): void
    {
        $recipients = ChatParticipant::where('conversation_id', $conversation->id)->where('user_id', '!=', $sender->id)
            ->pluck('user_id')->map(fn ($id) => (int) $id);
        if ($recipients->isEmpty()) {
            return;
        }
        $name = $sender->profile?->full_name ?: 'Un membre';
        $first = $sender->profile?->first_name ?: $name;
        $what = $message->kind === ChatMessage::PHOTO ? 'Photo' : 'Nouveau message';
        $title = $conversation->isGroup() ? ($conversation->gem?->label() ?? 'GEM') : $name;
        $one = $conversation->isGroup() ? $first.' : '.mb_strtolower($what) : $what;
        $url = '/chat/'.$conversation->id;
        $now = now();

        $push = [];
        foreach (User::whereIn('id', $recipients)->whereNull('blocked_at')->get(['id', 'approval_status', 'notification_prefs']) as $user) {
            if (! $user->isApproved() || Cache::has(self::viewingKey($conversation->id, (int) $user->id))) {
                continue;
            }
            $open = DB::table('user_notifications')->where('user_id', $user->id)->where('type', 'chat')->where('url', $url)
                ->whereNull('read_at')->orderByDesc('id')->first(['id', 'data']);
            $count = (int) (json_decode((string) ($open->data ?? ''), true)['count'] ?? 0) + 1;
            $row = [
                'title' => mb_substr($title, 0, 250),
                'body' => $count > 1 ? $count.' nouveaux messages' : $one,
                'data' => json_encode(['conversation_id' => $conversation->id, 'count' => $count]),
                'created_at' => $now,
            ];
            if ($open) {
                DB::table('user_notifications')->where('id', $open->id)->update($row);
            } else {
                DB::table('user_notifications')->insert($row + ['user_id' => $user->id, 'type' => 'chat', 'priority' => 'normal', 'url' => $url]);
            }
            if ((($user->notification_prefs ?? [])['chat'] ?? true) !== false
                && Cache::add("chat:pushed:{$conversation->id}:{$user->id}", 1, self::PUSH_QUIET_SECONDS)) {
                $push[] = (int) $user->id;
            }
        }
        if ($push) {
            Notifier::pushOnly($push, [
                'title' => $title,
                'body' => $one,
                'url' => $url,
                'type' => 'chat',
                // Une seule notification par discussion sur le telephone : la suivante remplace la precedente.
                'tag' => 'chat-'.$conversation->id,
            ]);
        }
    }

    // ------------------------------------------------------------------ Fichiers

    /** Chemin de la photo d'un message sur le disque prive (null si absente). */
    public static function photoPath(ChatMessage $message): ?string
    {
        return $message->photo_path && $message->deleted_at === null && Storage::disk('local')->exists($message->photo_path)
            ? Storage::disk('local')->path($message->photo_path) : null;
    }

    /** Photos a effacer avant la suppression d'un compte : ses messages et ses discussions privees entieres. */
    public static function photoPathsOfUser(int $userId): array
    {
        $directs = ChatConversation::where('type', ChatConversation::DIRECT)
            ->where(fn ($q) => $q->where('user_low_id', $userId)->orWhere('user_high_id', $userId))->pluck('id');

        return ChatMessage::whereNotNull('photo_path')
            ->where(fn ($q) => $q->where('sender_id', $userId)->orWhereIn('conversation_id', $directs))
            ->pluck('photo_path')->all();
    }

    /** Photos de la discussion de groupe d'un GEM (a effacer avec le GEM). */
    public static function photoPathsOfGem(int $gemId): array
    {
        return ChatMessage::whereNotNull('photo_path')
            ->whereIn('conversation_id', ChatConversation::where('gem_id', $gemId)->select('id'))->pluck('photo_path')->all();
    }

    /** @param array<int, string> $paths */
    public static function deletePhotos(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
                // fichier deja absent
            }
        }
    }

    // ------------------------------------------------------------------ Presentation

    /** @param Collection<int, ChatMessage> $messages */
    public static function presentMany(Collection $messages, User $viewer): array
    {
        return $messages->map(fn (ChatMessage $m) => self::present($m, $viewer))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function present(ChatMessage $m, User $viewer): array
    {
        $deleted = $m->deleted_at !== null;
        $reply = $m->reply_to_id ? $m->replyTo : null;

        return [
            'id' => $m->id,
            'client_id' => (int) $m->sender_id === (int) $viewer->id ? $m->client_id : null,
            'sender' => self::key((int) $m->sender_id),
            'mine' => (int) $m->sender_id === (int) $viewer->id,
            'kind' => $m->kind,
            'body' => $deleted ? null : $m->body,
            'photo' => ! $deleted && $m->photo_path ? ['width' => (int) $m->photo_width, 'height' => (int) $m->photo_height] : null,
            'reply' => $reply ? [
                'id' => $reply->id,
                'sender' => self::key((int) $reply->sender_id),
                'kind' => $reply->kind,
                'deleted' => $reply->deleted_at !== null,
                'text' => self::excerpt($reply, 120),
            ] : null,
            'reactions' => $deleted ? [] : $m->reactions->groupBy('emoji')
                ->map(fn (Collection $rows, string $emoji) => [
                    'emoji' => $emoji,
                    'by' => $rows->map(fn (ChatReaction $r) => self::key((int) $r->user_id))->values()->all(),
                ])->values()->all(),
            'edited' => ! $deleted && $m->edited_at !== null,
            'forwarded' => ! $deleted && (bool) $m->forwarded,
            'deleted' => $deleted,
            'at' => $m->created_at?->toIso8601String(),
            'rev' => (int) $m->rev,
        ];
    }

    /** Apercu court d'un message (liste des discussions, citation d'une reponse). */
    private static function excerpt(ChatMessage $m, int $length): string
    {
        if ($m->deleted_at !== null) {
            return 'Message supprimé';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $m->body));
        if ($m->kind === ChatMessage::PHOTO) {
            return $text !== '' ? mb_strimwidth($text, 0, $length, '…') : 'Photo';
        }

        return mb_strimwidth($text, 0, $length, '…');
    }

    /**
     * Personnes a afficher dans une discussion : ses participants actuels et les auteurs des messages rendus
     * (un ancien membre du GEM garde son nom sur ses messages). Cle opaque => nom, photo, Garde, moi.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<string, array<string, mixed>>
     */
    private static function people(User $viewer, ChatConversation $conversation, Collection $messages): array
    {
        $current = ChatParticipant::where('conversation_id', $conversation->id)->pluck('user_id')->map(fn ($id) => (int) $id);
        $ids = $current->merge($messages->pluck('sender_id'))
            ->merge($messages->map(fn (ChatMessage $m) => $m->replyTo?->sender_id))->filter()->map(fn ($id) => (int) $id)->unique();
        $profiles = self::profiles($ids->all());
        $garde = $conversation->isGroup() ? (int) ($conversation->gem?->leader_user_id ?? 0) : 0;
        $present = $current->flip();

        return $ids->mapWithKeys(fn (int $id) => [self::key($id) => [
            'name' => $profiles->get($id)?->full_name ?: 'Ancien membre',
            'first_name' => $profiles->get($id)?->first_name ?: 'Ancien membre',
            'photo_url' => $profiles->get($id)?->photo_url,
            'is_garde' => $garde !== 0 && $id === $garde,
            'is_me' => $id === (int) $viewer->id,
            'present' => isset($present[$id]),
        ]])->all();
    }

    /**
     * @param  array<int>  $userIds
     * @return Collection<int, Profile> par identifiant de membre
     */
    private static function profiles(array $userIds): Collection
    {
        return Profile::whereIn('user_id', $userIds ?: [0])->get(['id', 'user_id', 'first_name', 'last_name', 'photo_path'])->keyBy('user_id');
    }

    /** Texte saisi : fins de ligne unifiees, caracteres invisibles retires, espaces de bord coupes. */
    private static function clean(?string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", (string) $body);
        $body = (string) preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body);
        $body = (string) preg_replace("/\n{4,}/", "\n\n\n", $body);

        return trim($body);
    }

    // ------------------------------------------------------------------ Presence (memoire courte, rien en base)

    /** Le membre a le chat ouvert : « en ligne » pour ses interlocuteurs, et pas de notification pour la discussion a l'ecran. */
    private static function seen(User $user, ?ChatConversation $conversation = null): void
    {
        Cache::put('chat:online:'.$user->id, 1, self::ONLINE_SECONDS);
        if ($conversation) {
            Cache::put(self::viewingKey($conversation->id, (int) $user->id), 1, self::VIEWING_SECONDS);
        }
    }

    private static function isOnline(int $userId): bool
    {
        return Cache::has('chat:online:'.$userId);
    }

    private static function typingKey(int $conversationId, int $userId): string
    {
        return "chat:typing:{$conversationId}:{$userId}";
    }

    private static function viewingKey(int $conversationId, int $userId): string
    {
        return "chat:viewing:{$conversationId}:{$userId}";
    }
}
