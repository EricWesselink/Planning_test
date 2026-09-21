<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Services\PersonnelHoursService;
use App\Services\PersonnelWeekService;
use App\Services\PlanningBoardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerPersonnelController extends Controller
{
    public const TABS = ['weekstaat', 'goedkeuren', 'overzicht', 'afwezigheid', 'werkdagen'];

    public function __construct(
        private PlanningBoardService $board,
        private PersonnelWeekService $weeks,
        private PersonnelHoursService $hours,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-personnel');

        $weekStart = $this->board->weekStart(
            $request->string('week')->toString() ?: null,
            $request->filled('week_nr') ? $request->integer('week_nr') : null,
            $request->filled('year') ? $request->integer('year') : null,
        );
        $days = $this->board->weekDays($weekStart, 1);
        $tab = $this->tab($request);
        $staff = $this->weeks->forDays($days);
        $people = $tab === 'weekstaat' || $tab === 'goedkeuren'
            ? $this->hours->weekstaat($days)
            : $staff;

        $payload = [
            'tab' => $tab,
            'people' => $people,
            'staff' => $staff,
            'days' => $days,
            'weekStart' => $weekStart,
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'thisWeek' => $this->board->weekStart(null)->toDateString(),
            'canReviewHours' => $request->user()?->canReviewHours() ?? false,
            'pendingEntries' => [],
            'overview' => null,
            'filters' => ['workers' => collect(), 'projects' => collect(), 'workItems' => collect()],
            'dayDetails' => [],
            'detailDate' => null,
            'detailPerson' => null,
        ];

        if ($tab === 'goedkeuren') {
            $payload['pendingEntries'] = $this->hours->pendingForWeek($days);
        }

        if ($tab === 'overzicht') {
            $payload['overview'] = $this->hours->overview($request);
            $payload['filters'] = $this->hours->filterOptions();
        }

        if ($tab === 'weekstaat' && $request->filled('day') && $request->filled('worker_id')) {
            $payload['detailDate'] = $request->date('day')->toDateString();
            $payload['detailPerson'] = $request->integer('crew_member_id') ?: null;
            $payload['dayDetails'] = $this->hours->detailsFor(
                $people,
                $request->integer('worker_id'),
                $request->filled('crew_member_id') ? $request->integer('crew_member_id') : null,
                $payload['detailDate'],
            );
        }

        return view('personnel.index', $payload);
    }

    public function update(Request $request, Worker $worker, CrewMember $crewMember): RedirectResponse
    {
        Gate::authorize('update', $worker);
        abort_unless($worker->employment_type === EmploymentType::Eigen, 403);
        abort_unless((int) $crewMember->worker_id === (int) $worker->id, 404);

        $data = $request->validate([
            'day' => ['required', 'integer', Rule::in(array_keys(CrewMember::WEEKDAY_LABELS))],
            'works' => ['present', 'boolean'],
        ], [
            'day.required' => 'Kies een weekdag.',
            'day.in' => 'Kies een weekdag van maandag tot zaterdag.',
        ]);

        $crewMember->setRelation('worker', $worker);
        $crewMember->setWorkDay((int) $data['day'], $request->boolean('works'));
        $crewMember->save();

        if ($worker->crewPeople()->count() <= 1) {
            $worker->update(['friday_off' => $crewMember->friday_off]);
        }

        $label = CrewMember::WEEKDAY_LABELS[(int) $data['day']];
        $status = $crewMember->worksOn((int) $data['day'])
            ? $label.' staat als werkdag.'
            : $label.' staat als vaste vrije dag.';

        return back()->with('status', $status);
    }

    private function tab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        return in_array($tab, self::TABS, true) ? $tab : 'weekstaat';
    }
}
