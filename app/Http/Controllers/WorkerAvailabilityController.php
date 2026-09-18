<?php

namespace App\Http\Controllers;

use App\Enums\AvailabilityKind;
use App\Enums\EmploymentType;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkerAvailabilityController extends Controller
{
    public function updateFriday(Request $request, Worker $worker): RedirectResponse
    {
        return $this->updateFlags($request, $worker);
    }

    public function updateFlags(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);

        $payload = [];
        if ($request->exists('unavailable')) {
            $payload['unavailable'] = $request->boolean('unavailable');
        }
        if ($request->exists('friday_off')) {
            abort_unless($worker->employment_type === EmploymentType::Eigen, 403);
            $payload['friday_off'] = $request->boolean('friday_off');
        }
        if ($payload !== []) {
            $worker->update($payload);
        }

        $worker->refresh();

        return back()->with('status', $this->flagsStatus($worker, $payload));
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function flagsStatus(Worker $worker, array $payload): string
    {
        if (array_key_exists('unavailable', $payload)) {
            return $worker->unavailable
                ? 'Staat nu helemaal niet beschikbaar.'
                : 'Staat weer beschikbaar.';
        }

        if (array_key_exists('friday_off', $payload)) {
            return $worker->friday_off
                ? 'Vrijdagen staan op vrij.'
                : 'Vrijdagen staan weer als werkdag.';
        }

        return 'Beschikbaarheid bijgewerkt.';
    }

    public function store(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'kind' => ['required', Rule::enum(AvailabilityKind::class)],
        ], [
            'start_date.required' => 'Kies een begindatum.',
            'end_date.required' => 'Kies een einddatum.',
            'end_date.after_or_equal' => 'De einddatum mag niet voor de begindatum liggen.',
            'kind.required' => 'Kies beschikbaar of niet beschikbaar.',
        ]);

        $worker->availabilities()->create($data);

        $kind = AvailabilityKind::from($data['kind']);

        return back()->with('status', $kind->label().' opgeslagen.');
    }

    public function destroy(Worker $worker, WorkerAvailability $availability): RedirectResponse
    {
        Gate::authorize('update', $worker);

        $availability->delete();

        return back()->with('status', 'Periode verwijderd.');
    }
}
