<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Services\PersonnelWeekService;
use App\Services\PlanningBoardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerPersonnelController extends Controller
{
    public function __construct(
        private PlanningBoardService $board,
        private PersonnelWeekService $weeks,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Worker::class);

        $weekStart = $this->board->weekStart(
            $request->string('week')->toString() ?: null,
            $request->filled('week_nr') ? $request->integer('week_nr') : null,
            $request->filled('year') ? $request->integer('year') : null,
        );
        $days = $this->board->weekDays($weekStart, 1);
        $people = $this->weeks->forDays($days);

        return view('personnel.index', [
            'people' => $people,
            'days' => $days,
            'weekStart' => $weekStart,
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'thisWeek' => $this->board->weekStart(null)->toDateString(),
        ]);
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
}
