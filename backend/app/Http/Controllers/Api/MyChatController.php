<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Chat des GEMs (espace membre) : discussion de groupe du GEM et discussions privees entre ses membres.
 * Toutes les regles d'acces sont dans ChatService ; une discussion ou un message hors de portee repond
 * « introuvable ». Aucune adresse d'administration : seuls les participants lisent.
 */
class MyChatController extends Controller
{
    /** Liste des discussions et des membres a qui ecrire. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(ChatService::inbox($request->user()));
    }

    /** Nombre de messages non lus (pastille du menu). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => ChatService::unreadTotal($request->user())]);
    }

    /** Ouvre la discussion privee avec un membre de son GEM (cle opaque de la liste). */
    public function direct(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'regex:/^[a-f0-9]{20}$/']]);

        return response()->json(['conversation_id' => ChatService::directWith($request->user(), $data['key'])->id]);
    }

    /** Une page de messages : les plus recents, ou ?before=, ?after=, ?around= (message retrouve par la recherche). */
    public function show(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:1'],
            'around' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        $int = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;

        return response()->json(ChatService::page($user, ChatService::find($user, $conversation), $int('before'), $int('after'), $int('around')));
    }

    /** Recherche dans une discussion (2 caracteres au moins). */
    public function search(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);
        $user = $request->user();
        $query = trim($data['q']);
        abort_if(mb_strlen($query) < 2, 422, 'Écrivez au moins 2 caractères.');

        return response()->json(ChatService::search($user, ChatService::find($user, $conversation), $query));
    }

    /** Transfere un message vers une autre de ses discussions, ou vers un membre de son GEM (cle). */
    public function forward(Request $request, string $message): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,40}$/'],
            'conversation_id' => ['nullable', 'integer', 'min:1', 'required_without:key'],
            'key' => ['nullable', 'string', 'regex:/^[a-f0-9]{20}$/', 'required_without:conversation_id'],
        ]);
        $user = $request->user();
        $source = ChatService::message($user, $message);
        $target = ! empty($data['conversation_id'])
            ? ChatService::find($user, (string) $data['conversation_id'])
            : ChatService::directWith($user, (string) $data['key']);
        $sent = ChatService::forward($user, $source, $target, $data['client_id']);

        return response()->json([
            'message' => ChatService::present($sent, $user),
            'conversation_id' => $target->id,
        ], $sent->wasRecentlyCreated ? 201 : 200);
    }

    /** Mise a jour en direct : ce qui a change depuis ?rev=. */
    public function sync(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate(['rev' => ['required', 'integer', 'min:0']]);
        $user = $request->user();

        return response()->json(ChatService::sync($user, ChatService::find($user, $conversation), (int) $data['rev']));
    }

    public function send(Request $request, string $conversation): JsonResponse
    {
        $user = $request->user();
        $chat = ChatService::find($user, $conversation);
        $data = $request->validate([
            'client_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,40}$/'],
            'body' => ['nullable', 'string', 'max:'.(ChatService::MAX_BODY * 4)],
            'reply_to' => ['nullable', 'integer', 'min:1'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'], // 8 Mo avant reduction
        ]);
        $message = ChatService::send($user, $chat, $data, $request->file('photo'));

        return response()->json([
            'message' => ChatService::present($message, $user),
            'rev' => (int) $chat->refresh()->rev,
        ], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function edit(Request $request, string $message): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:'.(ChatService::MAX_BODY * 4)]]);
        $user = $request->user();
        $edited = ChatService::edit($user, ChatService::message($user, $message), $data['body'] ?? null);

        return response()->json(['message' => ChatService::present($edited, $user)]);
    }

    public function destroy(Request $request, string $message): JsonResponse
    {
        $user = $request->user();
        $deleted = ChatService::delete($user, ChatService::message($user, $message));

        return response()->json(['message' => ChatService::present($deleted, $user)]);
    }

    public function react(Request $request, string $message): JsonResponse
    {
        $data = $request->validate(['emoji' => ['present', 'nullable', 'string', Rule::in(ChatService::REACTIONS)]]);
        $user = $request->user();
        $changed = ChatService::react($user, ChatService::message($user, $message), $data['emoji']);

        return response()->json(['message' => ChatService::present($changed, $user)]);
    }

    public function read(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);
        $user = $request->user();
        [$upTo, $cleared] = ChatService::markRead($user, ChatService::find($user, $conversation), (int) $data['message_id']);

        // notifications_read : la cloche de l'application baisse d'autant, sans attendre le prochain pouls.
        return response()->json(['last_read_id' => $upTo, 'unread_total' => ChatService::unreadTotal($user), 'notifications_read' => $cleared]);
    }

    public function typing(Request $request, string $conversation): JsonResponse
    {
        $user = $request->user();
        ChatService::typing($user, ChatService::find($user, $conversation));

        return response()->json(['ok' => true]);
    }

    /** Photo d'un message : servie aux seuls participants (aucune adresse publique). */
    public function photo(Request $request, string $message): BinaryFileResponse
    {
        $user = $request->user();
        $path = ChatService::photoPath(ChatService::message($user, $message));
        abort_unless($path, 404, 'Photo introuvable.');

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Signale un message au Pasteur Resident (ce message seulement, avec le motif). */
    public function report(Request $request, string $message): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $user = $request->user();
        ChatService::report($user, ChatService::message($user, $message), trim($data['reason']));

        return response()->json(['message' => 'Message signalé au Pasteur Résident.']);
    }
}
