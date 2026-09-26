<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    public const CATEGORIES = ['info' => 'Information', 'important' => 'Important', 'evenement' => 'Événement'];

    /** Annonces que l'utilisateur peut gerer (les siennes ou celles de sa portee). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = Announcement::withCount('recipients')->with('creator.profile', 'scopes')->latest()->limit(200)->get()
            ->filter(fn (Announcement $a) => Audience::canManage($user, $a->created_by, $a->audienceList()))
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'category' => $a->category,
                'image_url' => $a->image_url,
                'target' => $a->audienceLabel(),
                'scopes' => $a->audienceList(),
                'recipients_count' => $a->recipients_count,
                'created_at' => $a->created_at->toDateString(),
                'author' => $a->creator?->profile?->full_name ?: null,
            ])->values();

        return response()->json(['announcements' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:8192'], // 8 Mo
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
        ]);

        // Une annonce doit avoir au moins un contenu : titre, texte ou image.
        if (blank($data['title'] ?? null) && blank($data['body'] ?? null) && ! $request->hasFile('image')) {
            abort(422, 'Ajoutez au moins un titre, un texte ou une image.');
        }

        // Portee : une ou plusieurs tribus / GEMs / departements, controlee par les droits de l'auteur.
        $author = $request->user();
        $scopes = Audience::resolve($author, Audience::fromRequest($request));

        [$announcement, $recipients] = DB::transaction(function () use ($data, $request, $author, $scopes) {
            $announcement = Announcement::create([
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? null,
                'image_path' => $request->hasFile('image') ? $request->file('image')->store('announcements', 'public') : null,
                'category' => $data['category'],
                'created_by' => $author->id,
            ]);
            $announcement->syncScopes($scopes);

            // Diffusion : une ligne de reception par destinataire, sauf l'auteur.
            $recipients = array_values(array_diff(Audience::userIds($scopes), [$author->id]));
            $announcement->recipients()->attach($recipients);
            Audit::log('announcement.published', $announcement, null, [], ['title' => $announcement->title, 'scopes' => $scopes, 'recipients' => count($recipients)]);

            return [$announcement, $recipients];
        });

        Notifier::send(
            $recipients,
            'announcement',
            $announcement->title ?: ($data['category'] === 'important' ? 'Annonce importante' : 'Nouvelle annonce'),
            $announcement->body ? mb_substr($announcement->body, 0, 200) : null,
            '/tableau-de-bord#annonces',
            ['announcement_id' => $announcement->id],
            $data['category'] === 'important' ? 'high' : 'normal',
        );

        return response()->json(['message' => 'Annonce publiée ('.count($recipients).' destinataire(s)).']);
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless(Audience::canManage($request->user(), $announcement->created_by, $announcement->audienceList()), 403, 'Cette annonce est hors de votre périmètre.');
        Audit::log('announcement.deleted', $announcement, null, ['title' => $announcement->title, 'scopes' => $announcement->audienceList()]);
        if ($announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->scopes()->delete();
        $announcement->delete();

        return response()->json(['message' => 'Annonce supprimée.']);
    }
}
