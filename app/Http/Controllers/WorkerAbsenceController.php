<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkerAbsenceController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Worker::class);

        $workers = Worker::query()
            ->ownStaff()
            ->with(['crewPeople', 'availabilities', 'users'])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $people = $workers
            ->flatMap(function (Worker $worker) {
                $members = $worker->crewPeople->isNotEmpty()
                    ? $worker->crewPeople
                    : collect([$worker->crewPeople()->make(['name' => $worker->name, 'sort_order' => 0])]);

                return $members->map(fn ($member): array => [
                    'worker' => $worker,
                    'member' => $member,
                ]);
            })
            ->values();

        return view('workers.absence', [
            'workers' => $workers,
            'people' => $people,
        ]);
    }
}
