<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\DashboardVerse;
use App\Models\Department;
use App\Models\Event;
use App\Models\Exercise;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\ResolutionResource;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\PlatformManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord de gestion (permission platform.manage) : uniquement des chiffres globaux sur les
 * comptes, les roles, l'organisation et les contenus. Aucune donnee personnelle ni aucun contenu
 * envoye par les fideles (FISS, Vertumetre, resolutions, reponses, rapports) n'y figure.
 */
class PlatformController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $managers = PlatformManager::userIds() ?: [0];
        $members = Profile::where('is_completed', true)->whereNotIn('user_id', $managers);
        $now = now();

        return response()->json([
            'accounts' => [
                'members' => (clone $members)->count(),
                'new_this_month' => (clone $members)->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
                'active' => User::exceptPlatformManagers()->where('activity_status', 'active')
                    ->whereHas('profile', fn ($p) => $p->where('is_completed', true))->count(),
                'pending' => User::exceptPlatformManagers()->where('approval_status', User::PENDING)->whereNotNull('approval_requested_at')->count(),
                'blocked' => User::exceptPlatformManagers()->whereNotNull('blocked_at')->count(),
            ],
            'roles' => [
                'total' => Role::count(),
                'custom' => Role::where('is_system', false)->count(),
                // Personnes ayant au moins une fonction (hors simple « Membre »).
                'holders' => DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->where('roles.key', '!=', 'fidele')->whereNotIn('role_user.user_id', $managers)
                    ->distinct()->count('role_user.user_id'),
            ],
            'organization' => [
                'tribes' => Tribe::where('is_active', true)->count(),
                'departments' => Department::where('is_active', true)->count(),
                'gems' => Gem::where('is_active', true)->count(),
            ],
            'content' => [
                'verses_live' => DashboardVerse::liveAt($now)->count(),
                'verses_draft' => DashboardVerse::where('status', 'draft')->count(),
                'resources_published' => ResolutionResource::where('status', 'published')->count(),
                'resources_draft' => ResolutionResource::where('status', 'draft')->count(),
                'resources_videos' => ResolutionResource::whereNotNull('youtube_id')->count(),
                'assignments' => Assignment::published()->count(),
                'announcements_30d' => Announcement::where('created_at', '>=', $now->copy()->subDays(30))->count(),
                'events_upcoming' => Event::where('is_personal', false)->where('starts_at', '>=', $now)->count(),
                'exercises_open' => Exercise::where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $now))->count(),
            ],
        ]);
    }
}
