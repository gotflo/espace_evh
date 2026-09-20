<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /** Feuille de presence pour une date + un evenement : membres (dans la portee) + presents. */
    public function roster(Request $request): JsonResponse
    {
        $date = $request->query('date') ?: now()->toDateString();
        $event = $request->query('event') ?: 'Culte';
        $kind = $request->query('kind') === 'repetition' ? 'repetition' : 'culte';

        $profiles = $this->scopedProfiles($request->user());
        $memberIds = $profiles->pluck('user_id');

        $records = Attendance::where('attended_on', $date)->where('event', $event)->where('kind', $kind)
            ->whereIn('member_user_id', $memberIds)
            ->get()->keyBy('member_user_id');

        return response()->json([
            'date' => $date,
            'event' => $event,
            'kind' => $kind,
            'members' => $profiles->map(fn (Profile $p) => [
                'user_id' => $p->user_id,
                'full_name' => $p->full_name ?: '(profil incomplet)',
                'photo_url' => $p->photo_url,
                'tribe' => $p->tribe?->name,
                'present' => isset($records[$p->user_id]) && $records[$p->user_id]->status === 'present',
                'status' => $records[$p->user_id]->status ?? null,
            ])->values(),
        ]);
    }

    /** Enregistrer la presence d'une session (remplace ce qui existait pour cette date + evenement). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attended_on' => ['required', 'date', 'before_or_equal:today'],
            'event' => ['required', 'string', 'max:60'],
            'kind' => ['nullable', 'in:culte,repetition'],
            'present_user_ids' => ['array'],
            'present_user_ids.*' => ['integer'],
            'statuses' => ['array'], // repetition : { user_id: status }
        ]);

        $kind = $data['kind'] ?? 'culte';
        $rosterIds = $this->scopedProfiles($request->user())->pluck('user_id');
        $recordedBy = $request->user()->id;
        $valid = ['present', 'retard', 'absent_justifie', 'absent'];

        // Determine le statut de chaque membre selon le type de session.
        $rows = collect();
        if ($kind === 'repetition') {
            foreach (($data['statuses'] ?? []) as $uid => $status) {
                if ($rosterIds->contains((int) $uid) && in_array($status, $valid, true)) {
                    $rows->push(['uid' => (int) $uid, 'status' => $status]);
                }
            }
        } else {
            foreach (collect($data['present_user_ids'] ?? [])->intersect($rosterIds) as $uid) {
                $rows->push(['uid' => (int) $uid, 'status' => 'present']);
            }
        }

        DB::transaction(function () use ($data, $kind, $rosterIds, $rows, $recordedBy) {
            Attendance::where('attended_on', $data['attended_on'])
                ->where('event', $data['event'])->where('kind', $kind)
                ->whereIn('member_user_id', $rosterIds)
                ->delete();

            foreach ($rows as $r) {
                Attendance::create([
                    'member_user_id' => $r['uid'],
                    'attended_on' => $data['attended_on'],
                    'event' => $data['event'],
                    'kind' => $kind,
                    'status' => $r['status'],
                    'recorded_by' => $recordedBy,
                ]);
            }
        });

        $present = $rows->where('status', 'present')->count();

        return response()->json(['message' => "Session enregistrée ({$présent} présent(s))."]);
    }

    /** Profils visibles par l'utilisateur (toute l'eglise ou sa portee). */
    private function scopedProfiles(User $user)
    {
        $query = Profile::with(['tribe'])->has('user');

        if (! $user->hasPermission('members.view_all')) {
            $tribeIds = $user->roles->where('pivot.scope_kind', 'tribe')->pluck('pivot.scope_id')->filter()->all();
            $deptIds = $user->roles->where('pivot.scope_kind', 'department')->pluck('pivot.scope_id')->filter()->all();
            $query->where(function ($sub) use ($tribeIds, $deptIds) {
                $sub->whereIn('tribe_id', $tribeIds ?: [0])
                    ->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $deptIds ?: [0]));
            });
        }

        return $query->orderBy('last_name')->orderBy('first_name')->get();
    }
}
