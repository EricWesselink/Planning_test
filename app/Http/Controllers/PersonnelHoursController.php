<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\TimeEntry;
use App\Models\Worker;
use App\Services\TimeEntryService;
use App\Support\PlanningHours;
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
            'review_note' => ['required', 'string', 'max:2000'],
        ], [
            'review_note.required' => 'Vul een reden in om af te wijzen.',
        ]);
        $hours->reject($timeEntry, $request->user(), $data['review_note']);

        return back()->with('status', 'Uren afgewezen.');
    }

    public function update(Request $request, TimeEntry $timeEntry, TimeEntryService $hours): RedirectResponse
    {
        Gate::authorize('approve', $timeEntry);
        $hasClock = $request->exists('approved_start_time') || $request->exists('approved_end_time');
        $rules = [
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
        if ($hasClock) {
            $rules['approved_start_time'] = ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'];
            $rules['approved_end_time'] = ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'];
            $rules['approved_break_minutes'] = ['required', 'integer', 'min:0', 'max:1440'];
        }
        if ($request->exists('approved_hours') || ! $hasClock) {
            $rules['approved_hours'] = ['required', 'numeric', 'min:0', 'max:24'];
        }
        $data = $request->validate($rules, [
            'approved_start_time.required' => 'Vul een begintijd in.',
            'approved_end_time.required' => 'Vul een eindtijd in.',
            'approved_break_minutes.required' => 'Vul de pauze in minuten in.',
            'approved_hours.required' => 'Vul de goedgekeurde uren in.',
            'approved_hours.min' => 'Uren moeten minimaal 0 zijn.',
        ]);
        $clock = null;
        $net = null;
        if ($hasClock) {
            $start = PlanningHours::normalizeTime($data['approved_start_time']);
            $end = PlanningHours::normalizeTime($data['approved_end_time']);
            $breakMinutes = (int) $data['approved_break_minutes'];
            $net = $hours->clockNet($start, $end, $breakMinutes, true, 'approved_end_time', 'approved_break_minutes');
            $clock = [
                'start_time' => $start,
                'end_time' => $end,
                'break_minutes' => $breakMinutes,
            ];
        }
        $manualHours = array_key_exists('approved_hours', $data)
            ? round((float) $data['approved_hours'], 2)
            : null;
        $previousHours = round((float) ($timeEntry->approved_hours ?? $timeEntry->hours), 2);
        $hoursWereEdited = $manualHours !== null && abs($manualHours - $previousHours) > 0.01;
        $clockChanged = $clock !== null && $hours->approvedClockChanged($timeEntry, $clock);
        if ($hoursWereEdited) {
            $approvedHours = $manualHours;
        } elseif ($clockChanged) {
            $approvedHours = (float) $net;
        } else {
            $approvedHours = $manualHours ?? (float) $net;
        }
        $entry = $hours->approveAdjusted(
            $timeEntry,
            $request->user(),
            $approvedHours,
            $data['review_note'] ?? null,
            $clock,
        );

        return back()->with('status', $entry->isAdjusted()
            ? $entry->approvedHoursLabel().' aangepast en goedgekeurd.'
            : $entry->approvedHoursLabel().' goedgekeurd.');
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
