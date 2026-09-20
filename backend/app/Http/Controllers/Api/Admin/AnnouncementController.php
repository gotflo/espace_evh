<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Tribe;
use App\Support\Audience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public const CATEGORIES = ['info' => 'Information', 'important' => 'Important', 'evenement' => 'Evenement'];

    public function index(): JsonResponse
    {
        $items = Announcement::withCount('recipients')->with('creator.profile')->latest()->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'category' => $a->category,
                'image_url' => $a->image_url,
                'target' => $this->targetLabel($a),
                'recipients_count' => $a->recipients_count,
                'created_at' => $a->created_at->toDateString(),
                'author' => $a->creator?->profile?->full_name ?: null,
            ]);

        return response()->json(['announcements' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:8192'], // 8 Mo
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'target_type' => ['required', 'in:all,tribe,department,gem'],
            'target_id' => ['nullable', 'integer'],
        ]);

        // Une annonce doit avoir au moins un contenu : titre, texte ou image.
        if (blank($data['title'] ?? null) && blank($data['body'] ?? null) && ! $request->hasFile('image')) {
            abort(422, 'Ajoutez au moins un titre, un texte ou une image.');
        }

        // Un responsable restreint ne diffuse qu'a sa propre portee (GEM / tribu / dept).
        if (\App\Support\MemberScope::isScoped($request->user())) {
            [$data['target_type'], $data['target_id']] = \App\Support\MemberScope::primaryScope($request->user());
        }

        if ($data['target_type'] === 'tribe' && ! Tribe::whereKey($data['target_id'] ?? null)->exists()) {
            abort(422, 'Tribu invalide.');
        }
        if ($data['target_type'] === 'department' && ! Department::whereKey($data['target_id'] ?? null)->exists()) {
            abort(422, 'Departement invalide.');
        }

        $announcement = Announcement::create([
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'image_path' => $request->hasFile('image') ? $request->file('image')->store('announcements', 'public') : null,
            'category' => $data['category'],
            'target_type' => $data['target_type'],
            'target_id' => $data['target_type'] === 'all' ? null : $data['target_id'],
            'created_by' => $request->user()->id,
        ]);

        // Diffusion : une "notification" (ligne pivot) par destinataire, sauf l'auteur.
        $recipients = collect(Audience::forActor($request->user(), $data['target_type'], $data['target_id'] ?? null))
            ->reject(fn ($id) => $id === $request->user()->id)->values();
        $announcement->recipients()->attach($recipients->all());

        return response()->json(['message' => 'Annonce publiee ('.$recipients->count().' destinataire(s)).']);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        if ($announcement->image_path) {
            \Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->delete();

        return response()->json(['message' => 'Annonce supprimee.']);
    }

    private function targetLabel(Announcement $a): string
    {
        return match ($a->target_type) {
            'tribe' => 'Tribu '.(Tribe::find($a->target_id)?->name ?? '?'),
            'department' => 'Dept. '.(Department::find($a->target_id)?->name ?? '?'),
            default => "Toute l'eglise",
        };
    }
}
