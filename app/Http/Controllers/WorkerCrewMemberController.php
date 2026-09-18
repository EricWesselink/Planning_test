<?php

namespace App\Http\Controllers;

use App\Models\CrewMember;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkerCrewMemberController extends Controller
{
    public function updateActive(Request $request, Worker $worker, CrewMember $crewMember): RedirectResponse
    {
        Gate::authorize('update', $worker);
        abort_unless((int) $crewMember->worker_id === (int) $worker->id, 404);
        $crewMember->setRelation('worker', $worker);

        $active = $request->boolean('active');

        DB::transaction(function () use ($crewMember, $active): void {
            $crewMember->update(['active' => $active]);
            $crewMember->user?->update(['active' => $active]);
        });

        $name = $crewMember->displayName();

        return back()->with(
            'status',
            $active ? $name.' is weer actief.' : $name.' is inactief gezet.',
        );
    }

    public function destroy(Worker $worker, CrewMember $crewMember): RedirectResponse
    {
        Gate::authorize('update', $worker);
        abort_unless((int) $crewMember->worker_id === (int) $worker->id, 404);
        $crewMember->setRelation('worker', $worker);

        if ($worker->crewPeople()->count() <= 1) {
            return back()->withErrors([
                'worker' => $crewMember->displayName().' is de laatste in het team. Zet hem inactief in plaats van te verwijderen.',
            ]);
        }

        $name = $crewMember->displayName();

        DB::transaction(function () use ($worker, $crewMember): void {
            $crewMember->user?->delete();
            $crewMember->delete();
            $worker->refreshRosterFromCrewPeople();
        });

        return back()->with('status', $name.' is verwijderd.');
    }
}
