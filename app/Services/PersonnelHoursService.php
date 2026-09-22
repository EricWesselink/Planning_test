<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Enums\TimeEntryStatus;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Worker;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PersonnelHoursService
{
    public function __construct(
        private PersonnelWeekService $weeks,
    ) {}

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return list<array<string, mixed>>
     */
    public function weekstaat(Collection $days): array
    {
        $people = $this->weeks->forDays($days);
        $people = array_values(array_filter(
            $people,
            fn (array $row): bool => $this->personRegistersHours($row['worker'], $row['member']),
        ));
        $people = [...$people, ...$this->externalHourRows($days, $people)];

        $entries = $this->entriesForPeople($people, $days->first(), $days->last());
        foreach ($people as $index => $row) {
            $people[$index] = $this->overlayEntries($row, $days, $entries);
        }

        usort(
            $people,
            fn (array $left, array $right): int => mb_strtolower($left['member']->displayName())
                <=> mb_strtolower($right['member']->displayName())
        );

        return $people;
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return list<TimeEntry>
     */
    public function pendingForWeek(Collection $days): array
    {
        return TimeEntry::query()
            ->with(['worker', 'crewMember', 'project', 'workItem', 'assignment.crewMembers', 'submitter'])
            ->where('status', TimeEntryStatus::Submitted)
            ->whereDate('date', '>=', $days->first())
            ->whereDate('date', '<=', $days->last())
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    public function overview(Request $request): LengthAwarePaginator
    {
        return $this->overviewQuery($request)
            ->with(['worker', 'crewMember', 'project', 'workItem', 'assignment', 'submitter', 'reviewer'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @return array{submitted: float, approved: float, difference: float, label: string}
     */
    public function overviewTotals(Request $request): array
    {
        $row = $this->overviewQuery($request)
            ->selectRaw('COALESCE(SUM(hours), 0) as submitted_hours')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN COALESCE(approved_hours, 0) ELSE 0 END), 0) as approved_hours',
                [TimeEntryStatus::Approved->value],
            )
            ->first();

        $submitted = round((float) ($row?->getAttribute('submitted_hours') ?? 0), 2);
        $approved = round((float) ($row?->getAttribute('approved_hours') ?? 0), 2);
        $difference = round($approved - $submitted, 2);

        return [
            'submitted' => $submitted,
            'approved' => $approved,
            'difference' => $difference,
            'label' => 'Ingediend: '.PlanningHours::hoursLabel($submitted)
                .' | Goedgekeurd: '.PlanningHours::hoursLabel($approved)
                .' | Verschil: '.$this->signedHoursLabel($difference),
        ];
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function overviewQuery(Request $request): Builder
    {
        $query = TimeEntry::query();

        if ($request->filled('week')) {
            $start = Carbon::parse($request->string('week')->toString())->startOfWeek(Carbon::MONDAY);
            $query->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $start->copy()->addDays(5)->toDateString());
        }
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->date('to'));
        }
        $this->applyPersonFilter($query, $request->string('person')->toString());
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        if ($request->filled('work_number')) {
            $number = trim($request->string('work_number')->toString());
            $query->whereHas('project', fn ($q) => $q->where('project_number', 'like', '%'.$number.'%'));
        }
        if ($request->filled('status')) {
            $status = TimeEntryStatus::tryFrom($request->string('status')->toString());
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyPersonFilter(Builder $query, string $person): void
    {
        if (preg_match('/^member-(\d+)$/', $person, $matches) === 1) {
            $query->where('crew_member_id', (int) $matches[1]);

            return;
        }

        if (preg_match('/^worker-(\d+)$/', $person, $matches) === 1) {
            $query->where('worker_id', (int) $matches[1])->whereNull('crew_member_id');
        }
    }

    private function signedHoursLabel(float $hours): string
    {
        if ($hours > 0.0001) {
            return '+'.PlanningHours::hoursLabel($hours);
        }

        return PlanningHours::hoursLabel($hours);
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    public function detailsFor(array $people, int $workerId, ?int $crewMemberId, string $date): array
    {
        $row = collect($people)->first(function (array $person) use ($workerId, $crewMemberId): bool {
            if ((int) $person['worker']->id !== $workerId) {
                return false;
            }

            $memberId = $person['member']->exists ? (int) $person['member']->id : null;

            return $memberId === $crewMemberId;
        });

        return is_array($row) ? ($row['day_details'][$date] ?? []) : [];
    }

    /**
     * @return array{people: list<array{value: string, label: string}>, projects: Collection<int, Project>}
     */
    public function filterOptions(): array
    {
        $people = [];
        $workers = Worker::query()
            ->with('crewPeople')
            ->where(function ($query): void {
                $query->where('registers_hours', true)
                    ->orWhereHas('crewPeople', fn ($q) => $q->where('registers_hours', true));
            })
            ->get();

        foreach ($workers as $worker) {
            $members = $worker->crewPeople
                ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '' && $member->registersHours());
            if ($members->isNotEmpty()) {
                foreach ($members as $member) {
                    $people[] = [
                        'value' => 'member-'.$member->id,
                        'label' => $member->displayName(),
                    ];
                }

                continue;
            }

            if ($worker->registersHours()) {
                $people[] = [
                    'value' => 'worker-'.$worker->id,
                    'label' => $worker->planName(),
                ];
            }
        }

        usort($people, fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return [
            'people' => $people,
            'projects' => Project::query()->active()->orderBy('name')->limit(200)->get(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @return Collection<int, TimeEntry>
     */
    private function entriesForPeople(array $people, mixed $from, mixed $to): Collection
    {
        $workerIds = collect($people)->map(fn (array $row): int => (int) $row['worker']->id)->unique()->values();
        if ($workerIds->isEmpty() || $from === null || $to === null) {
            return collect();
        }

        return TimeEntry::query()
            ->with(['project', 'workItem', 'assignment.crewMembers', 'reviewer', 'worker', 'crewMember'])
            ->whereIn('worker_id', $workerIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, CarbonInterface>  $days
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<string, mixed>
     */
    private function overlayEntries(array $row, Collection $days, Collection $entries): array
    {
        $member = $row['member'];
        $worker = $row['worker'];
        $mine = $entries->filter(function (TimeEntry $entry) use ($worker, $member): bool {
            if ((int) $entry->worker_id !== (int) $worker->id) {
                return false;
            }
            if (! $member->exists) {
                return $entry->crew_member_id === null;
            }

            return (int) $entry->crew_member_id === (int) $member->id;
        });

        $dayDetails = [];
        $countedHours = 0.0;
        $statuses = [];
        $cells = [];

        foreach ($row['cells'] as $cell) {
            $date = $cell['date'];
            $dayEntries = $mine->filter(
                fn (TimeEntry $entry): bool => $entry->date->toDateString() === $date
            )->values();
            $dayHours = round((float) $dayEntries->sum(fn (TimeEntry $entry): float => $entry->accountedHoursValue()), 2);
            $countedHours += $dayHours;
            $dayStatus = $this->dayStatus($dayEntries);
            if ($dayStatus !== null) {
                $statuses[] = $dayStatus;
            }

            $details = $dayEntries->map(fn (TimeEntry $entry): array => $this->detail($entry))->all();
            $dayDetails[$date] = $details;
            $tone = $this->entryTone($dayEntries);

            if ($dayEntries->isNotEmpty()) {
                $unplanned = $dayEntries->contains(fn (TimeEntry $entry): bool => $entry->is_unplanned);
                $cells[] = [
                    ...$cell,
                    'hours' => $dayHours,
                    'hours_label' => $dayHours > 0.01 ? PlanningHours::hoursLabel($dayHours) : '',
                    'entry_status' => $dayStatus,
                    'entry_status_label' => $this->cellStatusLabel($dayEntries, $dayHours, $unplanned),
                    'entry_tone' => $tone,
                    'has_entries' => true,
                    'is_unplanned' => $unplanned,
                ];

                continue;
            }

            $cells[] = [
                ...$cell,
                'entry_status' => null,
                'entry_status_label' => null,
                'entry_tone' => null,
                'has_entries' => false,
                'is_unplanned' => false,
            ];
        }

        $weekStatus = $this->weekStatus($statuses, $countedHours);
        $weekReview = $this->weekReview($mine);

        return [
            ...$row,
            'cells' => $cells,
            'submitted_hours' => round($countedHours, 2),
            'submitted_label' => PlanningHours::hoursLabel($countedHours),
            'hours_status' => $weekStatus,
            'hours_status_label' => $weekReview['label'],
            'hours_tone' => $weekReview['tone'],
            'review_date' => $weekReview['date'],
            'day_details' => $dayDetails,
        ];
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function dayStatus(Collection $entries): ?TimeEntryStatus
    {
        if ($entries->isEmpty()) {
            return null;
        }
        if ($entries->every(fn (TimeEntry $entry): bool => $entry->isApproved())) {
            return TimeEntryStatus::Approved;
        }
        if ($entries->contains(fn (TimeEntry $entry): bool => $entry->isSubmitted())) {
            return TimeEntryStatus::Submitted;
        }
        if ($entries->every(fn (TimeEntry $entry): bool => $entry->isRejected())) {
            return TimeEntryStatus::Rejected;
        }

        return TimeEntryStatus::Submitted;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function cellStatusLabel(Collection $entries, float $hours, bool $unplanned): string
    {
        $hoursLabel = PlanningHours::hoursLabel($hours);
        $status = $this->dayStatus($entries);
        $prefix = $unplanned ? 'Niet gepland – ' : '';
        if ($status === TimeEntryStatus::Approved) {
            $adjusted = $entries->contains(fn (TimeEntry $entry): bool => $entry->isAdjusted());

            return $prefix.$hoursLabel.($adjusted ? ' aangepast' : ' goedgekeurd');
        }
        if ($status === TimeEntryStatus::Rejected) {
            return $prefix.$hoursLabel.' afgewezen';
        }

        return $prefix.$hoursLabel.' ingediend';
    }

    /**
     * @param  list<TimeEntryStatus>  $statuses
     */
    private function weekStatus(array $statuses, float $submittedHours): ?TimeEntryStatus
    {
        if ($statuses === [] || $submittedHours < 0.01) {
            return null;
        }
        if (in_array(TimeEntryStatus::Submitted, $statuses, true)) {
            return TimeEntryStatus::Submitted;
        }
        if (in_array(TimeEntryStatus::Rejected, $statuses, true) && ! in_array(TimeEntryStatus::Approved, $statuses, true)) {
            return TimeEntryStatus::Rejected;
        }
        if (in_array(TimeEntryStatus::Approved, $statuses, true)) {
            return TimeEntryStatus::Approved;
        }

        return TimeEntryStatus::Submitted;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function entryTone(Collection $entries): ?string
    {
        $status = $this->dayStatus($entries);
        if ($status === null) {
            return null;
        }
        if ($status === TimeEntryStatus::Approved && $entries->contains(fn (TimeEntry $entry): bool => $entry->isAdjusted())) {
            return 'adjusted';
        }

        return match ($status) {
            TimeEntryStatus::Approved => 'approved',
            TimeEntryStatus::Rejected => 'rejected',
            TimeEntryStatus::Submitted => 'pending',
        };
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{label: string, tone: ?string, date: ?string}
     */
    private function weekReview(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return ['label' => '—', 'tone' => null, 'date' => null];
        }

        $date = ($entries->first(fn (TimeEntry $entry): bool => $entry->isSubmitted())
            ?? $entries->first(fn (TimeEntry $entry): bool => $entry->isRejected())
            ?? $entries->first())?->date->toDateString();

        if ($entries->contains(fn (TimeEntry $entry): bool => $entry->isSubmitted())) {
            return ['label' => 'Te beoordelen', 'tone' => 'pending', 'date' => $date];
        }
        if ($entries->contains(fn (TimeEntry $entry): bool => $entry->isRejected())) {
            return ['label' => 'Afgewezen / Ter correctie', 'tone' => 'rejected', 'date' => $date];
        }
        if ($entries->contains(fn (TimeEntry $entry): bool => $entry->isAdjusted())) {
            return ['label' => 'Aangepast & goedgekeurd', 'tone' => 'adjusted', 'date' => $date];
        }

        return ['label' => 'Goedgekeurd', 'tone' => 'approved', 'date' => $date];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(TimeEntry $entry): array
    {
        return [
            'entry' => $entry,
            'project' => $entry->project?->displayTitle() ?? 'Project',
            'work_number' => $entry->project?->workNumber() ?? '',
            'work' => $entry->workName(),
            'planned' => $entry->planningHoursValue(),
            'planned_label' => PlanningHours::hoursLabel($entry->planningHoursValue()),
            'submitted' => $entry->hoursValue(),
            'submitted_label' => $entry->submittedIntervalLabel() ?? $entry->hoursLabel(),
            'break_label' => $entry->hasSubmittedTimes() ? $entry->breakLabel() : null,
            'net_label' => $entry->hasSubmittedTimes() ? $entry->hoursLabel() : null,
            'approved_label' => $entry->isApproved() ? $entry->approvedHoursLabel() : '—',
            'difference' => $entry->reviewDifferenceHours(),
            'difference_label' => ($entry->reviewDifferenceHours() > 0.0001 ? '+' : '').PlanningHours::hoursLabel($entry->reviewDifferenceHours()),
            'note' => $entry->note,
            'status' => $entry->status,
            'is_unplanned' => $entry->is_unplanned,
        ];
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function externalHourRows(Collection $days, array $existing): array
    {
        $seen = collect($existing)->map(fn (array $row): int => (int) $row['worker']->id)->all();
        $workers = Worker::query()
            ->with(['crewPeople', 'availabilities'])
            ->where('employment_type', '!=', EmploymentType::Eigen)
            ->where('registers_hours', true)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->orderBy('name')
            ->get();

        $people = [];
        foreach ($workers as $worker) {
            $members = $worker->crewPeople->isNotEmpty()
                ? $worker->crewPeople
                : collect([$worker->crewPeople()->make(['name' => $worker->name, 'sort_order' => 0])]);
            foreach ($members as $member) {
                $member->setRelation('worker', $worker);
                if ($member->exists && ! $member->registersHours()) {
                    continue;
                }
                $cells = [];
                foreach ($days as $day) {
                    $cells[] = [
                        'date' => $day->toDateString(),
                        'hours' => 0.0,
                        'hours_label' => '',
                        'status' => null,
                        'status_label' => null,
                    ];
                }
                $people[] = [
                    'worker' => $worker,
                    'member' => $member,
                    'cells' => $cells,
                    'worked_hours' => 0.0,
                    'worked_label' => PlanningHours::hoursLabel(0),
                    'absence_hours' => 0.0,
                    'absence_label' => PlanningHours::hoursLabel(0),
                    'week_summary' => 'Gewerkt 0u | Totaal afwezig 0u',
                ];
            }
        }

        return $people;
    }

    private function personRegistersHours(Worker $worker, CrewMember $member): bool
    {
        if ($member->exists) {
            $member->setRelation('worker', $worker);

            return $member->registersHours();
        }

        return $worker->registersHours();
    }
}
