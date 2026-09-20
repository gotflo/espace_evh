<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MyAnnouncementController extends Controller
{
    /** Fil des annonces recues par le fidele (avec etat lu). */
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->receivedAnnouncements()
            ->orderByDesc('announcements.created_at')->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'image_url' => $a->image_url,
                'category' => $a->category,
                'created_at' => $a->created_at->toDateString(),
                'read' => $a->pivot->read_at !== null,
            ]);

        return response()->json([
            'announcements' => $items,
            'unread' => $items->where('read', false)->count(),
        ]);
    }

    /** Nombre d'annonces non lues (pour la cloche). */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = DB::table('announcement_user')
            ->where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    /** Marquer toutes les annonces comme lues. */
    public function markRead(Request $request): JsonResponse
    {
        DB::table('announcement_user')
            ->where('user_id', $request->user()->id)->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
