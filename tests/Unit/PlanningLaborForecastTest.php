<?php

namespace Tests\Unit;

use App\Support\PlanningLaborForecast;
use Tests\TestCase;

class PlanningLaborForecastTest extends TestCase
{
    public function test_warns_when_planned_hours_exceed_budget_before_any_work_is_done(): void
    {
        $forecast = PlanningLaborForecast::forBoard(15, 18, 0, 15);

        $this->assertSame('over', $forecast['planned_tone']);
        $this->assertSame('+3u', $forecast['planned_overrun_label']);
        $this->assertSame("Begroot: 15u\nGepland: 18u\nOverschrijding: +3u", $forecast['planned_title']);
        $this->assertSame('15u', $forecast['rest_label']);
        $this->assertSame('none', $forecast['rest_tone']);
    }

    public function test_keeps_remaining_based_on_actual_hours_when_overplanned(): void
    {
        $forecast = PlanningLaborForecast::forBoard(15, 18, 10, 5);

        $this->assertSame(8.0, $forecast['still_planned']);
        $this->assertSame('+3u', $forecast['planned_overrun_label']);
        $this->assertSame("Begroot: 15u\nGepland: 18u\nOverschrijding: +3u", $forecast['planned_title']);
        $this->assertSame('5u', $forecast['rest_label']);
        $this->assertSame('none', $forecast['rest_tone']);
    }

    public function test_marks_planned_hours_orange_when_eighty_percent_of_budget_is_scheduled(): void
    {
        $forecast = PlanningLaborForecast::forBoard(15, 12, 0, 15);

        $this->assertSame('warn', $forecast['planned_tone']);
        $this->assertNull($forecast['planned_overrun_label']);
        $this->assertSame('Begroot 15u · Gemaakt 0u · Nog gepland 12u', $forecast['planned_title']);
        $this->assertSame('15u', $forecast['rest_label']);
    }

    public function test_labels_rest_as_exceeded_when_actual_hours_pass_budget(): void
    {
        $forecast = PlanningLaborForecast::forBoard(15, 8, 16, -1);

        $this->assertSame('none', $forecast['planned_tone']);
        $this->assertNull($forecast['planned_overrun_label']);
        $this->assertSame('Overschreden +1u', $forecast['rest_label']);
        $this->assertSame('over', $forecast['rest_tone']);
    }

    public function test_does_not_warn_without_a_budget(): void
    {
        $forecast = PlanningLaborForecast::forBoard(0, 18, 0, null);

        $this->assertSame('none', $forecast['planned_tone']);
        $this->assertNull($forecast['planned_overrun_label']);
        $this->assertNull($forecast['planned_title']);
        $this->assertSame('—', $forecast['rest_label']);
    }

    public function test_splits_a_single_bar_proportionally_when_planned_hours_exceed_budget(): void
    {
        $splits = PlanningLaborForecast::splitByBudget(30.1, [
            ['id' => 1, 'hours' => 40, 'start_date' => '2026-09-07', 'start_time' => '08:00'],
        ]);

        $this->assertSame(30.1, $splits[1]['within_hours']);
        $this->assertSame(9.9, $splits[1]['over_hours']);
        $this->assertSame(75.25, $splits[1]['ok_percent']);
        $this->assertSame(24.75, $splits[1]['over_percent']);
    }

    public function test_turns_only_the_chronological_overflow_red_across_multiple_bars(): void
    {
        $splits = PlanningLaborForecast::splitByBudget(30.1, [
            ['id' => 2, 'hours' => 24, 'start_date' => '2026-09-09', 'start_time' => '08:00'],
            ['id' => 1, 'hours' => 16, 'start_date' => '2026-09-07', 'start_time' => '08:00'],
        ]);

        $this->assertSame(16.0, $splits[1]['within_hours']);
        $this->assertSame(0.0, $splits[1]['over_hours']);
        $this->assertSame(100.0, $splits[1]['ok_percent']);
        $this->assertSame(14.1, $splits[2]['within_hours']);
        $this->assertSame(9.9, $splits[2]['over_hours']);
        $this->assertSame(58.75, $splits[2]['ok_percent']);
        $this->assertSame(41.25, $splits[2]['over_percent']);
    }

    public function test_keeps_bars_fully_within_budget_when_no_hours_are_budgeted(): void
    {
        $splits = PlanningLaborForecast::splitByBudget(0, [
            ['id' => 1, 'hours' => 40, 'start_date' => '2026-09-07', 'start_time' => '08:00'],
        ]);

        $this->assertSame(40.0, $splits[1]['within_hours']);
        $this->assertSame(0.0, $splits[1]['over_hours']);
        $this->assertSame(100.0, $splits[1]['ok_percent']);
    }
}
