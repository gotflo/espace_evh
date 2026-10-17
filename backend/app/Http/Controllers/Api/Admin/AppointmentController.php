<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\AppointmentWindow;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rendez-vous avec le pasteur, cote pasteur et protocole : file des demandes par priorite, seances a
 * venir et leurs creneaux, placement, deplacement, annulation, suite donnee ; disponibilites (pasteur).
 * Le sujet (message) du membre n'est lu que par le pasteur concerne : jamais par le protocole.
 */
class AppointmentController extends Controller
{
    private const TIME = ['required', 'date_format:H:i'];

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(AppointmentService::canOrganize($viewer), 403, 'Réservé au pasteur et au protocole.');
        $hosts = User::whereIn('id', AppointmentService::hostIds())->with('profile')->get();
        $host = $this->host($request, $hosts);
        $isHost = $host && $viewer->id === $host->id;

        $sessions = $host ? AppointmentService::sessions($host->id, now()->subDay(), now()->addDays(AppointmentService::HORIZON_DAYS), true) : [];
        // Le message prive du membre : pour le pasteur concerne seulement.
        if (! $isHost) {
            foreach ($sessions as &$s) {
                foreach ($s['slots'] as &$slot) {
                    if (isset($slot['appointment'])) {
                        $slot['appointment']['message'] = null;
                        $slot['appointment']['has_message'] = false;
                    }
                }
            }
            unset($s, $slot);
        }
        $priority = $host ? AppointmentService::byPriority($host->id) : collect();
        $settings = $host ? AppointmentService::settings($host->id) : null;
        $canManage = $host && AppointmentService::canManage($viewer, $host);

        return response()->json([
            'hosts' => $hosts->map(fn (User $h) => AppointmentService::hostPayload($h))->values(),
            'host_id' => $host?->id,
            'is_host' => $isHost,
            'can_manage' => $canManage,
            'sessions' => $sessions,
            // Rendez-vous a venir par ordre de priorite (urgence, puis anciennete).
            'priority' => $priority->values()->map(fn (Appointment $a, int $i) => AppointmentService::present($a, $isHost, true) + ['rank' => $i + 1]),
            'recent' => $host ? Appointment::where('host_id', $host->id)->whereIn('status', ['done', 'no_show', 'cancelled'])
                ->where('updated_at', '>=', now()->subDays(30))->with('member.profile.tribe')->latest('updated_at')->limit(20)->get()
                ->map(fn (Appointment $a) => AppointmentService::present($a, $isHost, true))->values() : [],
            'availability' => $canManage ? [
                'slot_minutes' => $settings->slot_minutes,
                'accepting' => $settings->accepting,
                'weekly' => AppointmentWindow::where('host_id', $host->id)->whereNotNull('weekday')->orderBy('weekday')->orderBy('starts_at')->get()
                    ->map(fn ($w) => ['id' => $w->id, 'weekday' => $w->weekday, 'starts_at' => $w->starts_at, 'ends_at' => $w->ends_at])->values(),
                'extra' => AppointmentWindow::where('host_id', $host->id)->whereNotNull('date')->where('date', '>=', today()->toDateString())->orderBy('date')->get()
                    ->map(fn ($w) => ['id' => $w->id, 'date' => $w->date->toDateString(), 'label' => AppointmentService::dayLabel($w->date),
                        'starts_at' => $w->starts_at, 'ends_at' => $w->ends_at])->values(),
                'blocks' => AppointmentBlock::where('host_id', $host->id)->where('date', '>=', today()->toDateString())->orderBy('date')->get()
                    ->map(fn ($b) => ['id' => $b->id, 'date' => $b->date->toDateString(), 'label' => AppointmentService::dayLabel($b->date),
                        'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'reason' => $b->reason])->values(),
            ] : null,
            'urgencies' => AppointmentService::URGENCY_LABELS,
        ]);
    }

    public function assign(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date_format:Y-m-d H:i']]);
        AppointmentService::assign($request->user(), $appointment->load('member.profile'), Carbon::parse($data['starts_at']));

        return response()->json(['message' => 'Rendez-vous déplacé : le membre est prévenu.']);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        AppointmentService::cancel($request->user(), $appointment->load('member.profile'), $data['reason'] ?? null);

        return response()->json(['message' => 'Rendez-vous révoqué : le membre est prévenu.']);
    }

    public function outcome(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:done,no_show']]);
        $next = AppointmentService::outcome($request->user(), $appointment, $data['status']);
        $name = $next?->member?->profile?->full_name;

        return response()->json([
            'message' => ($data['status'] === 'done' ? 'Terminé.' : 'Absence notée.').($name ? ' '.$name.' est appelé(e) : c\'est son tour.' : ''),
            'called' => $name,
        ]);
    }

    /** « Faire entrer » : la personne recoit « C'est votre tour » (au plus une fois par minute). */
    public function call(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless(AppointmentService::canOrganizeAppointment($request->user(), $appointment), 403, 'Réservé au pasteur concerné et au protocole.');
        $sent = AppointmentService::call($appointment->load('member.profile'), true);

        return response()->json(['message' => $sent ? ($appointment->member?->profile?->full_name ?: 'La personne').' est appelé(e).' : 'Déjà appelé(e) à l\'instant.']);
    }

    public function urgency(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['urgency' => ['required', 'in:normal,important,urgent']]);
        AppointmentService::setUrgency($request->user(), $appointment, $data['urgency']);

        return response()->json(['message' => 'Priorité mise à jour.']);
    }

    // ---------------------------------------------------------------- Disponibilites

    public function saveAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host_id' => ['required', 'integer'],
            'slot_minutes' => ['required', 'integer', 'in:10,15,20,30,45,60'],
            'accepting' => ['required', 'boolean'],
            'weekly' => ['present', 'array', 'max:14'],
            'weekly.*.weekday' => ['required', 'integer', 'between:0,6'],
            'weekly.*.starts_at' => self::TIME,
            'weekly.*.ends_at' => self::TIME,
        ]);
        $host = $this->managed($request, (int) $data['host_id']);
        $weekly = array_map(fn ($w) => ['weekday' => (int) $w['weekday'], 'starts_at' => $w['starts_at'], 'ends_at' => $w['ends_at']], $data['weekly']);
        $requeued = AppointmentService::saveWeekly($request->user(), $host->id, $weekly, (int) $data['slot_minutes'], (bool) $data['accepting']);

        return response()->json(['message' => 'Disponibilités enregistrées.'.($requeued ? " {$requeued} rendez-vous déplacé(s) ou annulé(s) : membres prévenus." : ''), 'requeued' => $requeued]);
    }

    public function addWindow(Request $request): JsonResponse
    {
        $data = $request->validate(['host_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'starts_at' => self::TIME, 'ends_at' => self::TIME]);
        $host = $this->managed($request, (int) $data['host_id']);
        AppointmentService::addWindow($host->id, $data['date'], $data['starts_at'], $data['ends_at']);
        Audit::log('appointment.window_added', null, null, [], ['date' => $data['date'], 'from' => $data['starts_at'], 'to' => $data['ends_at']]);

        return response()->json(['message' => 'Plage ajoutée : les membres peuvent la réserver.'], 201);
    }

    public function removeWindow(Request $request, AppointmentWindow $window): JsonResponse
    {
        $this->managed($request, $window->host_id);
        abort_if($window->date === null, 422, 'Les plages de chaque semaine se modifient dans « Chaque semaine ».');
        $requeued = AppointmentService::removeWindow($request->user(), $window);

        return response()->json(['message' => 'Plage retirée.'.($requeued ? " {$requeued} rendez-vous déplacé(s) ou annulé(s) : membres prévenus." : ''), 'requeued' => $requeued]);
    }

    public function block(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'], 'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        // Fin apres le debut ; « 00:00 » = minuit, fin du dernier creneau d'une plage prolongee jusqu'a minuit.
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && $data['ends_at'] !== '00:00' && $data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'L\'heure de fin doit être après l\'heure de début.']);
        }
        $host = $this->managed($request, (int) $data['host_id']);
        $requeued = AppointmentService::block($request->user(), $host->id, $data['date'], $data['starts_at'] ?? null, $data['ends_at'] ?? null, $data['reason'] ?? null);

        return response()->json(['message' => ($data['starts_at'] ?? null ? 'Créneau bloqué.' : 'Séance bloquée.').($requeued ? " {$requeued} rendez-vous déplacé(s) ou annulé(s) : membres prévenus." : ''), 'requeued' => $requeued], 201);
    }

    public function unblock(Request $request, AppointmentBlock $block): JsonResponse
    {
        $this->managed($request, $block->host_id);
        $block->delete();
        Audit::log('appointment.unblocked', null, null, ['date' => $block->date->toDateString(), 'from' => $block->starts_at, 'to' => $block->ends_at]);

        return response()->json(['message' => 'De nouveau disponible.']);
    }

    // ----------------------------------------------------------------

    /** Pasteur affiche : celui demande, sinon soi-meme (pasteur), sinon le premier. */
    private function host(Request $request, $hosts): ?User
    {
        $wanted = (int) $request->query('host');
        if ($wanted) {
            return $hosts->firstWhere('id', $wanted) ?? abort(404, 'Ce pasteur ne reçoit pas de rendez-vous.');
        }

        return $hosts->firstWhere('id', $request->user()->id) ?? $hosts->first();
    }

    private function managed(Request $request, int $hostId): User
    {
        $host = User::find($hostId);
        abort_unless($host && AppointmentService::canManage($request->user(), $host), 403, 'Seul le pasteur concerné gère ses disponibilités.');

        return $host;
    }
}
