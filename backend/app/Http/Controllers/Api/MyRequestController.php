<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberRequest;
use App\Services\Notifier;
use App\Support\Recipients;
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

        $user = $request->user();
        $req = MemberRequest::create($data + ['user_id' => $user->id, 'status' => 'nouvelle']);

        // Les responsables qui traitent les demandes de ce fidele sont prevenus.
        $name = $user->profile?->full_name ?: $user->phone;
        Notifier::send(Recipients::followersOf($user, 'requests.handle'), 'request',
            'Nouvelle demande : '.(self::CATEGORIES[$req->category] ?? 'Demande'),
            $name.' · '.mb_substr($req->subject ?: $req->message, 0, 140), '/admin/demandes',
            ['request_id' => $req->id], $req->category === 'priere' || $req->category === 'aide' ? 'high' : 'normal');

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
