<?php

namespace Tests\Unit;

use App\Support\PlanningHours;
use Carbon\Carbon;
use Tests\TestCase;

class PlanningHoursTest extends TestCase
{
    public function test_snaps_hours_to_two_hour_steps(): void
    {
        $this->assertSame(2, PlanningHours::snapHours(1));
        $this->assertSame(4, PlanningHours::snapHours(4));
        $this->assertSame(6, PlanningHours::snapHours(5));
        $this->assertSame(8, PlanningHours::snapHours(9));
    }

    public function test_half_day_morning_is_four_hours_from_eight_to_noon(): void
    {
        [$start, $end, $hours] = PlanningHours::timesFromHours(4, 'morning');

        $this->assertSame('08:00:00', $start);
        $this->assertSame('12:00:00', $end);
        $this->assertSame(4, $hours);
    }

    public function test_half_day_afternoon_is_four_hours_from_noon_to_four(): void
    {
        [$start, $end, $hours] = PlanningHours::timesFromHours(4, 'afternoon');

        $this->assertSame('12:00:00', $start);
        $this->assertSame('16:00:00', $end);
        $this->assertSame(4, $hours);
    }

    public function test_full_day_is_eight_hours(): void
    {
        [$start, $end, $hours] = PlanningHours::timesFromHours(8);

        $this->assertSame('08:00:00', $start);
        $this->assertSame('16:00:00', $end);
        $this->assertSame(8, $hours);
    }

    public function test_monday_afternoon_plus_tuesday_full_day_totals_twelve_hours(): void
    {
        $total = PlanningHours::totalHours(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
            '12:00:00',
            '16:00:00',
        );

        $this->assertSame(12.0, $total);
        $this->assertSame(4.0, PlanningHours::hoursOnDate(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
            '12:00:00',
            '16:00:00',
        ));
        $this->assertSame(8.0, PlanningHours::hoursOnDate(
            Carbon::parse('2026-09-08'),
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
            '12:00:00',
            '16:00:00',
        ));
    }

    public function test_adjacent_intervals_do_not_overlap(): void
    {
        $morningStart = Carbon::parse('2026-09-07 08:00:00');
        $morningEnd = Carbon::parse('2026-09-07 12:00:00');
        $afternoonStart = Carbon::parse('2026-09-07 12:00:00');
        $afternoonEnd = Carbon::parse('2026-09-07 16:00:00');

        $this->assertFalse(PlanningHours::intervalsOverlap($morningStart, $morningEnd, $afternoonStart, $afternoonEnd));
        $this->assertTrue(PlanningHours::intervalsOverlap(
            $morningStart,
            Carbon::parse('2026-09-07 16:00:00'),
            $afternoonStart,
            $afternoonEnd,
        ));
    }

    public function test_hours_label_uses_compact_suffix(): void
    {
        $this->assertSame('4u', PlanningHours::hoursLabel(4));
        $this->assertSame('8u', PlanningHours::hoursLabel(8.0));
    }

    public function test_overlapping_intervals_count_unique_hours_once(): void
    {
        $hours = PlanningHours::uniqueHours([
            [Carbon::parse('2026-09-07 08:00:00'), Carbon::parse('2026-09-07 16:00:00')],
            [Carbon::parse('2026-09-07 08:00:00'), Carbon::parse('2026-09-07 12:00:00')],
        ]);

        $this->assertSame(8.0, $hours);
    }

    public function test_adjacent_morning_and_afternoon_make_one_man_day(): void
    {
        $hours = PlanningHours::uniqueHours([
            [Carbon::parse('2026-09-07 08:00:00'), Carbon::parse('2026-09-07 12:00:00')],
            [Carbon::parse('2026-09-07 12:00:00'), Carbon::parse('2026-09-07 16:00:00')],
        ]);

        $this->assertSame(8.0, $hours);
        $this->assertSame(1.0, PlanningHours::manDaysFromHours($hours));
    }

    public function test_four_hours_is_half_a_man_day(): void
    {
        $this->assertSame(0.5, PlanningHours::manDaysFromHours(4));
        $this->assertSame('0,5', PlanningHours::manDaysLabel(0.5));
    }
}
