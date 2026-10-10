<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentView;
use App\Models\MonthlyResolution;
use App\Models\SpiritualHealthForm;
use App\Models\VertumetreForm;
use App\Services\AppointmentService;
use App\Services\AssignmentProgress;
use App\Services\FissService;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mon mois » sur le tableau de bord : les rendez-vous personnels du mois en un coup d'oeil
 * (FISS, Vertumetre, résolutions, devoir), pour savoir tout de suite ce qui reste a faire.
 */
class MyMonthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = now()->format('Y-m');
        $daysLeft = (int) now()->startOfDay()->diffInDays(now()->endOfMonth()->startOfDay());

        $fiss = SpiritualHealthForm::where('user_id', $user->id)->where('period', $period)->exists();
        $vertumetre = VertumetreForm::where('user_id', $user->id)->where('period', $period)->exists();
        $rm = MonthlyResolution::where('user_id', $user->id)->where('period', $period)->first();
        $previous = MonthlyResolution::where('user_id', $user->id)->where('period', now()->subMonthNoOverflow()->format('Y-m'))->first();
        $latest = Assignment::published()->orderByDesc('published_at')->orderByDesc('id')->first();
        // Fait = resume envoye ET lecture terminee (video ou audio), comme les exercices video.
        $done = $latest && AssignmentProgress::isDone($latest,
            AssignmentView::where('assignment_id', $latest->id)->where('user_id', $user->id)->first(),
            AssignmentResponse::where('assignment_id', $latest->id)->where('user_id', $user->id)->first());

        $steps = [
            'fiss' => ['done' => $fiss],
            'vertumetre' => ['done' => $vertumetre],
            'resolutions' => [
                'done' => $rm !== null,
                'review_needed' => $previous !== null && $previous->outcome === null && $previous->filled() !== [],
            ],
            'assignment' => [
                'done' => $done,
                'available' => $latest !== null,
                'id' => $latest?->id,
                'title' => $latest?->title,
                'media_type' => $latest?->media_type,
            ],
        ];
        // Modules desactives dans les reglages : retires du parcours du mois.
        $modules = ['vertumetre' => 'vertumetre', 'resolutions' => 'resolutions', 'assignment' => 'assignments'];
        foreach ($modules as $step => $module) {
            if (! PlatformSettings::module($module)) {
                unset($steps[$step]);
            }
        }
        $countable = array_filter($steps, fn ($s, $k) => $k !== 'assignment' || $s['available'], ARRAY_FILTER_USE_BOTH);

        // Rendez-vous avec le pasteur a venir (ou demande en attente) : rappele sur le tableau de bord.
        $appointment = ! PlatformSettings::module('appointments') ? null : Appointment::where('user_id', $user->id)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($c) => $c->where('status', 'confirmed')->where('ends_at', '>', now())))
            ->orderByRaw("status = 'confirmed' desc")->orderBy('starts_at')->first();

        return response()->json([
            'appointment' => $appointment ? [
                'status' => $appointment->status,
                'when' => $appointment->starts_at ? AppointmentService::whenLabel($appointment->starts_at) : null,
                'today' => $appointment->starts_at?->isToday() ?? false,
            ] : null,
            'period' => $period,
            'period_label' => FissService::periodLabel($period),
            'days_left' => $daysLeft,
            'steps' => $steps,
            'done' => count(array_filter($countable, fn ($s) => $s['done'])),
            'total' => count($countable),
        ]);
    }
}
