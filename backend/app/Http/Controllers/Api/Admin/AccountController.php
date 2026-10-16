<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Tribe;
use App\Models\User;
use App\Support\DepartmentLeadership;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comptes (permission accounts.view) : de quoi administrer les acces, sans la fiche du membre.
 * Seulement l'identite utile (nom, tribu), le telephone MASQUE et les fonctions. Le compte de gestion
 * principal et les inscriptions non terminees n'y figurent pas.
 */
class AccountController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $q = trim((string) ($data['q'] ?? ''));

        $query = User::exceptPlatformManagers()
            ->whereHas('profile', fn ($p) => $p->where('is_completed', true))
            ->when($q !== '', fn ($w) => $w->whereHas('profile', function ($p) use ($q) {
                // Chaque mot doit se trouver dans le prenom ou le nom (« marie trem » trouve Marie Tremblay).
                foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 4) as $word) {
                    $p->where(function ($s) use ($word) {
                        Like::contains($s, 'first_name', $word);
                        Like::contains($s, 'last_name', $word, 'or');
                    });
                }
            }))
            ->when(! empty($data['role']), fn ($w) => $w->whereHas('roles', fn ($r) => $r->where('key', $data['role'])));
        $total = (clone $query)->count();

        $users = $query->with(['profile:id,user_id,first_name,last_name,tribe_id,photo_path', 'profile.tribe:id,name', 'roles:id,key,name', 'ledDepartments:id,name'])
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->orderBy('profiles.last_name')->orderBy('profiles.first_name')
            ->select('users.id', 'users.phone', 'users.activity_status', 'users.activity_override', 'users.blocked_at')
            ->forPage($page, self::PER_PAGE)->get();

        $tribes = Tribe::pluck('name', 'id');
        $gems = Gem::pluck('name', 'id');
        $departments = Department::pluck('name', 'id');
        $members = Profile::whereIn('user_id', $users->flatMap(fn ($u) => $u->roles->where('pivot.scope_kind', 'member')->pluck('pivot.scope_id'))->filter()->all())
            ->get(['user_id', 'first_name', 'last_name'])->keyBy('user_id');

        return response()->json([
            'accounts' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->profile?->full_name,
                'first_name' => $u->profile?->first_name,
                'last_name' => $u->profile?->last_name,
                'photo_url' => $u->profile?->photo_url,
                'tribe' => $u->profile?->tribe?->name,
                'tribe_id' => $u->profile?->tribe_id,
                'phone' => self::mask($u->phone),
                'status' => $u->blocked_at ? 'blocked' : $u->activityStatus(),
                'roles' => $u->roles->map(fn ($r) => [
                    'assignment_id' => $r->pivot->id,
                    'key' => $r->key,
                    'name' => $r->name,
                    'scope_kind' => $r->pivot->scope_kind,
                    'scope_name' => match ($r->pivot->scope_kind) {
                        'tribe' => $tribes[$r->pivot->scope_id] ?? null,
                        'gem' => $gems[$r->pivot->scope_id] ?? null,
                        'department' => $departments[$r->pivot->scope_id] ?? null,
                        'member' => $members[$r->pivot->scope_id]?->full_name ?? null,
                        default => null,
                    },
                ])->values(),
                // Deja affiche par le role « Responsable de departement » sur ce departement : pas de doublon.
                'led_departments' => $u->ledDepartments->reject(fn ($d) => $u->roles->contains(fn ($r) => DepartmentLeadership::isLeaderRole($r, $r->pivot->scope_kind)
                    && (int) $r->pivot->scope_id === (int) $d->id))->pluck('name')->values(),
            ])->values(),
            'total' => $total,
            'page' => $page,
            'has_more' => $page * self::PER_PAGE < $total,
        ]);
    }

    /** Telephone masque : les 2 derniers chiffres seulement (« •••• 76 »). */
    public static function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return '•••• '.substr($phone, -2);
    }
}
