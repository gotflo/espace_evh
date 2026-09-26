<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Destinataires que l'utilisateur peut choisir pour une annonce, un evenement ou un exercice. */
class AudienceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('announcements.publish') || $user->hasPermission('events.manage') || $user->hasPermission('exercises.assign'), 403);

        return response()->json(Audience::options($user));
    }
}
