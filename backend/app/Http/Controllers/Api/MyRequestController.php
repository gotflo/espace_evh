<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyRequestController extends Controller
{
    public const CATEGORIES = [
        'rendez-vous' => 'Rendez-vous',
        'aide' => 'Besoin d\'aide',
        'priere' => 'Demande de prière',
        'question' => 'Question',
        'autre' => 'Autre',
    ];

    public const STATUS = [
        'nouvelle' => 'Nouvelle',
        'en_cours' => 'En cours',
        'traitee' => 'Traitee',
    ];

    /** Demandes envoyees par le fidele connecte. */
    public function index(Request $request): JsonResponse
    {
        $items = MemberRequest::where('user_id', $request->user()->id)
            ->latest()->get()
            ->map(fn (MemberRequest $r) => $this->present($r));

        return response()->json(['requests' => $items]);
    }

    /** Le fidele envoie une nouvelle demande. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $req = MemberRequest::create($data + ['user_id' => $request->user()->id, 'status' => 'nouvelle']);

        return response()->json(['message' => 'Demande envoyée.', 'request' => $this->present($req)], 201);
    }

    /** @return array<string, mixed> */
    private function present(MemberRequest $r): array
    {
        return [
            'id' => $r->id,
            'category' => $r->category,
            'category_label' => self::CATEGORIES[$r->category] ?? $r->category,
            'subject' => $r->subject,
            'message' => $r->message,
            'status' => $r->status,
            'status_label' => self::STATUS[$r->status] ?? $r->status,
            'reply' => $r->reply,
            'replied_at' => $r->replied_at?->toDateString(),
            'created_at' => $r->created_at->toDateString(),
        ];
    }
}
