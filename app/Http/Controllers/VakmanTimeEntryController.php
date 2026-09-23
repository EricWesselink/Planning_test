<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkerAssignment;
use App\Services\TimeEntryService;
use App\Services\VakmanPlanningService;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VakmanTimeEntryController extends Controller
{
    public function create(Request $request, string $date, VakmanPlanningService $planning): View
    {
        $user = $this->vakman($request);
        abort_unless($user->canRegisterHours(), 403);

        $day = $this->parseDate($date);
        $projects = Project::query()->active()->with('workItems')->orderBy('name')->get();

        return view('vakman.hours-unplanned', [
            'worker' => $user->worker,
            'date' => $day,
            'detail' => $planning->day($user, $day),
            'projects' => $projects,
            'weekUrl' => route('vakman.planning', [
                'view' => 'week',
                'week' => $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request, TimeEntryService $hours): RedirectResponse
    {
        $user = $this->vakman($request);
        if (! $user->can('create', TimeEntry::class)) {
            $assignment = WorkerAssignment::query()
                ->with('workTickets')
                ->find((int) $request->input('worker_assignment_id'));
            abort_unless(
                $assignment !== null
                && (int) $assignment->worker_id === (int) $user->scheduledWorkerId()
                && $assignment->isHourlyOpdracht(),
                403,
            );
        }

        if ($request->exists('allocations')) {
            $data = $this->validatedDistribution($request);
            $entries = $hours->submitDistribution($user, $data);
            $total = round(array_sum(array_map(
                fn ($entry): float => $entry->submittedHoursValue(),
                $entries,
            )), 2);

            return back()->with('status', PlanningHours::hoursLabel($total).' ingediend');
        }

        $data = $this->validated($request);
        $entry = $hours->submit($user, $data);

        return back()->with('status', $entry->hoursLabel().' ingediend');
    }

    public function update(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        $this->vakman($request);
        Gate::authorize('update', $timeEntry);

        $data = $this->usesClock($request)
            ? $this->validatedClock($request, false)
            : $request->validate([
                'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
                'note' => ['nullable', 'string', 'max:2000'],
            ], [
                'hours.required' => 'Vul de gewerkte uren in.',
                'hours.min' => 'Uren moeten minimaal 0,25 zijn.',
            ]);

        $entry = $hours->updateOpen($timeEntry, $request->user(), $data);

        return back()->with('status', $entry->hoursLabel().' ingediend');
    }

    /**
     * @return array{date: string, hours: float, note: ?string, worker_assignment_id: ?int, project_id: ?int, work_item_id: ?int}
     */
    private function validated(Request $request): array
    {
        if ($this->usesClock($request)) {
            return $this->validatedClock($request, true);
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'note' => ['nullable', 'string', 'max:2000'],
            'worker_assignment_id' => ['nullable', 'integer', 'exists:worker_assignments,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'work_item_id' => ['nullable', 'integer', 'exists:work_items,id'],
        ], [
            'hours.required' => 'Vul de gewerkte uren in.',
            'hours.min' => 'Uren moeten minimaal 0,25 zijn.',
            'date.required' => 'Kies een datum.',
        ]);

        return [
            'date' => $data['date'],
            'hours' => (float) $data['hours'],
            'note' => $data['note'] ?? null,
            'worker_assignment_id' => isset($data['worker_assignment_id']) ? (int) $data['worker_assignment_id'] : null,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'work_item_id' => isset($data['work_item_id']) ? (int) $data['work_item_id'] : null,
        ];
    }

    /**
     * @return array{date: string, start_time: string, end_time: string, break_minutes: int, note: ?string, project_id: ?int, allocations: list<array{worker_assignment_id: int, work_item_id: ?int, hours: float}>}
     */
    private function validatedDistribution(Request $request): array
    {
        $allocations = $request->input('allocations', []);
        if (is_array($allocations)) {
            foreach ($allocations as $index => $row) {
                if (is_array($row) && array_key_exists('hours', $row)) {
                    $allocations[$index]['hours'] = str_replace(',', '.', (string) $row['hours']);
                }
                if (is_array($row) && ($row['work_item_id'] ?? '') === '') {
                    $allocations[$index]['work_item_id'] = null;
                }
            }
            $request->merge(['allocations' => $allocations]);
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'note' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.worker_assignment_id' => ['required', 'integer', 'exists:worker_assignments,id'],
            'allocations.*.work_item_id' => ['nullable', 'integer', 'exists:work_items,id'],
            'allocations.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
        ], [
            'start_time.required' => 'Vul een begintijd in.',
            'end_time.required' => 'Vul een eindtijd in.',
            'break_minutes.required' => 'Vul de pauze in minuten in.',
            'date.required' => 'Kies een datum.',
            'allocations.*.hours.required' => 'Vul de uren per werkzaamheid in.',
            'allocations.*.hours.min' => 'Uren moeten minimaal 0 zijn.',
        ]);

        return [
            'date' => $data['date'],
            'start_time' => PlanningHours::normalizeTime($data['start_time']),
            'end_time' => PlanningHours::normalizeTime($data['end_time']),
            'break_minutes' => (int) $data['break_minutes'],
            'note' => $data['note'] ?? null,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'allocations' => array_map(fn (array $row): array => [
                'worker_assignment_id' => (int) $row['worker_assignment_id'],
                'work_item_id' => isset($row['work_item_id']) ? (int) $row['work_item_id'] : null,
                'hours' => round((float) $row['hours'], 2),
            ], $data['allocations']),
        ];
    }

    private function usesClock(Request $request): bool
    {
        return $request->exists('start_time') || $request->exists('end_time') || $request->exists('break_minutes');
    }

    /**
     * @return array{date?: string, hours: float, start_time: string, end_time: string, break_minutes: int, note: ?string, worker_assignment_id?: ?int, project_id?: ?int, work_item_id?: ?int}
     */
    private function validatedClock(Request $request, bool $creating): array
    {
        $rules = [
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        if ($creating) {
            $rules['date'] = ['required', 'date'];
            $rules['worker_assignment_id'] = ['nullable', 'integer', 'exists:worker_assignments,id'];
            $rules['project_id'] = ['nullable', 'integer', 'exists:projects,id'];
            $rules['work_item_id'] = ['nullable', 'integer', 'exists:work_items,id'];
        }

        $data = $request->validate($rules, [
            'start_time.required' => 'Vul een begintijd in.',
            'end_time.required' => 'Vul een eindtijd in.',
            'break_minutes.required' => 'Vul de pauze in minuten in.',
            'date.required' => 'Kies een datum.',
        ]);

        $payload = [
            'hours' => 0,
            'start_time' => PlanningHours::normalizeTime($data['start_time']),
            'end_time' => PlanningHours::normalizeTime($data['end_time']),
            'break_minutes' => (int) $data['break_minutes'],
            'note' => $data['note'] ?? null,
        ];
        if ($creating) {
            $payload['date'] = $data['date'];
            $payload['worker_assignment_id'] = isset($data['worker_assignment_id']) ? (int) $data['worker_assignment_id'] : null;
            $payload['project_id'] = isset($data['project_id']) ? (int) $data['project_id'] : null;
            $payload['work_item_id'] = isset($data['work_item_id']) ? (int) $data['work_item_id'] : null;
        }

        return $payload;
    }

    private function vakman(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->isVakman(), 403);
        $user->loadMissing(['worker', 'crewMember']);

        return $user;
    }

    private function parseDate(string $date): Carbon
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $day = Carbon::createFromFormat('Y-m-d', $date);
        abort_unless($day instanceof Carbon && $day->format('Y-m-d') === $date, 404);

        return $day->startOfDay();
    }
}
