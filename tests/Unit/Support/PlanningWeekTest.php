<?php

namespace Tests\Unit\Support;

use App\Support\PlanningWeek;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PlanningWeekTest extends TestCase
{
    public function test_monday_of_iso_week_forty_in_2026_is_28_september(): void
    {
        $monday = PlanningWeek::monday(2026, 40);

        $this->assertNotNull($monday);
        $this->assertSame('2026-09-28', $monday->toDateString());
        $this->assertTrue($monday->isMonday());
    }

    public function test_saturday_of_iso_week_forty_four_in_2026_is_31_october(): void
    {
        $saturday = PlanningWeek::saturday(2026, 44);

        $this->assertNotNull($saturday);
        $this->assertSame('2026-10-31', $saturday->toDateString());
        $this->assertTrue($saturday->isSaturday());
    }

    public function test_from_input_converts_start_and_klaar_weeks_to_dates(): void
    {
        $dates = PlanningWeek::fromInput([
            'start_year' => '2026',
            'start_week' => '40',
            'klaar_year' => '2026',
            'klaar_week' => '44',
        ]);

        $this->assertSame('2026-09-28', $dates['start']);
        $this->assertSame('2026-10-31', $dates['end']);
    }

    public function test_empty_input_leaves_planning_dates_empty(): void
    {
        $dates = PlanningWeek::fromInput([
            'start_year' => '',
            'start_week' => '',
            'klaar_year' => '',
            'klaar_week' => '',
        ]);

        $this->assertNull($dates['start']);
        $this->assertNull($dates['end']);
    }

    public function test_apply_to_ignores_an_empty_fallback_start(): void
    {
        $data = PlanningWeek::applyTo([], '');

        $this->assertNull($data['planned_start_date']);
        $this->assertNull($data['planned_end_date']);
    }

    public function test_apply_to_keeps_fallback_start_when_weeks_are_empty(): void
    {
        $data = PlanningWeek::applyTo([], '2026-09-07');

        $this->assertSame('2026-09-07', $data['planned_start_date']);
        $this->assertNull($data['planned_end_date']);
    }

    public function test_apply_to_prefers_filled_weeks_over_fallback_date(): void
    {
        $data = PlanningWeek::applyTo([
            'start_year' => 2026,
            'start_week' => 40,
            'klaar_year' => 2026,
            'klaar_week' => 44,
        ], '2026-09-07');

        $this->assertSame('2026-09-28', $data['planned_start_date']);
        $this->assertSame('2026-10-31', $data['planned_end_date']);
    }

    public function test_from_input_uses_dates_when_weeks_are_empty(): void
    {
        $dates = PlanningWeek::fromInput([
            'start_date' => '2026-10-14',
            'klaar_date' => '2026-11-03',
        ]);

        $this->assertSame('2026-10-14', $dates['start']);
        $this->assertSame('2026-11-03', $dates['end']);
    }

    public function test_week_number_without_year_uses_the_current_iso_year(): void
    {
        $this->travelTo('2026-09-07 09:00:00');

        $dates = PlanningWeek::fromInput([
            'start_week' => 40,
            'klaar_week' => 44,
        ]);

        $this->assertSame('2026-09-28', $dates['start']);
        $this->assertSame('2026-10-31', $dates['end']);
    }

    public function test_resolve_keeps_an_exact_date_when_week_fields_are_unchanged(): void
    {
        $currentStart = Carbon::parse('2026-10-14');
        $currentEnd = Carbon::parse('2026-11-03');

        $dates = PlanningWeek::resolve([
            'start_year' => 2026,
            'start_week' => 42,
            'start_date' => '2026-10-14',
            'klaar_year' => 2026,
            'klaar_week' => 45,
            'klaar_date' => '2026-11-03',
        ], $currentStart, $currentEnd);

        $this->assertSame('2026-10-14', $dates['start']);
        $this->assertSame('2026-11-03', $dates['end']);
    }

    public function test_resolve_uses_a_changed_date_even_when_weeks_are_still_the_old_week(): void
    {
        $dates = PlanningWeek::resolve([
            'start_year' => 2026,
            'start_week' => 40,
            'start_date' => '2026-10-14',
            'klaar_year' => 2026,
            'klaar_week' => 44,
            'klaar_date' => '2026-11-03',
        ], Carbon::parse('2026-09-28'), Carbon::parse('2026-10-31'));

        $this->assertSame('2026-10-14', $dates['start']);
        $this->assertSame('2026-11-03', $dates['end']);
    }

    public function test_resolve_uses_changed_weeks_over_the_previous_dates(): void
    {
        $dates = PlanningWeek::resolve([
            'start_year' => 2026,
            'start_week' => 42,
            'start_date' => '2026-09-28',
            'klaar_year' => 2026,
            'klaar_week' => 46,
            'klaar_date' => '2026-10-31',
        ], Carbon::parse('2026-09-28'), Carbon::parse('2026-10-31'));

        $this->assertSame('2026-10-12', $dates['start']);
        $this->assertSame('2026-11-14', $dates['end']);
    }

    public function test_rejects_a_klaar_date_before_the_start_date(): void
    {
        $validator = Validator::make([
            'start_date' => '2026-11-03',
            'klaar_date' => '2026-10-14',
        ], PlanningWeek::rules(), PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['Klaar werk moet op dezelfde dag of later vallen dan start werk.'],
            $validator->errors()->get('klaar_date')
        );
    }

    public function test_rejects_a_klaar_week_before_the_start_week(): void
    {
        $validator = Validator::make([
            'start_year' => 2026,
            'start_week' => 44,
            'klaar_year' => 2026,
            'klaar_week' => 40,
        ], PlanningWeek::rules(), PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['Klaar werk moet op dezelfde dag of later vallen dan start werk.'],
            $validator->errors()->get('klaar_week')
        );
    }

    public function test_rejects_week_53_in_a_year_with_52_weeks(): void
    {
        $this->assertFalse(PlanningWeek::weekExists(2025, 53));

        $validator = Validator::make([
            'start_year' => 2025,
            'start_week' => 53,
        ], PlanningWeek::rules(), PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['Dit weeknummer bestaat niet in 2025.'],
            $validator->errors()->get('start_week')
        );
    }
}
