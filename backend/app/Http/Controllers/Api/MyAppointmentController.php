<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** « Prendre rendez-vous avec le pasteur » : seances et creneaux libres, demande, annulation. */
class MyAppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $mine = Appointment::where('user_id', $me->id)->with('host.profile')->latest('id')->limit(12)->get();
        // Pasteurs qui prennent des rendez-vous (un compte ferme n'est pas propose), et celui d'une demande en cours.
        $withActive = $mine->filter(fn (Appointment $a) => $a->isActive())->pluck('host_id')->all();
        $hosts = User::whereIn('id', AppointmentService::hostIds())->where('id', '!=', $me->id)->with('profile')->get()
            ->map(fn (User $h) => AppointmentService::hostPayload($h))
            ->filter(fn (array $h) => $h['accepting'] || in_array($h['id'], $withActive, true))->sortByDesc('accepting')->values();
        $wanted = (int) $request->query('host');
        abort_if($wanted && ! $hosts->contains('id', $wanted), 404, 'Ce pasteur ne reçoit pas de rendez-vous.');
        $hostId = $wanted ?: (int) (collect($withActive)->first(fn ($id) => $hosts->contains('id', $id)) ?? $hosts->first()['id'] ?? 0);

        $active = $mine->first(fn (Appointment $a) => $a->isActive() && $a->host_id === $hostId) ?? $mine->first(fn (Appointment $a) => $a->isActive());
        $accepting = $hostId && ($hosts->firstWhere('id', $hostId)['accepting'] ?? false);
        $sessions = $hostId && $accepting
            ? array_values(array_filter(
                AppointmentService::sessions($hostId, now(), now()->addDays(AppointmentService::HORIZON_DAYS), false),
                fn ($s) => ! $s['blocked'],
            ))
            : [];

        return response()->json([
            'hosts' => $hosts,
            'host_id' => $hostId ?: null,
            'accepting' => $accepting,
            'slot_minutes' => $hostId ? AppointmentService::settings($hostId)->slot_minutes : AppointmentService::DEFAULT_SLOT_MINUTES,
            'sessions' => $sessions,
            'active' => $active ? AppointmentService::present($active, true, false) + ['host' => $active->host?->profile?->full_name] : null,
            'history' => $mine->reject(fn (Appointment $a) => $a->isActive())->take(5)
                ->map(fn (Appointment $a) => AppointmentService::present($a, false, false))->values(),
            'urgencies' => AppointmentService::URGENCY_LABELS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host_id' => ['required', 'integer'],
            'mode' => ['required', 'in:slot,session,first'],
            'starts_at' => ['required_if:mode,slot', 'nullable', 'date_format:Y-m-d H:i'],
            'date' => ['required_if:mode,session', 'nullable', 'date_format:Y-m-d'],
            'urgency' => ['nullable', 'in:normal,important,urgent'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'starts_at.required_if' => 'Choisissez un créneau.',
            'date.required_if' => 'Choisissez une date.',
        ]);
        $host = User::find($data['host_id']);
        abort_unless($host, 404, 'Ce pasteur ne reçoit pas de rendez-vous.');
        $limit = now()->addDays(AppointmentService::HORIZON_DAYS)->endOfDay();
        $when = $data['mode'] === 'slot' ? $data['starts_at'] : ($data['mode'] === 'session' ? $data['date'] : null);
        abort_if($when && now()->parse($when)->gt($limit), 422, 'Cette date est trop éloignée : choisissez dans les prochaines semaines.');

        $appointment = AppointmentService::request($request->user(), $host, $data);

        return response()->json([
            'message' => 'Rendez-vous confirmé : '.$appointment->starts_at->locale('fr')->isoFormat('dddd D MMMM').' à '.AppointmentService::time($appointment->starts_at->format('H:i')).'.',
            'appointment' => AppointmentService::present($appointment, true, false),
        ], 201);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->user_id === $request->user()->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        AppointmentService::cancel($request->user(), $appointment->load('member.profile'), $data['reason'] ?? null);

        return response()->json(['message' => 'Rendez-vous annulé.']);
    }
}
