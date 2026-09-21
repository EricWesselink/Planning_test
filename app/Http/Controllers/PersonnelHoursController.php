<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\TimeEntry;
use App\Models\Worker;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonnelHoursController extends Controller
{
    public function approve(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        Gate::authorize('approve', $timeEntry);
        $hours->approve($timeEntry, $request->user());

        return back()->with('status', $timeEntry->fresh()?->hoursLabel().' goedgekeurd.');
    }

    public function reject(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        Gate::authorize('review', $timeEntry);
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $hours->reject($timeEntry, $request->user(), $data['review_note'] ?? null);

        return back()->with('status', 'Uren afgewezen.');
    }

    public function update(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        Gate::authorize('update', $timeEntry);
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'note' => ['nullable', 'string', 'max:2000'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'hours.required' => 'Vul de uren in.',
        ]);
        $hours->adjust($timeEntry, $request->user(), $data);

        return back()->with('status', 'Uren aangepast.');
    }

    public function approveWeek(Request $request, TimeEntryService $hours): RedirectResponse
    {
        Gate::authorize('review-hours');
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'crew_member_id' => ['nullable', 'integer', 'exists:crew_members,id'],
            'week' => ['required', 'date'],
        ]);
        $worker = Worker::query()->findOrFail($data['worker_id']);
        $approved = $hours->approveWeek(
            $request->user(),
            $worker,
            isset($data['crew_member_id']) ? (int) $data['crew_member_id'] : null,
            Carbon::parse($data['week']),
        );

        $count = count($approved);

        return back()->with('status', $count === 1 ? '1 regel goedgekeurd.' : $count.' regels goedgekeurd.');
    }

    public function updateWorkerSetting(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $data = $request->validate([
            'registers_hours' => ['required', 'boolean'],
        ]);
        $worker->update(['registers_hours' => $request->boolean('registers_hours')]);
        if ($worker->employment_type !== EmploymentType::Eigen && $worker->crewPeople()->count() <= 1) {
            $worker->crewPeople()->update(['registers_hours' => $worker->registers_hours]);
        }

        return back()->with('status', $worker->registersHours()
            ? 'Uren registreren staat aan.'
            : 'Uren registreren staat uit.');
    }

    public function updateMemberSetting(Request $request, Worker $worker, CrewMember $crewMember): RedirectResponse
    {
        Gate::authorize('update', $worker);
        abort_unless((int) $crewMember->worker_id === (int) $worker->id, 404);
        $request->validate([
            'registers_hours' => ['required', 'boolean'],
        ]);
        $crewMember->update(['registers_hours' => $request->boolean('registers_hours')]);

        return back()->with('status', $crewMember->registersHours()
            ? 'Uren registreren staat aan voor '.$crewMember->displayName().'.'
            : 'Uren registreren staat uit voor '.$crewMember->displayName().'.');
    }
}
