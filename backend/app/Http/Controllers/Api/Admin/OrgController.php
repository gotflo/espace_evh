<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Tribe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrgController extends Controller
{
    /** Tribus et departements avec le nombre de membres. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('tribes.manage') && ! $user->hasPermission('departments.manage')) {
            abort(403, 'Accès refusé.');
        }

        return response()->json([
            'tribes' => Tribe::withCount('members')->orderBy('name')->get()
                ->map(fn (Tribe $t) => ['id' => $t->id, 'name' => $t->name, 'members_count' => $t->members_count]),
            'departments' => Department::withCount('members')->with('leaders.profile:id,user_id,first_name,last_name')->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id, 'name' => $d->name, 'members_count' => $d->members_count, 'tracks_rehearsal' => $d->tracks_rehearsal,
                    'description' => $d->description, 'is_active' => $d->is_active,
                    'leaders' => $d->leaders->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->profile?->full_name ?: $u->phone])->values(),
                ]),
        ]);
    }

    public function storeTribe(Request $request): JsonResponse
    {
        $name = $this->validateName($request);
        Tribe::create(['name' => $name, 'slug' => $this->uniqueSlug(Tribe::class, $name)]);

        return response()->json(['message' => 'Tribu créée.']);
    }

    public function updateTribe(Request $request, Tribe $tribe): JsonResponse
    {
        $tribe->update(['name' => $this->validateName($request)]);

        return response()->json(['message' => 'Tribu renommée.']);
    }

    public function destroyTribe(Tribe $tribe): JsonResponse
    {
        $tribe->delete();

        return response()->json(['message' => 'Tribu supprimée.']);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $name = $this->validateName($request);
        Department::create(['name' => $name, 'slug' => $this->uniqueSlug(Department::class, $name)]);

        return response()->json(['message' => 'Département créé.']);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'tracks_rehearsal' => ['sometimes', 'boolean'],
        ]);
        $department->update($data);

        return response()->json(['message' => 'Département mis à jour.']);
    }

    /**
     * Responsables d'un departement : ils recoivent les droits de responsable sur ce departement
     * (voir ses membres, faire l'appel des repetitions, publier ses evenements). Ils doivent en etre membres.
     */
    public function setDepartmentLeaders(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:5'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        $memberIds = $department->members()->pluck('profiles.user_id')->map(fn ($id) => (int) $id)->all();
        foreach ($ids as $id) {
            abort_unless(in_array($id, $memberIds, true), 422, 'Un responsable doit d\'abord être membre du département.');
        }
        $before = $department->leaders()->pluck('users.id')->sort()->values()->all();
        $department->leaders()->sync($ids);
        \App\Support\Audit::log('department.leaders_updated', $department, null, ['leaders' => $before], ['leaders' => $ids]);
        foreach (array_diff($ids, $before) as $newLeader) {
            \App\Services\Notifier::send([$newLeader], 'role', "Responsable du département {$department->name}",
                'Vous pouvez désormais suivre ses membres, faire l\'appel des répétitions et publier ses événements.', '/tableau-de-bord');
        }

        return response()->json(['message' => 'Responsables du département mis à jour.']);
    }

    /** Membres d'un departement (pour choisir ses responsables). */
    public function departmentMembers(Department $department): JsonResponse
    {
        return response()->json(['members' => $department->members()->orderBy('first_name')->get(['profiles.user_id', 'first_name', 'last_name'])
            ->map(fn ($p) => ['user_id' => $p->user_id, 'name' => trim($p->first_name.' '.$p->last_name)])->values()]);
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        \App\Support\Audit::log('department.deleted', $department, null, ['name' => $department->name], [], ['member_profile_ids' => $department->members()->pluck('profiles.id')]);
        $department->delete();

        return response()->json(['message' => 'Département supprimé.']);
    }

    private function validateName(Request $request): string
    {
        return $request->validate(['name' => ['required', 'string', 'max:60']])['name'];
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
