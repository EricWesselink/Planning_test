<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerPersonnelController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Worker::class);

        $workers = Worker::query()
            ->ownStaff()
            ->with('crewPeople')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $people = $workers
            ->flatMap(function (Worker $worker) {
                $members = $worker->crewPeople->isNotEmpty()
                    ? $worker->crewPeople
                    : collect([$worker->crewPeople()->make(['name' => $worker->name, 'sort_order' => 0])]);

                return $members->map(function (CrewMember $member) use ($worker): array {
                    $member->setRelation('worker', $worker);

                    return [
                        'worker' => $worker,
                        'member' => $member,
                    ];
                });
            })
            ->sortBy(fn (array $row): string => mb_strtolower($row['member']->displayName()))
            ->values();

        return view('workers.personnel', [
            'people' => $people,
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
