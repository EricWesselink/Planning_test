<?php

namespace App\Http\Controllers;

use App\Enums\AvailabilityKind;
use App\Enums\AvailabilitySlot;
use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Support\PlanningHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkerAvailabilityController extends Controller
{
    public function updateFriday(Request $request, Worker $worker): RedirectResponse
    {
        return $this->updateFlags($request, $worker);
    }

    public function updateFlags(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless(
            $request->user()?->canManageWorkers() || $request->user()?->canAdjustAbsence(),
            403
        );

        $payload = [];
        if ($request->exists('unavailable')) {
            $payload['unavailable'] = $request->boolean('unavailable');
        }
        if ($request->exists('friday_off')) {
            abort_unless($worker->employment_type === EmploymentType::Eigen, 403);
            $payload['friday_off'] = $request->boolean('friday_off');
        }

        $member = $this->requestedMember($request, $worker);
        if ($payload !== []) {
            if ($member instanceof CrewMember) {
                $this->updateMemberFlags($worker, $member, $payload);
            } else {
                $worker->update($payload);
                $this->syncCrewFlags($worker, $payload);
            }
        }

        $worker->refresh();

        return back()->with('status', $this->flagsStatus($member instanceof CrewMember ? $member : $worker, $payload));
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function flagsStatus(Worker|CrewMember $subject, array $payload): string
    {
        if (array_key_exists('unavailable', $payload)) {
            return $subject->unavailable
                ? 'Staat nu helemaal niet beschikbaar.'
                : 'Staat weer beschikbaar.';
        }

        if (array_key_exists('friday_off', $payload)) {
            return $subject->friday_off
                ? 'Vrijdagen staan op vrij.'
                : 'Vrijdagen staan weer als werkdag.';
        }

        return 'Beschikbaarheid bijgewerkt.';
    }

    public function store(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless(
            $request->user()?->canManageWorkers() || $request->user()?->canAdjustAbsence(),
            403
        );

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'kind' => ['required', Rule::enum(AvailabilityKind::class)],
            'slot' => ['nullable', Rule::enum(AvailabilitySlot::class)],
            'hours' => ['nullable', 'numeric', 'min:1', 'max:'.PlanningHours::WORKDAY_HOURS],
            'crew_member_id' => ['nullable', 'integer'],
        ], [
            'start_date.required' => 'Kies een begindatum.',
            'end_date.required' => 'Kies een einddatum.',
            'end_date.after_or_equal' => 'De einddatum mag niet voor de begindatum liggen.',
            'kind.required' => 'Kies een reden.',
        ]);

        $member = $this->requestedMember($request, $worker);
        $slot = AvailabilitySlot::tryFrom((string) ($data['slot'] ?? '')) ?? AvailabilitySlot::Full;
        $hours = $slot === AvailabilitySlot::Hours
            ? (float) ($data['hours'] ?? 4)
            : $slot->defaultHours();
        if ($hours >= PlanningHours::WORKDAY_HOURS - 0.01) {
            $slot = AvailabilitySlot::Full;
            $hours = (float) PlanningHours::WORKDAY_HOURS;
        }
        $worker->availabilities()->create([
            'crew_member_id' => $member?->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'kind' => $data['kind'],
            'slot' => $slot,
            'hours' => $hours,
        ]);

        $kind = AvailabilityKind::from($data['kind']);

        return back()->with('status', $kind->label().' opgeslagen.');
    }

    public function destroy(Worker $worker, WorkerAvailability $availability): RedirectResponse
    {
        abort_unless(
            $request->user()?->canManageWorkers() || $request->user()?->canAdjustAbsence(),
            403
        );

        $availability->delete();

        return back()->with('status', 'Periode verwijderd.');
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function updateMemberFlags(Worker $worker, CrewMember $member, array $payload): void
    {
        $worker->loadMissing('crewPeople');
        foreach ($payload as $flag => $value) {
            if ($worker->{$flag}) {
                foreach ($worker->crewPeople as $person) {
                    if ((int) $person->id !== (int) $member->id) {
                        if ($flag === 'friday_off') {
                            $person->setRelation('worker', $worker);
                            $person->setWorkDay(5, ! $value);
                            $person->save();
                        } else {
                            $person->update([$flag => true]);
                        }
                    }
                }
                $worker->update([$flag => false]);
            }
            if ($flag === 'friday_off') {
                $member->setRelation('worker', $worker);
                $member->setWorkDay(5, ! $value);
                $member->save();
            } else {
                $member->update([$flag => $value]);
            }
        }

        if ($worker->crewPeople->count() <= 1) {
            $worker->update($payload);
        }
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function syncCrewFlags(Worker $worker, array $payload): void
    {
        $worker->loadMissing('crewPeople');
        $flags = $payload;
        if (array_key_exists('friday_off', $flags)) {
            $fridayOff = $flags['friday_off'];
            unset($flags['friday_off']);
            foreach ($worker->crewPeople as $person) {
                $person->setRelation('worker', $worker);
                $person->setWorkDay(5, ! $fridayOff);
                $person->save();
            }
        }

        if ($flags !== []) {
            $worker->crewPeople()->update($flags);
        }
    }

    private function requestedMember(Request $request, Worker $worker): ?CrewMember
    {
        $id = $request->integer('crew_member_id');
        if ($id < 1) {
            return null;
        }

        $member = $worker->crewPeople()->whereKey($id)->first();
        abort_unless($member instanceof CrewMember, 404);

        return $member;
    }
}
