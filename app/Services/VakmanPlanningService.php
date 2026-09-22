<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\VoucherPriceKind;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkUnit;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Support\Format;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VakmanPlanningService
{
    public function __construct(
        private VoucherPriceResolver $prices,
        private WorkerAvailabilityService $availability,
        private TimeEntryService $hours,
    ) {}

    /**
     * @return array{
     *     view: string,
     *     weekStart: Carbon,
     *     monthStart: Carbon,
     *     periodLabel: string,
     *     prevUrl: string,
     *     nextUrl: string,
     *     todayUrl: string,
     *     hasJobs: bool,
     *     days: Collection<int, array<string, mixed>>
     * }
     */
    public function agenda(User $user, string $view, ?string $week, ?string $month): array
    {
        $user->loadMissing(['worker.crewPeople', 'worker.availabilities', 'crewMember']);
        $view = $view === 'month' ? 'month' : 'week';
        $weekStart = $this->weekStart($week);
        $monthStart = $this->monthStart($month, $week);

        if ($view === 'month') {
            $from = $monthStart->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $to = $monthStart->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->startOfDay();
            $periodLabel = $monthStart->translatedFormat('F Y');
            $prev = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
            $next = $monthStart->copy()->addMonthNoOverflow()->startOfMonth();
            $prevUrl = route('vakman.planning', ['view' => 'month', 'month' => $prev->format('Y-m')]);
            $nextUrl = route('vakman.planning', ['view' => 'month', 'month' => $next->format('Y-m')]);
            $todayUrl = route('vakman.planning', ['view' => 'month', 'month' => now()->format('Y-m')]);
        } else {
            $from = $weekStart->copy();
            $to = $weekStart->copy()->addDays(5);
            $periodLabel = 'Week '.$weekStart->isoWeek().' · '.$weekStart->translatedFormat('j M').' – '.$to->translatedFormat('j M Y');
            $prevUrl = route('vakman.planning', ['view' => 'week', 'week' => $weekStart->copy()->subWeek()->toDateString()]);
            $nextUrl = route('vakman.planning', ['view' => 'week', 'week' => $weekStart->copy()->addWeek()->toDateString()]);
            $todayUrl = route('vakman.planning', ['view' => 'week', 'week' => now()->startOfWeek(Carbon::MONDAY)->toDateString()]);
        }

        $assignments = $this->assignments($user, $from, $to);
        $colleagues = $this->colleagueAssignmentsForWindow($user, $assignments, $from, $to);
        $entries = $this->hourEntries($user, $from, $to);
        $days = $this->calendarDays($from, $to, $monthStart, $user, $assignments, $colleagues, $entries);

        return [
            'view' => $view,
            'weekStart' => $weekStart,
            'monthStart' => $monthStart,
            'periodLabel' => $periodLabel,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
            'todayUrl' => $todayUrl,
            'hasJobs' => $days->contains(fn (array $day): bool => $day['jobs'] !== []),
            'days' => $days,
        ];
    }

    /**
     * @return array{
     *     date: Carbon,
     *     heading: string,
     *     jobs: list<array<string, mixed>>,
     *     isExternal: bool
     * }
     */
    public function day(User $user, CarbonInterface $date): array
    {
        $user->loadMissing(['worker.crewPeople', 'worker.availabilities', 'crewMember']);
        $day = $date->copy()->startOfDay();
        $assignments = $this->assignments($user, $day, $day);
        $colleagues = $this->colleagueAssignmentsForWindow($user, $assignments, $day, $day);
        $entries = $this->hourEntries($user, $day, $day);
        $jobs = $this->jobsOnDate($user, $assignments, $colleagues, $day, true, $entries);

        return [
            'date' => $day,
            'heading' => $this->dayHeading($day),
            'jobs' => $jobs,
            'can_register_hours' => $user->canRegisterHours(),
            'unplanned_url' => $user->canRegisterHours()
                ? route('vakman.hours.create', $day->toDateString())
                : null,
            'isExternal' => $user->worker?->employment_type?->isExternal() ?? false,
        ];
    }

    public function weekStart(?string $week): Carbon
    {
        $date = $week ? Carbon::parse($week) : now();

        return $date->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function monthStart(?string $month, ?string $week = null): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth()->startOfDay();
        }

        $date = $week ? Carbon::parse($week) : now();

        return $date->copy()->startOfMonth()->startOfDay();
    }

    /**
     * @return Collection<int, WorkerAssignment>
     */
    private function assignments(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null) {
            return collect();
        }

        return WorkerAssignment::query()
            ->with([
                'project.customer',
                'project.workItems.areaTasks.area.floor',
                'project.workActivities.category',
                'project.documents',
                'project.workOrders',
                'worker.rates',
                'worker.crewPeople',
                'workItem.areaTasks.area.floor',
                'crewMembers',
                'foreman',
                'workTicketHolder',
                'workTickets.lines.workItem',
                'workTickets.areas.floor',
                'workTickets.floors',
                'workTickets.documents',
            ])
            ->where('worker_id', $workerId)
            ->whereDate('end_date', '>=', $from)
            ->whereDate('start_date', '<=', $to)
            ->where(function ($query): void {
                $query->whereNull('origin')->orWhere('origin', '!=', 'hours');
            })
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkerAssignment>  $colleagues
     * @return Collection<int, array<string, mixed>>
     */
    private function calendarDays(
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $monthStart,
        User $user,
        Collection $assignments,
        Collection $colleagues,
        Collection $entries,
    ): Collection {
        $days = collect();
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $jobs = $this->jobsOnDate($user, $assignments, $colleagues, $cursor, false, $entries);
            $days->push([
                'date' => $cursor->copy(),
                'key' => $cursor->toDateString(),
                'heading' => $this->dayHeading($cursor),
                'short' => $cursor->translatedFormat('j'),
                'weekday' => ucfirst($cursor->translatedFormat('D')),
                'weekday_full' => Str::upper($cursor->translatedFormat('l')),
                'date_label' => Str::upper($cursor->translatedFormat('j F')),
                'is_today' => $cursor->isSameDay(now()),
                'in_month' => $cursor->isSameMonth($monthStart),
                'url' => $jobs !== [] ? route('vakman.planning.day', $cursor->toDateString()) : null,
                'jobs' => $jobs,
                'absence' => $jobs === [] ? $this->registeredAbsence($user, $cursor) : null,
            ]);
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkerAssignment>  $colleagues
     * @return list<array<string, mixed>>
     */
    private function jobsOnDate(User $user, Collection $assignments, Collection $colleagues, CarbonInterface $date, bool $detailed, ?Collection $entries = null): array
    {
        $entries ??= collect();
        $isExternal = $user->worker?->employment_type?->isExternal() ?? false;
        $own = $assignments
            ->filter(fn (WorkerAssignment $assignment): bool => ($assignment->project !== null || $assignment->isInternal())
                && ! $assignment->isHoursOrigin()
                && $assignment->coversDate($date)
                && ($isExternal || $assignment->includesVakman($user)))
            ->values();

        $others = $colleagues;

        return $own->map(function (WorkerAssignment $assignment) use ($user, $others, $date, $detailed, $isExternal, $entries): array {
            if ($assignment->isInternal()) {
                return $this->internalJobCard($user, $assignment, $date, $isExternal);
            }

            $project = $assignment->project;
            $tickets = $this->ticketsOnDate($assignment, $date);
            $hourlyOpdracht = $isExternal ? $this->hourlyOpdracht($tickets) : null;
            $holder = $isExternal ? null : $assignment->workTicketHolder;
            $card = [
                'assignment' => $assignment,
                'project' => $project,
                'card_id' => 'vakman-job-'.$date->toDateString().'-'.$assignment->id,
                'date' => $date->toDateString(),
                'project_name' => $project->displayTitle(),
                'city' => trim((string) $project->city),
                'numbers' => $project->labeledNumbersLine(),
                'address' => $project->nawLine(),
                'maps_url' => $project->googleMapsUrl(),
                'kind_label' => $isExternal ? null : $this->kindLabel($project),
                'time_label' => $this->timeLabel($assignment, $isExternal),
                'headline' => $this->headlineWork($assignment, $tickets),
                'summary' => $isExternal ? null : $this->workSummary($assignment, $tickets),
                'people' => $isExternal ? [] : $this->peopleOnAssignment($user, $assignment),
                'colleagues' => $isExternal
                    ? $this->colleagueNamesForExternal($user, $assignment, $others, $date)
                    : $this->colleagueNames($user, $assignment, $others, $date),
                'foreman' => $isExternal ? null : $assignment->foreman?->label(),
                'work_ticket_holder' => $holder?->label(),
                'is_work_ticket_holder' => ! $isExternal && $assignment->isWorkTicketResponsible($user),
                'url' => route('vakman.planning.day', $date->toDateString()),
                'project_url' => route('projects.show', $project),
                'drawing_url' => $this->drawingUrl($project),
                'tickets' => $tickets,
                'werkbon_url' => $this->werkbonUrl($user, $assignment, $tickets, $date, $isExternal),
                'opdrachtbon_url' => $isExternal
                    ? route('vakman.planning.opdrachtbon', [$date->toDateString(), $project])
                    : null,
                'hour_slots' => $this->hourSlots($user, $assignment, $date, $entries),
                'can_register_hours' => $isExternal
                    ? $hourlyOpdracht !== null
                    : $user->canRegisterHours(),
                'hourly_label' => $hourlyOpdracht?->billingLabel(),
            ];

            if (! $detailed) {
                return $card;
            }

            $works = $isExternal && $hourlyOpdracht === null
                ? $this->pricedWorks($assignment)
                : $this->works($assignment, $tickets);
            $rooms = $this->rooms($assignment, $works, $tickets);

            return [
                ...$card,
                'floors' => $rooms['floors'],
                'rooms' => $rooms['rooms'],
                'works' => $works,
                'notes' => $this->notes($assignment, $tickets),
                'drawings' => $this->drawings($project, $tickets),
                'opdracht' => $isExternal
                    ? Voucher::latestOpdracht((int) $assignment->worker_id, (int) $project->id)
                    : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function internalJobCard(User $user, WorkerAssignment $assignment, CarbonInterface $date, bool $isExternal): array
    {
        $description = trim((string) $assignment->description);
        $notes = trim((string) $assignment->notes);

        return [
            'assignment' => $assignment,
            'project' => null,
            'is_internal' => true,
            'card_id' => 'vakman-job-'.$date->toDateString().'-'.$assignment->id,
            'date' => $date->toDateString(),
            'project_name' => $assignment->business_unit?->label() ?? 'Intern – inzet',
            'city' => '',
            'numbers' => '',
            'address' => null,
            'maps_url' => null,
            'kind_label' => $isExternal ? null : 'Intern',
            'time_label' => $this->timeLabel($assignment, $isExternal),
            'headline' => '',
            'summary' => $description,
            'contact_name' => trim((string) $assignment->contact_name),
            'people' => $isExternal ? [] : $this->peopleOnAssignment($user, $assignment),
            'colleagues' => [],
            'foreman' => null,
            'work_ticket_holder' => null,
            'is_work_ticket_holder' => false,
            'url' => route('vakman.planning.day', $date->toDateString()),
            'project_url' => null,
            'drawing_url' => null,
            'tickets' => collect(),
            'werkbon_url' => null,
            'opdrachtbon_url' => null,
            'hour_slots' => [],
            'can_register_hours' => false,
            'floors' => [],
            'rooms' => [],
            'works' => [],
            'notes' => $notes !== '' ? [$notes] : [],
            'drawings' => [],
            'opdracht' => null,
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $own
     * @return Collection<int, WorkerAssignment>
     */
    private function colleagueAssignmentsForWindow(User $user, Collection $own, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $workerId = $user->scheduledWorkerId();
        $projectIds = $own->pluck('project_id')->unique()->filter()->values();
        if ($workerId === null || $projectIds->isEmpty()) {
            return collect();
        }

        return WorkerAssignment::query()
            ->with(['worker.crewPeople', 'crewMembers'])
            ->whereIn('project_id', $projectIds)
            ->whereNotIn('id', $own->modelKeys())
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $others
     * @return list<string>
     */
    private function colleagueNames(
        User $user,
        WorkerAssignment $assignment,
        Collection $others,
        CarbonInterface $date,
    ): array {
        $ownInterval = $assignment->intervalOnDate($date);

        $overlapping = $others->filter(function (WorkerAssignment $other) use ($assignment, $date, $ownInterval): bool {
            if ((int) $other->project_id !== (int) $assignment->project_id) {
                return false;
            }
            if (! $other->coversDate($date)) {
                return false;
            }

            return $this->intervalsOverlapOnDate($ownInterval, $other->intervalOnDate($date));
        });

        return collect($this->personNamesOnAssignment($assignment))
            ->concat($overlapping->flatMap(fn (WorkerAssignment $row): array => $this->personNamesOnAssignment($row)))
            ->filter(fn (string $name): bool => $name !== '' && ! $this->isOwnColleagueName($user, $name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $others
     * @return list<string>
     */
    private function colleagueNamesForExternal(
        User $user,
        WorkerAssignment $assignment,
        Collection $others,
        CarbonInterface $date,
    ): array {
        $ownLabels = array_values(array_filter([
            trim((string) $user->name),
            $assignment->worker?->planName(),
        ], fn (string $name): bool => $name !== ''));

        $overlapping = $others->filter(function (WorkerAssignment $other) use ($assignment, $date): bool {
            return (int) $other->project_id === (int) $assignment->project_id
                && $other->coversDate($date);
        });

        return collect($assignment->presentNames())
            ->concat($overlapping->flatMap(function (WorkerAssignment $row): array {
                $names = $row->presentNames();
                if ($names !== []) {
                    return $names;
                }

                $team = trim((string) ($row->worker?->planName() ?? ''));

                return $team !== '' ? [$team] : [];
            }))
            ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $ownLabels, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function personNamesOnAssignment(WorkerAssignment $assignment): array
    {
        $present = $assignment->presentNames();
        if ($present !== []) {
            return array_values(array_filter(array_map(
                fn (string $name): ?string => $this->colleagueLabel($name),
                $present,
            )));
        }

        $worker = $assignment->worker;
        if ($worker === null) {
            return [];
        }

        if ($worker->employment_type?->isExternal()) {
            $company = trim((string) $worker->company);
            if ($company !== '') {
                return [$company];
            }

            $name = $this->colleagueLabel($worker->planName());

            return $name !== null ? [$name] : [];
        }

        $crew = $worker->relationLoaded('crewPeople')
            ? $worker->activeCrewPeople()
            : collect();
        $named = $crew
            ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
            ->map(fn (CrewMember $member): ?string => $this->colleagueLabel($member->label()))
            ->filter()
            ->values()
            ->all();
        if ($named !== []) {
            return $named;
        }

        $personal = $this->colleagueLabel($worker->displayName());

        return $personal !== null ? [$personal] : [];
    }

    private function colleagueLabel(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $this->looksLikeTeamLabel($name)) {
            return null;
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $short = $parts[0] ?? $name;
        if ($this->looksLikeTeamLabel($short)) {
            return null;
        }

        return $short;
    }

    private function looksLikeTeamLabel(string $name): bool
    {
        return preg_match('/^team(\s|\d|$)/iu', trim($name)) === 1;
    }

    private function isOwnColleagueName(User $user, string $name): bool
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return false;
        }

        return collect([
            trim((string) $user->name),
            trim((string) ($user->crewMember?->label() ?? '')),
        ])
            ->filter()
            ->flatMap(fn (string $label): array => array_filter([$label, $this->colleagueLabel($label)]))
            ->map(fn (string $label): string => mb_strtolower(trim($label)))
            ->contains($needle);
    }

    /**
     * @return array{key: string, label: string}|null
     */
    private function registeredAbsence(User $user, CarbonInterface $date): ?array
    {
        $worker = $user->worker;
        if ($worker === null) {
            return null;
        }

        $absence = $this->availability->absenceOn($worker, $date, $user->crewMember);
        if ($absence === null || ! empty($absence['structural']) || ! is_array($absence)) {
            return null;
        }

        $label = $absence['label'] === 'Vrij op vrijdag' ? 'Vrije dag' : $absence['label'];

        return [
            'key' => (string) ($absence['key'] ?? 'overig'),
            'label' => Str::upper($label),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $left
     * @param  array{0: Carbon, 1: Carbon}|null  $right
     */
    private function intervalsOverlapOnDate(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return true;
        }

        return PlanningHours::intervalsOverlap($left[0], $left[1], $right[0], $right[1]);
    }

    private function drawingUrl(Project $project): ?string
    {
        if ($project->isWinkel() || $project->plattegrond() === null) {
            return null;
        }

        return route('projects.show', $project);
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     */
    private function werkbonUrl(
        User $user,
        WorkerAssignment $assignment,
        Collection $tickets,
        CarbonInterface $date,
        bool $isExternal,
    ): ?string {
        if ($isExternal || ! $assignment->isWorkTicketResponsible($user)) {
            return null;
        }

        $ticket = $tickets->first();
        if ($ticket !== null) {
            return route('work-tickets.show', $ticket);
        }

        return route('vakman.planning.werkbon', $date->toDateString());
    }

    private function kindLabel(Project $project): string
    {
        return match ($project->kind) {
            ProjectKind::Winkel => 'Winkel',
            ProjectKind::Service => 'Service',
            ProjectKind::Klein => 'Klein werk',
            default => 'Projecten',
        };
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     */
    private function workSummary(WorkerAssignment $assignment, Collection $tickets): ?string
    {
        $headline = $this->headlineWork($assignment, $tickets);
        foreach ($tickets as $ticket) {
            $text = $this->shortenDescription((string) ($ticket->notes ?? ''));
            if ($text !== null && mb_strtolower($text) !== mb_strtolower($headline)) {
                return $text;
            }
        }

        $own = $this->shortenDescription((string) ($assignment->notes ?? ''));
        if ($own !== null && mb_strtolower($own) !== mb_strtolower($headline)) {
            return $own;
        }

        $project = $assignment->project;
        $description = $this->shortenDescription((string) ($project?->work_description ?? ''));
        if ($description !== null && mb_strtolower($description) !== mb_strtolower($headline)) {
            return $description;
        }

        if ($project?->isWinkel()) {
            $shop = $this->shortenDescription((string) ($project->shopWorkLine() ?? ''));
            if ($shop !== null && mb_strtolower($shop) !== mb_strtolower($headline)) {
                return $shop;
            }
        }

        return null;
    }

    private function shortenDescription(string $text): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($clean === '') {
            return null;
        }

        return Str::limit($clean, 140);
    }

    /**
     * @return list<string>
     */
    private function peopleOnAssignment(User $user, WorkerAssignment $assignment): array
    {
        $names = $assignment->presentNames();
        if ($names !== []) {
            return $names;
        }

        $own = trim((string) ($user->name ?: $assignment->worker?->displayName() ?: ''));

        return $own !== '' ? [$own] : [];
    }

    private function headlineWork(WorkerAssignment $assignment, Collection $tickets): string
    {
        if ($tickets->isNotEmpty()) {
            return $tickets
                ->flatMap(fn (WorkTicket $ticket) => $ticket->lines)
                ->map(fn ($line): string => $line->workItem?->planningTitle() ?? '')
                ->filter()
                ->unique()
                ->values()
                ->implode(' & ');
        }

        $item = $this->resolvedWorkItem($assignment);
        if ($item !== null) {
            return $item->planningTitle();
        }

        $project = $assignment->project;
        if ($project === null) {
            return '';
        }

        if ($project->isWinkel()) {
            return (string) ($project->shopWorkLine() ?? $project->shopHeadline());
        }

        return $this->workItems($assignment)
            ->map(fn (WorkItem $item): string => $item->planningTitle())
            ->filter()
            ->unique()
            ->values()
            ->implode(' & ');
    }

    /**
     * @return list<array{title: string, quantity: string, unit: string, unit_enum: WorkUnit, item: ?WorkItem, amount: ?float, price_label: ?string}>
     */
    public function pricedWorks(WorkerAssignment $assignment): array
    {
        $worker = $assignment->worker;
        $project = $assignment->project;
        $tickets = $assignment->relationLoaded('workTickets') ? $assignment->workTickets : collect();
        $opdracht = $worker !== null && $project !== null
            ? Voucher::latestOpdracht((int) $worker->id, (int) $project->id)
            : null;
        $orders = $project?->workOrders
            ? $project->workOrders->where('worker_id', $assignment->worker_id)->values()
            : collect();

        if ($worker !== null) {
            $worker->loadMissing('rates');
        }

        return array_map(function (array $work) use ($worker, $opdracht, $orders): array {
            $item = $work['item'];
            $unit = $work['unit_enum'];
            $resolved = $worker !== null
                ? $this->prices->resolve($worker, $item, $unit, $opdracht, $orders)
                : ['price' => null, 'source' => null];
            $price = $resolved['price'];
            $quantity = (float) ($item?->ordered_quantity ?? 0);
            $order = $item !== null
                ? $orders->first(fn ($row): bool => (int) $row->work_item_id === (int) $item->id)
                : null;
            if ($order !== null && (float) $order->assigned_quantity > 0.0001) {
                $quantity = (float) $order->assigned_quantity;
            }
            if (isset($work['quantity_value'])) {
                $quantity = (float) $work['quantity_value'];
            }

            $kind = $opdracht?->lines
                ->first(fn ($line): bool => $item !== null && (int) $line->work_item_id === (int) $item->id)
                ?->price_kind;
            $isFixed = $kind === VoucherPriceKind::Fixed;
            $amount = $price !== null
                ? ($isFixed
                    ? (float) ($opdracht?->lines
                        ->first(fn ($line): bool => $item !== null && (int) $line->work_item_id === (int) $item->id)
                        ?->amount ?? 0)
                    : round($quantity * $price, 2))
                : null;

            $work['price_label'] = $price === null
                ? null
                : ($isFixed
                    ? 'Vaste prijs'
                    : Format::money($price).' / '.$unit->label());
            $work['amount'] = $amount;

            return $work;
        }, $this->works($assignment, $tickets));
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<array{title: string, quantity: string, unit: string, unit_enum: WorkUnit, item: ?WorkItem, quantity_value: float, note: string}>
     */
    private function works(WorkerAssignment $assignment, Collection $tickets): array
    {
        if ($tickets->isNotEmpty()) {
            return $tickets
                ->flatMap(fn (WorkTicket $ticket) => $ticket->lines)
                ->groupBy('work_item_id')
                ->map(function (Collection $lines) {
                    $first = $lines->first();
                    $item = $first?->workItem;
                    $quantity = (float) $lines->sum('quantity');
                    $unit = $first?->unit ?? $item?->unit ?? WorkUnit::SquareMeter;

                    return [
                        'title' => $item?->planningTitle() ?? 'Werkzaamheid',
                        'quantity' => Format::qty($quantity, abs($quantity - round($quantity)) < 0.001 ? 0 : 2),
                        'quantity_value' => $quantity,
                        'unit' => $unit->label(),
                        'unit_enum' => $unit,
                        'item' => $item,
                        'note' => trim((string) ($item?->notes ?? '')),
                    ];
                })
                ->values()
                ->all();
        }

        $orders = $assignment->project?->workOrders
            ? $assignment->project->workOrders->where('worker_id', $assignment->worker_id)
            : collect();

        return $this->workItems($assignment)
            ->concat($this->smallWorkActivityItems($assignment))
            ->unique('id')
            ->map(function (WorkItem $item) use ($orders): array {
                $order = $orders->first(fn ($row): bool => (int) $row->work_item_id === (int) $item->id);
                $quantity = $order !== null && (float) $order->assigned_quantity > 0.0001
                    ? (float) $order->assigned_quantity
                    : (float) $item->ordered_quantity;
                $unit = $order?->unit ?? $item->unit;

                return [
                    'title' => $item->work_activity_id !== null ? $item->name : $item->planningTitle(),
                    'quantity' => Format::qty($quantity, abs($quantity - round($quantity)) < 0.001 ? 0 : 2),
                    'quantity_value' => $quantity,
                    'unit' => $unit->label(),
                    'unit_enum' => $unit,
                    'item' => $item,
                    'note' => trim((string) $item->notes),
                ];
            })->values()->all();
    }

    /**
     * @return Collection<int, WorkItem>
     */
    private function smallWorkActivityItems(WorkerAssignment $assignment): Collection
    {
        $project = $assignment->project;
        if ($project === null || ! $project->isSmallWork()) {
            return collect();
        }

        return $project->workItems
            ->filter(fn (WorkItem $item): bool => $item->work_activity_id !== null)
            ->values();
    }

    /**
     * @return Collection<int, WorkItem>
     */
    private function workItems(WorkerAssignment $assignment): Collection
    {
        $resolved = $this->resolvedWorkItem($assignment);
        if ($resolved !== null) {
            return collect([$resolved]);
        }

        $project = $assignment->project;
        if ($project === null) {
            return collect();
        }

        $orderedIds = $project->workOrders
            ->where('worker_id', $assignment->worker_id)
            ->pluck('work_item_id')
            ->filter()
            ->unique()
            ->values();
        if ($orderedIds->isNotEmpty()) {
            return $project->workItems->whereIn('id', $orderedIds)->values();
        }

        return collect();
    }

    private function resolvedWorkItem(WorkerAssignment $assignment): ?WorkItem
    {
        if ($assignment->workItem !== null) {
            return $assignment->workItem;
        }

        $id = $assignment->resolvedWorkItemId();
        if ($id === null) {
            return null;
        }

        return $assignment->project?->workItems->firstWhere('id', $id);
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @param  list<array{item: ?WorkItem}>  $works
     * @return array{floors: list<string>, rooms: list<string>}
     */
    private function rooms(WorkerAssignment $assignment, array $works, Collection $tickets): array
    {
        if ($tickets->isNotEmpty()) {
            $floors = $tickets
                ->map(fn (WorkTicket $ticket): string => $ticket->floorsLabel())
                ->filter()
                ->unique()
                ->values()
                ->all();
            $rooms = $tickets
                ->map(fn (WorkTicket $ticket): string => $ticket->roomsLabel())
                ->filter(fn (string $label): bool => $label !== '' && $label !== 'Hele verdieping')
                ->flatMap(fn (string $label): array => array_map('trim', explode(',', $label)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return ['floors' => $floors, 'rooms' => $rooms];
        }

        $floors = [];
        $rooms = [];
        foreach (collect($works)->pluck('item')->filter() as $item) {
            foreach ($item->areaTasks as $task) {
                $area = $task->area;
                if ($area === null) {
                    continue;
                }
                $room = trim($area->label());
                if ($room !== '') {
                    $rooms[] = $room;
                }
                $floor = trim((string) ($area->floor?->name ?? ''));
                if ($floor !== '') {
                    $floors[] = $floor;
                }
            }
        }

        return [
            'floors' => array_values(array_unique($floors)),
            'rooms' => array_values(array_unique($rooms)),
        ];
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<string>
     */
    private function notes(WorkerAssignment $assignment, Collection $tickets): array
    {
        $notes = [];
        $own = trim((string) ($assignment->notes ?? ''));
        if ($own !== '') {
            $notes[] = $own;
        }

        foreach ($tickets as $ticket) {
            $text = trim((string) ($ticket->notes ?? ''));
            if ($text !== '') {
                $notes[] = $text;
            }
        }

        return array_values(array_unique($notes));
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<ProjectDocument>
     */
    private function drawings(Project $project, Collection $tickets): array
    {
        $fromTickets = $tickets
            ->flatMap(fn (WorkTicket $ticket) => $ticket->documents)
            ->unique('id')
            ->values()
            ->all();
        if ($fromTickets !== []) {
            return $fromTickets;
        }

        $drawing = $project->plattegrond();

        return $drawing instanceof ProjectDocument ? [$drawing] : [];
    }

    /**
     * @return Collection<int, WorkTicket>
     */
    /**
     * @param  Collection<int, WorkTicket>  $tickets
     */
    private function hourlyOpdracht(Collection $tickets): ?WorkTicket
    {
        return $tickets->first(
            fn (WorkTicket $ticket): bool => $ticket->isOpdrachtbon()
                && $ticket->billing_method === WorkTicketBilling::Hourly
        );
    }

    private function ticketsOnDate(WorkerAssignment $assignment, CarbonInterface $date): Collection
    {
        if (! $assignment->relationLoaded('workTickets')) {
            return collect();
        }

        return $assignment->workTickets
            ->filter(fn (WorkTicket $ticket): bool => $date->betweenIncluded($ticket->start_date, $ticket->end_date))
            ->values();
    }

    private function timeLabel(WorkerAssignment $assignment, bool $isExternal = false): string
    {
        $start = PlanningHours::formatTime($assignment->startTimeValue());
        $end = PlanningHours::formatTime($assignment->endTimeValue());
        if (! $isExternal && $start === PlanningHours::DAY_START && $end === PlanningHours::DAY_END) {
            return 'Hele dag';
        }

        return $start.' – '.$end;
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    private function hourEntries(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null) {
            return collect();
        }

        return TimeEntry::query()
            ->with('reviewer')
            ->where('worker_id', $workerId)
            ->where(function ($query) use ($user): void {
                $crewId = $this->hours->resolvedCrewMemberId($user, $user->worker);
                if ($crewId === null) {
                    $query->whereNull('crew_member_id');

                    return;
                }

                $query->where('crew_member_id', $crewId)
                    ->orWhereNull('crew_member_id');
            })
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{assignment_id: int, project_id: int, work_item_id: ?int, work_title: string, planned_hours: float, entry: ?TimeEntry}>
     */
    private function hourSlots(User $user, WorkerAssignment $assignment, CarbonInterface $date, Collection $entries): array
    {
        $item = $assignment->workItem ?? $this->resolvedWorkItem($assignment);
        $crewId = $this->hours->resolvedCrewMemberId($user, $user->worker);
        $visitEntries = $entries->filter(function (TimeEntry $entry) use ($assignment, $date, $crewId): bool {
            return (int) $entry->worker_assignment_id === (int) $assignment->id
                && $entry->date->toDateString() === $date->toDateString()
                && (int) ($entry->crew_member_id ?? 0) === (int) ($crewId ?? 0);
        })->values();
        $entry = $visitEntries->first(fn (TimeEntry $row): bool => ! $row->isApproved())
            ?? $visitEntries->first();

        return [[
            'assignment_id' => (int) $assignment->id,
            'project_id' => (int) $assignment->project_id,
            'work_item_id' => $item?->id,
            'work_title' => $item?->planningTitle() ?? 'Werkzaamheid',
            'planned_hours' => round($assignment->hoursOnDate($date), 2),
            'entry' => $entry,
        ]];
    }

    private function dayHeading(CarbonInterface $date): string
    {
        return ucfirst($date->translatedFormat('l j F'));
    }
}
