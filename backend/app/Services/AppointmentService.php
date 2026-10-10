<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\AppointmentDigest;
use App\Models\AppointmentSetting;
use App\Models\AppointmentWindow;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rendez-vous avec le pasteur.
 *
 * Le pasteur (permission appointments.host ; le PR par defaut) ouvre des plages : par defaut apres le culte
 * du mercredi (21h50 - 23h20) et du dimanche (12h50 - 14h20), decoupees en creneaux de 15 min (au moins 6 par seance). Il en ajoute
 * a une date, ou bloque une seance ou un creneau.
 *
 * Le membre prend un creneau precis, ou la premiere heure libre d'un jour ou de toutes les seances : le
 * rendez-vous est confirme aussitot (un seul rendez-vous par creneau, garanti par la base). Le pasteur peut
 * revoquer un rendez-vous ou bloquer des plages ; un rendez-vous dont le creneau devient indisponible est
 * deplace automatiquement au prochain creneau libre (annule s'il n'y en a plus), et le membre est prevenu.
 * Le protocole (appointments.organize) voit les rendez-vous par ordre de priorite et organise les rencontres.
 */
class AppointmentService
{
    /** Plages ouvertes par defaut : [jour (0 = dimanche), debut, fin]. */
    public const DEFAULT_WINDOWS = [[3, '21:50', '23:20'], [0, '12:50', '14:20']];

    /** Au moins 6 creneaux par seance : la fin de la derniere plage du jour deborde un peu si besoin. */
    public const MIN_SLOTS = 6;

    public const DEFAULT_SLOT_MINUTES = 15;

    /** Jusqu'a quand on peut prendre rendez-vous (jours). */
    public const HORIZON_DAYS = 42;

    /** Un membre reserve un creneau au plus tard 30 min avant (le protocole, jusqu'au dernier moment). */
    public const MIN_NOTICE_MINUTES = 30;

    /** Liste du jour envoyee au pasteur et au protocole : 3 h avant la seance. */
    public const DIGEST_HOURS = 3;

    public const URGENCY_LABELS = ['normal' => 'Normale', 'important' => 'Importante', 'urgent' => 'Urgente'];

    public const STATUS_LABELS = [
        'pending' => 'En attente', 'confirmed' => 'Confirmé', 'done' => 'Fait', 'no_show' => 'Absent', 'cancelled' => 'Annulé',
    ];

    // ---------------------------------------------------------------- Qui

    /** @return array<int> Pasteurs qui recoivent des rendez-vous : PR et roles avec appointments.host. */
    public static function hostIds(): array
    {
        $superAdmin = Role::where('key', User::SUPER_ADMIN)->value('id');
        $byPermission = DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.key', 'appointments.host')->pluck('role_user.user_id');

        return DB::table('role_user')->where('role_id', $superAdmin)->pluck('user_id')->merge($byPermission)
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public static function isHost(User $user): bool
    {
        return in_array($user->id, self::hostIds(), true);
    }

    /** Protocole, et les pasteurs pour leurs propres rendez-vous. */
    public static function canOrganize(User $user): bool
    {
        return $user->hasPermission('appointments.organize') || self::isHost($user);
    }

    /** Disponibilites d'un pasteur : lui-meme, ou le Pasteur Resident. */
    public static function canManage(User $viewer, User $host): bool
    {
        return self::isHost($host) && ($viewer->id === $host->id || $viewer->isSuperAdmin());
    }

    /** @return array<int> Protocole (role avec appointments.organize), prevenu des demandes. */
    public static function organizerIds(): array
    {
        return DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.key', 'appointments.organize')->pluck('role_user.user_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Pasteur et protocole : ceux qui sont prevenus d'une demande. */
    private static function staffFor(int $hostId): array
    {
        return array_values(array_unique(array_merge([$hostId], self::organizerIds())));
    }

    // ---------------------------------------------------------------- Reglages et plages

    /** Reglages du pasteur ; a la premiere utilisation, les plages par defaut sont ouvertes. */
    public static function settings(int $hostId): AppointmentSetting
    {
        $existing = AppointmentSetting::where('user_id', $hostId)->first();
        if ($existing) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($hostId) {
                $settings = AppointmentSetting::create(['user_id' => $hostId, 'slot_minutes' => self::DEFAULT_SLOT_MINUTES, 'accepting' => true]);
                foreach (self::DEFAULT_WINDOWS as [$day, $start, $end]) {
                    AppointmentWindow::create(['host_id' => $hostId, 'weekday' => $day, 'starts_at' => $start, 'ends_at' => $end]);
                }

                return $settings;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux premieres ouvertures simultanees : la premiere a cree les reglages.
            return AppointmentSetting::where('user_id', $hostId)->firstOrFail();
        }
    }

    /**
     * Seances du pasteur entre deux dates, avec leurs creneaux.
     *
     * @return list<array<string, mixed>>
     */
    public static function sessions(int $hostId, Carbon $from, Carbon $to, bool $forStaff, ?Carbon $now = null): array
    {
        $now ??= now();
        $settings = self::settings($hostId);
        $slot = max(5, $settings->slot_minutes);
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $windows = AppointmentWindow::where('host_id', $hostId)
            ->where(fn ($q) => $q->whereNotNull('weekday')->orWhereBetween('date', [$from->toDateString(), $to->toDateString()]))->get();
        $blocks = AppointmentBlock::where('host_id', $hostId)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy(fn ($b) => $b->date->toDateString());
        // Recus ou absents : le personnel les voit encore sur leur creneau.
        $finished = $forStaff ? Appointment::where('host_id', $hostId)->whereIn('status', ['done', 'no_show'])
            ->whereBetween('starts_at', [$from, $to->copy()->endOfDay()])->with('member.profile.tribe')->get()
            ->groupBy(fn ($a) => $a->starts_at->toDateString()) : collect();
        $booked = Appointment::where('host_id', $hostId)->where('status', 'confirmed')
            ->whereBetween('starts_at', [$from, $to->copy()->endOfDay()])->with('member.profile.tribe')->orderBy('starts_at')->get()
            ->groupBy(fn ($a) => $a->starts_at->toDateString());
        $queued = Appointment::where('host_id', $hostId)->where('status', 'pending')->whereNotNull('preferred_date')
            ->selectRaw('preferred_date, count(*) as c')->groupBy('preferred_date')->pluck('c', 'preferred_date')
            ->mapWithKeys(fn ($c, $d) => [substr((string) $d, 0, 10) => (int) $c]);

        $out = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $dayWindows = $windows->filter(fn ($w) => ($w->weekday !== null && $w->weekday === $day->dayOfWeek)
                || ($w->date && $w->date->toDateString() === $date))->sortBy('starts_at')->values();
            $dayBooked = $booked->get($date, collect());
            if ($dayWindows->isEmpty() && $dayBooked->isEmpty()) {
                continue;
            }
            $dayBlocks = $blocks->get($date, collect());
            $fullBlock = $dayBlocks->first(fn ($b) => $b->starts_at === null);

            $spans = self::spans($date, $dayWindows, $slot);
            $slots = [];
            foreach ($spans as [$t, $end]) {
                for ($t = $t->copy(); $t->copy()->addMinutes($slot)->lte($end); $t->addMinutes($slot)) {
                    $key = $t->format('H:i');
                    if (isset($slots[$key])) {
                        continue;
                    }
                    $slots[$key] = self::slotState($t->copy(), $t->copy()->addMinutes($slot), $fullBlock, $dayBlocks, $dayBooked, $forStaff, $now, $finished->get($date, collect()));
                }
            }
            // Rendez-vous confirmes hors des creneaux actuels (plage ou duree modifiee) : visibles du personnel.
            foreach ($forStaff ? $dayBooked : [] as $a) {
                $key = $a->starts_at->format('H:i');
                if (! isset($slots[$key]) && ! collect($slots)->contains(fn ($s) => ($s['appointment_id'] ?? null) === $a->id)) {
                    $slots[$key] = self::slotState($a->starts_at->copy(), $a->ends_at->copy(), null, collect(), collect([$a]), $forStaff, $now) + ['outside' => true];
                }
            }
            ksort($slots);
            $slots = array_values($slots);
            if ($slots === []) {
                continue;
            }
            $first = $spans ? $spans[0][0]->format('H:i') : $dayBooked->first()->starts_at->format('H:i');
            $last = $spans ? collect($spans)->max(fn ($p) => $p[1])->format('H:i') : $dayBooked->last()->ends_at->format('H:i');
            $lastSlotEnd = Carbon::parse($date.' '.$last);
            if (! $forStaff && $lastSlotEnd->lte($now)) {
                continue;
            }

            $out[] = [
                'date' => $date,
                'label' => self::dayLabel($day),
                'range' => self::time($first).' – '.self::time($last),
                'starts_at' => Carbon::parse($date.' '.$first)->toIso8601String(),
                'blocked' => (bool) $fullBlock,
                'block_id' => $fullBlock?->id,
                'block_reason' => $fullBlock?->reason,
                'slots' => $slots,
                'free' => count(array_filter($slots, fn ($s) => $s['state'] === 'free')),
                'booked' => count(array_filter($slots, fn ($s) => $s['state'] === 'booked')),
                'queued' => $queued[$date] ?? 0,
                'over' => $lastSlotEnd->lte($now),
            ];
        }

        return $out;
    }

    /** Etat d'un creneau : free | booked (personnel) / taken (membre) | blocked | past. */
    private static function slotState(Carbon $start, Carbon $end, ?AppointmentBlock $fullBlock, Collection $blocks, Collection $booked, bool $forStaff, Carbon $now, ?Collection $finished = null): array
    {
        $out = ['start' => $start->format('H:i'), 'end' => $end->format('H:i'), 'at' => $start->toIso8601String()];
        $appointment = $booked->first(fn ($a) => $a->starts_at->lt($end) && $a->ends_at->gt($start));
        $block = $fullBlock ?? $blocks->first(fn ($b) => $b->starts_at !== null
            && Carbon::parse($start->toDateString().' '.$b->starts_at)->lt($end) && Carbon::parse($start->toDateString().' '.$b->ends_at)->gt($start));

        if ($appointment) {
            if (! $forStaff) {
                return $out + ['state' => 'taken'];
            }

            // Message prive compris : le controleur le retire pour qui n'est pas le pasteur concerne.
            return $out + ['state' => 'booked', 'appointment_id' => $appointment->id, 'appointment' => self::present($appointment, true, true)];
        }
        // Deja recu (ou absent) sur ce creneau : visible du personnel.
        $seen = $finished?->first(fn ($a) => $a->starts_at->equalTo($start));
        // (creneau a venir libere par une personne recue en avance : de nouveau libre pour tous)
        if ($forStaff && $seen && $start->lte($now)) {
            return $out + ['state' => 'done', 'appointment_id' => $seen->id, 'appointment' => self::present($seen, true, true)];
        }
        if ($block) {
            return $out + ['state' => $forStaff ? 'blocked' : 'taken', 'block_id' => $forStaff && $block->starts_at !== null ? $block->id : null];
        }
        $notice = $forStaff ? 0 : self::MIN_NOTICE_MINUTES;
        if ($start->lt($now->copy()->addMinutes($notice))) {
            return $out + ['state' => 'past'];
        }

        return $out + ['state' => 'free'];
    }

    /** Le creneau qui commence a $start est-il libre (en ignorant eventuellement un rendez-vous) ? */
    public static function isFree(int $hostId, Carbon $start, bool $forStaff, ?int $ignoreAppointmentId = null): ?Carbon
    {
        $sessions = self::sessions($hostId, $start->copy(), $start->copy(), true);
        foreach ($sessions as $s) {
            foreach ($s['slots'] as $slot) {
                if ($slot['start'] !== $start->format('H:i') || ($slot['outside'] ?? false) || $s['blocked']) {
                    continue;
                }
                $ok = $slot['state'] === 'free' || ($slot['state'] === 'booked' && $ignoreAppointmentId && ($slot['appointment_id'] ?? null) === $ignoreAppointmentId);
                if (! $ok) {
                    return null;
                }
                if (! $forStaff && $start->lt(now()->addMinutes(self::MIN_NOTICE_MINUTES))) {
                    return null;
                }

                return Carbon::parse($s['date'].' '.$slot['end']);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Demandes (membre)

    /**
     * Debuts des creneaux libres, dans l'ordre : un jour precis ($date) ou toutes les seances a venir.
     *
     * @return list<Carbon>
     */
    public static function freeStarts(int $hostId, ?string $date, bool $forStaff, ?Carbon $after = null): array
    {
        $after ??= now();
        $from = $date ? Carbon::parse($date) : $after->copy();
        $to = $date ? Carbon::parse($date) : $after->copy()->addDays(self::HORIZON_DAYS);
        $out = [];
        foreach (self::sessions($hostId, $from, $to, $forStaff) as $s) {
            if ($s['blocked']) {
                continue;
            }
            foreach ($s['slots'] as $slot) {
                $start = Carbon::parse($s['date'].' '.$slot['start']);
                if ($slot['state'] === 'free' && ! ($slot['outside'] ?? false) && $start->gt($after)) {
                    $out[] = $start;
                }
            }
        }

        return $out;
    }

    /**
     * Prise de rendez-vous : toujours confirmee aussitot. Un creneau precis, ou la premiere heure libre
     * d'un jour (« session ») ou de toutes les seances (« first »).
     *
     * @param  array{mode: string, starts_at?: string|null, date?: string|null, urgency?: string|null, message?: string|null}  $data
     */
    public static function request(User $member, User $host, array $data): Appointment
    {
        abort_unless(self::isHost($host), 404, 'Ce pasteur ne reçoit pas de rendez-vous.');
        abort_if($member->id === $host->id, 422, 'Vous ne pouvez pas prendre rendez-vous avec vous-même.');
        abort_unless(self::settings($host->id)->accepting, 409, 'Le pasteur ne prend pas de rendez-vous pour le moment.');
        abort_if(Appointment::where('user_id', $member->id)->where('host_id', $host->id)->whereIn('status', Appointment::ACTIVE)->exists(), 409,
            'Vous avez déjà un rendez-vous avec le pasteur. Annulez-le pour en prendre un autre.');

        $notice = now()->addMinutes(self::MIN_NOTICE_MINUTES)->subSecond();
        $candidates = match ($data['mode']) {
            'slot' => [Carbon::parse($data['starts_at'])],
            'session' => self::freeStarts($host->id, Carbon::parse($data['date'])->toDateString(), false, $notice),
            default => self::freeStarts($host->id, null, false, $notice),
        };
        abort_if($candidates === [], 409, match ($data['mode']) {
            'session' => 'Plus aucune place ce jour-là. Choisissez une autre date.',
            default => 'Aucun créneau libre pour le moment. Revenez bientôt.',
        });

        $base = [
            'host_id' => $host->id, 'user_id' => $member->id, 'status' => 'confirmed', 'preference' => $data['mode'],
            'urgency' => $data['urgency'] ?? 'normal',
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
        ];
        // Le premier creneau encore libre au moment d'ecrire (un autre membre a pu le prendre entre-temps).
        $appointment = null;
        foreach (array_slice($candidates, 0, 6) as $start) {
            $end = self::isFree($host->id, $start, false);
            if (! $end) {
                continue;
            }
            $appointment = self::tryLock(fn () => Appointment::create($base + [
                'preferred_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $end,
                'slot_key' => self::slotKey($host->id, $start), 'confirmed_at' => now(),
            ]));
            if ($appointment) {
                break;
            }
        }
        abort_unless($appointment, 409, $data['mode'] === 'slot'
            ? 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre. Choisissez-en un autre.'
            : 'Les créneaux viennent d\'être pris. Choisissez une autre date.');

        $appointment->load('member.profile');
        $name = $member->profile?->full_name ?: $member->phone;
        Notifier::send(self::staffFor($host->id), 'appointment', 'Nouveau rendez-vous : '.$name,
            ucfirst(self::whenLabel($appointment->starts_at)).($appointment->urgency !== 'normal' ? ' · '.self::URGENCY_LABELS[$appointment->urgency] : ''),
            '/admin/rendez-vous', ['appointment_id' => $appointment->id], $appointment->urgency === 'urgent' ? 'high' : 'normal');
        Audit::log('appointment.requested', $appointment, $member->id, [], [
            'when' => self::whenLabel($appointment->starts_at), 'urgency' => $appointment->urgency,
        ]);
        // Toutes les plages du jour prises : le courriel du pasteur part sans attendre.
        self::mailDigestFor($host->id, $appointment->starts_at->toDateString());

        return $appointment;
    }

    /**
     * Annulation par le membre, ou revocation par le pasteur / le protocole (motif facultatif, communique
     * au membre, qui peut reprendre un autre rendez-vous).
     */
    public static function cancel(User $by, Appointment $appointment, ?string $reason): void
    {
        $isMember = $by->id === $appointment->user_id;
        abort_unless($isMember || self::canOrganize($by), 403, 'Vous ne pouvez pas annuler ce rendez-vous.');
        abort_unless($appointment->isActive(), 409, 'Ce rendez-vous n\'est plus actif.');
        abort_if($appointment->starts_at && $appointment->starts_at->lte(now()) && $isMember, 409, 'Ce rendez-vous a déjà commencé.');

        $reason = filled($reason) ? mb_substr(trim($reason), 0, 500) : null;
        $appointment->forceFill(['status' => 'cancelled', 'slot_key' => null, 'cancelled_by' => $by->id, 'cancel_reason' => $reason])->save();
        Audit::log($isMember ? 'appointment.cancelled' : 'appointment.revoked', $appointment, $appointment->user_id, [], ['reason' => $reason]);

        $when = $appointment->starts_at ? ucfirst(self::whenLabel($appointment->starts_at)) : 'Votre demande';
        if ($isMember) {
            $name = $appointment->member?->profile?->full_name ?: 'Un membre';
            Notifier::send(self::staffFor($appointment->host_id), 'appointment', 'Rendez-vous annulé : '.$name,
                $when.' · le créneau est de nouveau libre.', '/admin/rendez-vous', ['appointment_id' => $appointment->id]);
        } else {
            Notifier::send([$appointment->user_id], 'appointment', 'Votre rendez-vous avec le pasteur est annulé',
                $when.' · '.($reason ?: 'Le pasteur n\'est pas disponible à ce moment.').' Vous pouvez reprendre rendez-vous sur un autre créneau.',
                '/rendez-vous', ['appointment_id' => $appointment->id], 'high');
        }
    }

    // ---------------------------------------------------------------- Organisation (protocole, pasteur)

    /** Deplace un rendez-vous sur un creneau libre (le membre est prevenu). */
    public static function assign(User $by, Appointment $appointment, Carbon $start, bool $notify = true): void
    {
        abort_unless(self::canOrganize($by), 403, 'Réservé au pasteur et au protocole.');
        abort_unless($appointment->isActive(), 409, 'Ce rendez-vous n\'est plus actif.');
        abort_if($start->lt(now()->subMinutes(5)), 422, 'Ce créneau est déjà passé.');
        $end = self::isFree($appointment->host_id, $start, true, $appointment->id);
        abort_unless($end, 409, 'Ce créneau n\'est plus libre. Choisissez-en un autre.');

        $before = $appointment->starts_at;
        $done = self::tryLock(function () use ($appointment, $start, $end, $by) {
            $appointment->forceFill(['slot_key' => null])->save();
            $appointment->forceFill([
                'status' => 'confirmed', 'starts_at' => $start, 'ends_at' => $end, 'preferred_date' => $start->toDateString(),
                'slot_key' => self::slotKey($appointment->host_id, $start), 'handled_by' => $by->id, 'reminded_at' => null,
            ])->save();

            return $appointment;
        });
        abort_unless($done, 409, 'Ce créneau vient d\'être réservé. Choisissez-en un autre.');
        Audit::log('appointment.moved', $appointment, $appointment->user_id, $before ? ['when' => self::whenLabel($before)] : [], ['when' => self::whenLabel($start)]);
        self::mailDigestFor($appointment->host_id, $start->toDateString());

        if ($notify && (! $before || ! $before->equalTo($start))) {
            Notifier::send([$appointment->user_id], 'appointment', 'Rendez-vous avec le pasteur déplacé',
                ucfirst(self::whenLabel($start)).'.', '/rendez-vous', ['appointment_id' => $appointment->id], 'high');
        }
    }

    /**
     * Fin d'un rendez-vous (« Termine ») ou absence, des le jour meme : une personne qui sort plus tot libere
     * la place. Les creneaux se suivent sans pause : la personne suivante du jour est appelee aussitot.
     * Renvoie la personne appelee.
     */
    public static function outcome(User $by, Appointment $appointment, string $status): ?Appointment
    {
        abort_unless(self::canOrganize($by), 403, 'Réservé au pasteur et au protocole.');
        abort_unless(in_array($appointment->status, ['confirmed', 'done', 'no_show'], true) && $appointment->starts_at, 409, 'Ce rendez-vous n\'a pas eu lieu.');
        abort_if($appointment->starts_at->gt(now()) && ! $appointment->starts_at->isSameDay(now()), 409, 'Ce rendez-vous n\'est pas aujourd\'hui.');
        $wasWaiting = $appointment->status === 'confirmed';
        $appointment->forceFill(['status' => $status, 'slot_key' => null, 'handled_by' => $by->id])->save();
        Audit::log('appointment.'.$status, $appointment, $appointment->user_id);

        return $wasWaiting ? self::callNext($appointment) : null;
    }

    /**
     * Personne suivante : la premiere encore attendue aujourd'hui (meme si l'ordre a ete bouscule), si son
     * rendez-vous est dans l'heure (pas d'appel a midi pour un rendez-vous du soir). Appelee aussitot.
     */
    public static function callNext(Appointment $after): ?Appointment
    {
        $next = Appointment::where('host_id', $after->host_id)->where('status', 'confirmed')->where('id', '!=', $after->id)
            // Une personne oubliee depuis plus de 30 min (deja partie, non notee) n'est pas rappelee.
            ->whereBetween('starts_at', [now()->subMinutes(30)->max(now()->startOfDay()), now()->addHour()->min(now()->endOfDay())])
            ->with('member.profile')->orderBy('starts_at')->first();
        if ($next) {
            self::call($next, false);
        }

        return $next;
    }

    /**
     * « C'est votre tour » : notification prioritaire. Appel automatique une seule fois ; un rappel manuel
     * (bouton « Faire entrer ») au plus une fois par minute.
     */
    public static function call(Appointment $appointment, bool $manual): bool
    {
        abort_unless($appointment->status === 'confirmed' && $appointment->starts_at?->isSameDay(now()), 409, 'Ce rendez-vous n\'est pas aujourd\'hui.');
        $key = $manual ? 'appointments:call:'.$appointment->id.':'.now()->format('H:i') : 'appointments:call:'.$appointment->id;
        if (! Notifier::once($key)) {
            return false;
        }
        Notifier::send([$appointment->user_id], 'appointment', 'C\'est votre tour',
            'Le pasteur vous reçoit maintenant. Présentez-vous au protocole.', '/rendez-vous', ['appointment_id' => $appointment->id], 'high');

        return true;
    }

    public static function setUrgency(User $by, Appointment $appointment, string $urgency): void
    {
        abort_unless(self::canOrganize($by), 403, 'Réservé au pasteur et au protocole.');
        abort_unless($appointment->isActive(), 409, 'Ce rendez-vous n\'est plus actif.');
        $appointment->forceFill(['urgency' => $urgency])->save();
    }

    /** Rendez-vous a venir par ordre de priorite : urgence, puis anciennete de la prise de rendez-vous. */
    public static function byPriority(int $hostId): Collection
    {
        return Appointment::where('host_id', $hostId)->where('status', 'confirmed')->where('ends_at', '>', now())
            ->with('member.profile.tribe')->get()
            ->sortBy([
                fn ($a, $b) => Appointment::URGENCY_WEIGHT[$b->urgency] <=> Appointment::URGENCY_WEIGHT[$a->urgency],
                fn ($a, $b) => $a->created_at <=> $b->created_at,
            ])->values();
    }

    // ---------------------------------------------------------------- Disponibilites (pasteur)

    /** @param list<array{weekday: int, starts_at: string, ends_at: string}> $weekly */
    public static function saveWeekly(User $by, int $hostId, array $weekly, int $slotMinutes, bool $accepting): int
    {
        self::assertNoOverlap($weekly);
        DB::transaction(function () use ($hostId, $weekly, $slotMinutes, $accepting) {
            self::settings($hostId)->update(['slot_minutes' => $slotMinutes, 'accepting' => $accepting]);
            AppointmentWindow::where('host_id', $hostId)->whereNotNull('weekday')->delete();
            foreach ($weekly as $w) {
                AppointmentWindow::create(['host_id' => $hostId, 'weekday' => $w['weekday'], 'starts_at' => $w['starts_at'], 'ends_at' => $w['ends_at']]);
            }
        });
        Audit::log('appointment.availability', null, null, [], ['weekly' => $weekly, 'slot_minutes' => $slotMinutes, 'accepting' => $accepting]);

        return self::relocateOrphans($by, $hostId);
    }

    public static function addWindow(int $hostId, string $date, string $start, string $end): AppointmentWindow
    {
        self::settings($hostId); // plages par defaut creees avant toute comparaison
        $day = Carbon::parse($date);
        abort_if($day->lt(today()), 422, 'Choisissez une date à venir.');
        $existing = AppointmentWindow::where('host_id', $hostId)
            ->where(fn ($q) => $q->where('weekday', $day->dayOfWeek)->orWhere('date', $day->toDateString()))->get()
            ->map(fn ($w) => ['weekday' => 0, 'starts_at' => $w->starts_at, 'ends_at' => $w->ends_at])->all();
        self::assertNoOverlap(array_merge($existing, [['weekday' => 0, 'starts_at' => $start, 'ends_at' => $end]]),
            'Cette plage chevauche une plage déjà ouverte ce jour-là.');

        return AppointmentWindow::create(['host_id' => $hostId, 'date' => $day->toDateString(), 'starts_at' => $start, 'ends_at' => $end]);
    }

    public static function removeWindow(User $by, AppointmentWindow $window): int
    {
        $window->delete();

        return self::relocateOrphans($by, $window->host_id);
    }

    public static function block(User $by, int $hostId, string $date, ?string $start, ?string $end, ?string $reason): int
    {
        self::settings($hostId);
        abort_if(Carbon::parse($date)->lt(today()), 422, 'Choisissez une date à venir.');
        AppointmentBlock::create([
            'host_id' => $hostId, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end,
            'reason' => $reason ? mb_substr(trim($reason), 0, 255) : null, 'created_by' => $by->id,
        ]);
        Audit::log('appointment.blocked', null, null, [], ['date' => $date, 'from' => $start, 'to' => $end, 'reason' => $reason]);

        return self::relocateOrphans($by, $hostId);
    }

    /**
     * Rendez-vous a venir dont le creneau n'existe plus (bloque, plage retiree) : deplaces automatiquement
     * sur le prochain creneau libre (les plus prioritaires d'abord) ; sans creneau libre, annules. Le membre
     * est prevenu dans les deux cas. Renvoie le nombre de rendez-vous concernes.
     */
    public static function relocateOrphans(User $by, int $hostId): int
    {
        $orphans = self::byPriority($hostId)->filter(fn (Appointment $a) => $a->starts_at->gt(now()) && ! self::slotStillOffered($hostId, $a))->values();
        $moved = 0;
        $cancelled = 0;
        foreach ($orphans as $a) {
            $before = $a->starts_at->copy();
            $placed = false;
            foreach (array_slice(self::freeStarts($hostId, null, true, max(now(), $before)), 0, 6) as $start) {
                try {
                    self::assign($by, $a, $start, false);
                    $placed = true;
                    break;
                } catch (HttpException) {
                    // pris entre-temps : creneau suivant
                }
            }
            if ($placed) {
                $moved++;
                Notifier::send([$a->user_id], 'appointment', 'Rendez-vous avec le pasteur déplacé',
                    'Le pasteur n\'est plus disponible '.self::whenLabel($before).'. Nouveau rendez-vous : '.self::whenLabel($a->starts_at)
                    .'. Un empêchement ? Annulez dans l\'application.', '/rendez-vous', ['appointment_id' => $a->id], 'high');

                continue;
            }
            $cancelled++;
            $a->forceFill(['status' => 'cancelled', 'slot_key' => null, 'cancelled_by' => $by->id,
                'cancel_reason' => 'Le pasteur n\'est plus disponible et aucun autre créneau n\'est libre.'])->save();
            Audit::log('appointment.revoked', $a, $a->user_id, ['when' => self::whenLabel($before)], ['reason' => 'aucun créneau libre']);
            Notifier::send([$a->user_id], 'appointment', 'Votre rendez-vous avec le pasteur est annulé',
                ucfirst(self::whenLabel($before)).' · le pasteur n\'est plus disponible et aucun autre créneau n\'est libre pour le moment. Reprenez rendez-vous dès que de nouvelles plages s\'ouvrent.',
                '/rendez-vous', ['appointment_id' => $a->id], 'high');
        }
        if ($moved + $cancelled > 0) {
            Notifier::send(self::organizerIds(), 'appointment',
                ($moved + $cancelled > 1 ? ($moved + $cancelled).' rendez-vous modifiés' : 'Un rendez-vous modifié').' par le pasteur',
                trim(($moved ? "{$moved} déplacé(s) au prochain créneau libre. " : '').($cancelled ? "{$cancelled} annulé(s), faute de place." : '')),
                '/admin/rendez-vous');
        }

        return $moved + $cancelled;
    }

    private static function slotStillOffered(int $hostId, Appointment $a): bool
    {
        $date = $a->starts_at->toDateString();
        $windows = AppointmentWindow::where('host_id', $hostId)
            ->where(fn ($q) => $q->where('weekday', $a->starts_at->dayOfWeek)->orWhere('date', $date))->get();
        $inWindow = collect(self::spans($date, $windows, max(5, self::settings($hostId)->slot_minutes)))
            ->contains(fn ($p) => $p[0]->lte($a->starts_at) && $p[1]->gte($a->ends_at));
        if (! $inWindow) {
            return false;
        }

        return ! AppointmentBlock::where('host_id', $hostId)->where('date', $date)->get()->contains(fn ($b) => $b->starts_at === null
            || (Carbon::parse("{$date} {$b->starts_at}")->lt($a->ends_at) && Carbon::parse("{$date} {$b->ends_at}")->gt($a->starts_at)));
    }

    /**
     * Plages d'un jour [debut, fin], dans l'ordre. Moins de 6 creneaux en tout : la plage qui finit le plus
     * tard est prolongee d'autant de creneaux (sans depasser minuit).
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private static function spans(string $date, Collection $windows, int $slot): array
    {
        $spans = $windows->sortBy('starts_at')
            ->map(fn ($w) => [Carbon::parse("{$date} {$w->starts_at}"), Carbon::parse("{$date} {$w->ends_at}")])->values()->all();
        if ($spans === []) {
            return [];
        }
        $count = array_sum(array_map(fn ($p) => intdiv((int) $p[0]->diffInMinutes($p[1]), $slot), $spans));
        if ($count < self::MIN_SLOTS) {
            $i = collect($spans)->keys()->sortBy(fn ($k) => $spans[$k][1]->timestamp)->last();
            [$start, $end] = $spans[$i];
            $fit = intdiv((int) $start->diffInMinutes($end), $slot);
            $spans[$i][1] = $start->copy()->addMinutes(($fit + self::MIN_SLOTS - $count) * $slot)
                ->min(Carbon::parse($date)->addDay()->startOfDay())->max($end);
        }

        return $spans;
    }

    /** @param list<array{weekday: int, starts_at: string, ends_at: string}> $windows */
    private static function assertNoOverlap(array $windows, string $message = 'Deux plages se chevauchent le même jour.'): void
    {
        foreach ($windows as $i => $a) {
            abort_if($a['starts_at'] >= $a['ends_at'], 422, 'L\'heure de fin doit être après l\'heure de début.');
            foreach ($windows as $j => $b) {
                if ($i < $j && $a['weekday'] === $b['weekday'] && $a['starts_at'] < $b['ends_at'] && $b['starts_at'] < $a['ends_at']) {
                    abort(422, $message);
                }
            }
        }
    }

    // ---------------------------------------------------------------- Automatismes (app:tick)

    /** Liste du jour (pasteur, protocole), rappel au membre, cloture des rendez-vous passes. */
    public static function tick(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        // Rendez-vous passes depuis plus de 6 h sans suite : consideres comme faits.
        Appointment::where('status', 'confirmed')->where('ends_at', '<', $now->copy()->subHours(6))
            ->update(['status' => 'done', 'slot_key' => null, 'updated_at' => $now]);

        foreach (self::hostIds() as $hostId) {
            $today = collect(self::sessions($hostId, $now->copy(), $now->copy(), true, $now))->first();
            if (! $today || $today['blocked'] || $today['over']) {
                continue;
            }
            $start = Carbon::parse($today['starts_at']);
            // Liste du jour, 3 h avant la seance, pour le pasteur et le protocole (une fois).
            if ($now->gte($start->copy()->subHours(self::DIGEST_HOURS)) && Notifier::once("appointments:digest:{$hostId}:{$today['date']}")) {
                $list = Appointment::where('host_id', $hostId)->where('status', 'confirmed')
                    ->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->with('member.profile')->orderBy('starts_at')->get();
                if ($list->isNotEmpty()) {
                    $sent += Notifier::send(self::staffFor($hostId), 'appointment',
                        ($list->count() > 1 ? $list->count().' rendez-vous' : '1 rendez-vous').' avec le pasteur aujourd\'hui',
                        $list->map(fn ($a) => self::time($a->starts_at->format('H:i')).' '.($a->member?->profile?->full_name ?: 'Membre'))->take(6)->implode(' · '),
                        '/admin/rendez-vous');
                }
            }
        }

        // Rappel le jour meme au membre (des 8 h, avant l'heure du rendez-vous).
        if ($now->hour >= 8) {
            // Pris il y a moins de 2 h : la personne vient de le voir, pas de rappel.
            $due = Appointment::where('status', 'confirmed')->whereNull('reminded_at')->where('confirmed_at', '<', $now->copy()->subHours(2))
                ->whereBetween('starts_at', [$now, $now->copy()->endOfDay()])->get();
            foreach ($due as $a) {
                $a->forceFill(['reminded_at' => $now])->save();
                $sent += Notifier::send([$a->user_id], 'appointment', 'Rendez-vous avec le pasteur aujourd\'hui à '.self::time($a->starts_at->format('H:i')),
                    'Présentez-vous au protocole après le culte. Un empêchement ? Annulez dans l\'application pour libérer la place.',
                    '/rendez-vous', ['appointment_id' => $a->id]);
            }
        }

        return $sent;
    }

    // ---------------------------------------------------------------- Courriel du jour au pasteur

    /** Courriel envoye 15 min avant le premier rendez-vous si toutes les plages ne sont pas prises. */
    public const DIGEST_MAIL_MINUTES = 15;

    /** Passage chaque minute : jours a venir qui ont des rendez-vous. */
    public static function mailDigests(?Carbon $now = null): int
    {
        $now ??= now();
        $queued = 0;
        foreach (self::hostIds() as $hostId) {
            $dates = Appointment::where('host_id', $hostId)->where('status', 'confirmed')
                ->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->addDays(self::HORIZON_DAYS)])
                ->pluck('starts_at')->map(fn ($d) => $d->toDateString())->unique();
            foreach ($dates as $date) {
                $queued += self::mailDigestFor($hostId, $date, $now);
            }
        }

        return $queued;
    }

    /**
     * Un seul courriel par jour au pasteur : la liste des personnes attendues, des que toutes les plages
     * du jour sont prises, sinon 15 min avant le premier rendez-vous. Si la liste a change apres un envoi
     * anticipe (annulation, nouvelle reservation), une seule version corrigee part a ce meme moment.
     */
    public static function mailDigestFor(int $hostId, string $date, ?Carbon $now = null): int
    {
        $now ??= now();
        $session = collect(self::sessions($hostId, Carbon::parse($date), Carbon::parse($date), true, $now))->first();
        if (! $session || $session['blocked'] || $session['over']) {
            return 0;
        }
        $list = Appointment::where('host_id', $hostId)->where('status', 'confirmed')
            ->whereBetween('starts_at', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()])
            ->with('member.profile.tribe')->orderBy('starts_at')->get();
        if ($list->isEmpty()) {
            return 0;
        }
        $slots = collect($session['slots'])->reject(fn ($s) => $s['state'] === 'blocked');
        $full = $slots->isNotEmpty() && $slots->every(fn ($s) => $s['state'] === 'booked' || $s['state'] === 'done');
        $deadline = $now->gte(Carbon::parse($session['starts_at'])->subMinutes(self::DIGEST_MAIL_MINUTES));
        $signature = sha1($list->map(fn ($a) => $a->id.'@'.$a->starts_at->format('H:i').':'.$a->urgency)->implode('|'));

        $digest = AppointmentDigest::firstOrCreate(['host_id' => $hostId, 'date' => $date]);
        if (! $digest->sent_at) {
            if (! $full && ! $deadline) {
                return 0;
            }
            // Reserve l'envoi (un seul courriel meme si deux taches tournent en meme temps).
            $claimed = AppointmentDigest::where('id', $digest->id)->whereNull('sent_at')
                ->update(['sent_at' => $now, 'signature' => $signature, 'reason' => $full ? 'full' : 'deadline', 'updated_at' => $now]);

            return $claimed === 1 ? self::queueDigest($hostId, $date, $session, $list, $full ? 'full' : 'deadline', false) : 0;
        }
        if ($deadline && ! $digest->updated_sent_at && $digest->signature !== $signature) {
            $claimed = AppointmentDigest::where('id', $digest->id)->whereNull('updated_sent_at')
                ->update(['updated_sent_at' => $now, 'signature' => $signature, 'updated_at' => $now]);

            return $claimed === 1 ? self::queueDigest($hostId, $date, $session, $list, 'deadline', true) : 0;
        }

        return 0;
    }

    /** @param array<string, mixed> $session */
    private static function queueDigest(int $hostId, string $date, array $session, Collection $list, string $reason, bool $update): int
    {
        $host = User::with('profile')->find($hostId);
        $email = $host ? Messenger::emailOf($host) : null;
        if (! $email) {
            // Sans adresse : on le dit une fois au pasteur (la liste reste dans l'application).
            if (Notifier::once("appointments:no-email:{$hostId}")) {
                Notifier::send([$hostId], 'appointment', 'Ajoutez votre adresse courriel',
                    'Pour recevoir chaque jour la liste de vos rendez-vous par courriel, ajoutez votre adresse dans votre profil.', '/mon-profil');
            }

            return 0;
        }
        $day = Carbon::parse($date);
        $start = Carbon::parse($session['starts_at']);
        $dayTitle = $day->isToday() ? ($start->hour >= 17 ? 'de ce soir' : 'd\'aujourd\'hui') : 'du '.self::dayLabel($day);
        $items = $list->map(fn (Appointment $a) => [
            'time' => self::time($a->starts_at->format('H:i')),
            'name' => $a->member?->profile?->full_name ?: (string) $a->member?->phone,
            'tribe' => $a->member?->profile?->tribe?->name,
            'phone' => $a->member?->phone,
            'urgency' => $a->urgency,
            'urgency_label' => self::URGENCY_LABELS[$a->urgency] ?? $a->urgency,
            'message' => $a->message,
        ])->values()->all();
        $slots = collect($session['slots'])->reject(fn ($s) => $s['state'] === 'blocked');

        $subject = ($update ? 'Liste mise à jour · ' : '').'Vos rendez-vous '.$dayTitle
            .($day->isToday() ? ' · '.self::dayLabel($day) : '').' ('.count($items).')';

        return Messenger::queueEmail($email, $hostId, 'appointments_digest', $subject, 'appointments-digest', [
            'dayLabel' => self::dayLabel($day),
            'dayTitle' => $dayTitle,
            'range' => $session['range'],
            'reason' => $reason,
            'update' => $update,
            'items' => $items,
            'total' => $slots->count(),
            'free' => $slots->where('state', 'free')->count(),
            'minutes' => self::settings($hostId)->slot_minutes,
            'adminUrl' => rtrim((string) config('app.url'), '/').'/admin/rendez-vous',
        ], "appointments-digest:{$hostId}:{$date}:".($update ? 'update' : 'first')) ? 1 : 0;
    }

    // ---------------------------------------------------------------- Presentation

    public static function present(Appointment $a, bool $withMessage, bool $withMember): array
    {
        $profile = $a->member?->profile;

        return [
            'id' => $a->id,
            'host_id' => $a->host_id,
            'status' => $a->status,
            'status_label' => self::STATUS_LABELS[$a->status] ?? $a->status,
            'starts_at' => $a->starts_at?->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(),
            'when' => $a->starts_at ? self::whenLabel($a->starts_at) : null,
            'day' => $a->starts_at ? self::dayLabel($a->starts_at) : null,
            'time' => $a->starts_at ? self::time($a->starts_at->format('H:i')) : null,
            'preference' => $a->preference,
            'preferred_date' => $a->preferred_date?->toDateString(),
            'preferred_label' => $a->status === 'pending'
                ? ($a->preferred_date ? self::dayLabel($a->preferred_date) : 'Dès que possible')
                : null,
            'urgency' => $a->urgency,
            'urgency_label' => self::URGENCY_LABELS[$a->urgency] ?? $a->urgency,
            'message' => $withMessage ? $a->message : null,
            // Le sujet reste entre le membre et le pasteur : le protocole ne sait meme pas s'il y en a un.
            'has_message' => $withMessage && filled($a->message),
            'cancel_reason' => $a->cancel_reason,
            'created_at' => $a->created_at?->toIso8601String(),
            'member' => $withMember ? [
                'user_id' => $a->user_id,
                'name' => $profile?->full_name ?: $a->member?->phone,
                'first_name' => $profile?->first_name,
                'last_name' => $profile?->last_name,
                'phone' => $a->member?->phone,
                'tribe' => $profile?->tribe?->name,
                'photo_url' => $profile?->photo_url,
            ] : null,
        ];
    }

    public static function hostPayload(User $host): array
    {
        $profile = $host->profile;

        return ['id' => $host->id, 'name' => $profile?->full_name ?: 'Pasteur', 'photo_url' => $profile?->photo_url, 'accepting' => self::settings($host->id)->accepting];
    }

    /** « mercredi 14 octobre à 21h50 » */
    public static function whenLabel(Carbon $at): string
    {
        return self::dayLabel($at).' à '.self::time($at->format('H:i'));
    }

    /** « mercredi 14 octobre » */
    public static function dayLabel(Carbon $day): string
    {
        return $day->copy()->locale('fr')->isoFormat('dddd D MMMM');
    }

    /** « 21h50 », « 9h30 » */
    public static function time(string $hhmm): string
    {
        [$h, $m] = explode(':', $hhmm);

        return ((int) $h).'h'.$m;
    }

    private static function slotKey(int $hostId, Carbon $start): string
    {
        return $hostId.'|'.$start->format('Y-m-d H:i');
    }

    /** Ecriture d'un creneau : la base refuse un second rendez-vous sur le meme creneau (null alors). */
    private static function tryLock(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
