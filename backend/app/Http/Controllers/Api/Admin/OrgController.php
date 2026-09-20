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
            abort(403, 'Accès refuse.');
        }

        return response()->json([
            'tribes' => Tribe::withCount('members')->orderBy('name')->get()
                ->map(fn (Tribe $t) => ['id' => $t->id, 'name' => $t->name, 'members_count' => $t->members_count]),
            'departments' => Department::withCount('members')->orderBy('name')->get()
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'members_count' => $d->members_count, 'tracks_rehearsal' => $d->tracks_rehearsal]),
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

        return response()->json(['message' => 'Tribu renommee.']);
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

    public function destroyDepartment(Department $department): JsonResponse
    {
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
