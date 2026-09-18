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

        return view('workers.absence', [
            'workers' => $workers,
        ]);
    }
}
