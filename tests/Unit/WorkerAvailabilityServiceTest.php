<?php

namespace Tests\Unit;

use App\Enums\AvailabilityKind;
use App\Models\Worker;
use App\Services\WorkerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_staff_with_friday_off_are_away_on_friday(): void
    {
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'friday_off' => true,
            'active' => true,
        ]);

        $service = app(WorkerAvailabilityService::class);

        $this->assertSame('Vrij op vrijdag', $service->awayLabelOn($peter, Carbon::parse('2026-09-11')));
        $this->assertNull($service->awayLabelOn($peter, Carbon::parse('2026-09-10')));
        $this->assertSame('Peter is vrij op vrijdag.', $service->rejection(
            $peter,
            Carbon::parse('2026-09-11'),
            Carbon::parse('2026-09-11'),
        ));
    }

    public function test_own_staff_with_a_fixed_weekday_off_are_away_that_day(): void
    {
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $member = $peter->crewPeople->first();
        $member->setRelation('worker', $peter);
        $member->setWorkDay(3, false);
        $member->save();
        $peter->unsetRelation('crewPeople');

        $service = app(WorkerAvailabilityService::class);

        $this->assertSame('Vrije dag', $service->awayLabelOn($peter, Carbon::parse('2026-09-09')));
        $this->assertNull($service->awayLabelOn($peter, Carbon::parse('2026-09-08')));
        $this->assertNull($service->awayLabelOn($peter, Carbon::parse('2026-09-11')));
    }

    public function test_own_staff_with_friday_off_and_weekday_leave_are_away_all_week(): void
    {
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'friday_off' => true,
            'active' => true,
        ]);
        $peter->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-10',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $peter->load('availabilities');

        $service = app(WorkerAvailabilityService::class);

        $this->assertSame('Niet beschikbaar', $service->awayLabelOn($peter, Carbon::parse('2026-09-07')));
        $this->assertSame('Niet beschikbaar', $service->awayLabelOn($peter, Carbon::parse('2026-09-10')));
        $this->assertSame('Vrij op vrijdag', $service->awayLabelOn($peter, Carbon::parse('2026-09-11')));
        $this->assertSame('Peter is niet beschikbaar.', $service->rejection(
            $peter,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-11'),
        ));
    }

    public function test_partial_leave_is_not_fully_away_and_leaves_the_afternoon_free(): void
    {
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $peter->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'kind' => AvailabilityKind::Leave,
            'hours' => 4,
            'slot' => 'morning',
        ]);
        $peter->load(['availabilities', 'crewPeople']);

        $service = app(WorkerAvailabilityService::class);
        $monday = Carbon::parse('2026-09-07');

        $this->assertFalse($service->isAwayOn($peter, $monday));
        $this->assertSame('Verlof', $service->awayLabelOn($peter, $monday));
        $this->assertSame(4.0, $service->absenceOn($peter, $monday)['hours']);
        $this->assertSame('Verlof', $service->awayLabelInRange(
            $peter,
            $monday,
            $monday,
            false,
            false,
            '08:00',
            '16:00',
        ));
        $this->assertNull($service->awayLabelInRange(
            $peter,
            $monday,
            $monday,
            false,
            false,
            '12:00',
            '16:00',
        ));
    }

    public function test_zzp_unavailable_period_marks_those_days_away(): void
    {
        $nick = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-09',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $nick->load('availabilities');

        $service = app(WorkerAvailabilityService::class);

        $this->assertTrue($service->isAwayOn($nick, Carbon::parse('2026-09-08')));
        $this->assertFalse($service->isAwayOn($nick, Carbon::parse('2026-09-10')));
        $this->assertSame(['Niet 7 sep. – 9 sep.'], $service->summaryLines($nick));
    }

    public function test_friday_off_does_not_apply_to_zzp(): void
    {
        $nick = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'friday_off' => true,
            'active' => true,
        ]);

        $this->assertNull(app(WorkerAvailabilityService::class)->awayLabelOn(
            $nick,
            Carbon::parse('2026-09-11'),
        ));
    }

    public function test_a_fully_unavailable_team_is_away_every_day(): void
    {
        $nick = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'unavailable' => true,
            'active' => true,
        ]);

        $service = app(WorkerAvailabilityService::class);

        $this->assertSame('Niet beschikbaar', $service->awayLabelOn($nick, Carbon::parse('2026-09-07')));
        $this->assertSame('Niet beschikbaar', $service->awayLabelOn($nick, Carbon::parse('2026-09-11')));
        $this->assertSame(['Niet beschikbaar'], $service->summaryLines($nick));
        $this->assertSame('Nick Seine is niet beschikbaar.', $service->rejection(
            $nick,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
        ));
    }

    public function test_weekend_unavailability_does_not_block_a_weekday_span(): void
    {
        $nick = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $nick->availabilities()->create([
            'start_date' => '2026-09-19',
            'end_date' => '2026-09-19',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $nick->load('availabilities');

        $service = app(WorkerAvailabilityService::class);

        $this->assertNull($service->rejection(
            $nick,
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-25'),
        ));
        $this->assertSame('Nick Seine is niet beschikbaar.', $service->rejection(
            $nick,
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-25'),
            true,
            false,
        ));
        $this->assertNull($service->rejection(
            $nick,
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-25'),
            false,
            true,
        ));
    }
}
