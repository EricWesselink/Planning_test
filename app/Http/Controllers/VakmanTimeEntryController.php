<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use App\Services\VakmanPlanningService;
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
        Gate::authorize('create', TimeEntry::class);

        $data = $this->validated($request);
        $entry = $hours->submit($user, $data);

        return back()->with('status', $entry->hoursLabel().' ingediend');
    }

    public function update(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        $this->vakman($request);
        Gate::authorize('update', $timeEntry);

        $data = $request->validate([
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
