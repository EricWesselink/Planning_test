<?php

namespace Tests\Unit;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Models\WorkProgressEntry;
use App\Services\ProjectLaborCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLaborCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_assignments_for_one_person_count_unique_hours(): void
    {
        [$project, $worker, $primer] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-21',
            end: '2026-09-21',
            startTime: '08:00:00',
            endTime: '14:00:00',
        );
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC stroken',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'in_uitvoering',
        ]);
        $second = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $pvc->id,
            'people_count' => 1,
        ]);
        $second->applySchedule(Carbon::parse('2026-09-21'), Carbon::parse('2026-09-21'), '12:00:00', '20:00:00');
        $second->save();

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $byItem = collect($labor['items'])->keyBy('id');

        $primerAssignment = WorkerAssignment::query()->where('work_item_id', $primer->id)->first();
        $this->assertSame(6.0, $primerAssignment->hoursOnDate(Carbon::parse('2026-09-21')));
        $this->assertSame(8.0, $second->fresh()->hoursOnDate(Carbon::parse('2026-09-21')));
        $this->assertSame(6.0, $byItem[$primer->id]['planned_hours']);
        $this->assertSame(6.0, $byItem[$pvc->id]['planned_hours']);
        $this->assertSame(12.0, $labor['planned_hours']);
    }

    public function test_two_people_for_three_days_cost_nine_euro_per_completed_square_meter(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 2,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 240,
            orderedM2: 1000,
            actualHours: 40,
        );

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(48.0, $labor['planned_hours']);
        $this->assertSame(40.0, $labor['actual_hours']);
        $this->assertSame(45.0, $labor['hourly_rate']);
        $this->assertSame(1800.0, $labor['labor_cost']);
        $this->assertSame(240.0, $labor['completed_m2']);
        $this->assertSame(7.5, $labor['cost_per_m2']);
        $this->assertSame('48u · €45/u · €1.800 arbeid · €7,50/m²', $labor['summary']);
        $this->assertSame('+8u', $labor['hours_delta_label']);
        $this->assertFalse($labor['hours_over']);
        $this->assertSame(
            'Uren: 48u | Tarief: €45 | Arbeid: €1.800 | Gereed: 240 m² | Werkelijk: 40u | €7,50/m²',
            $labor['detail'],
        );
    }

    public function test_two_and_a_half_days_count_as_twenty_hours(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '12:00:00',
        );

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(20.0, $labor['planned_hours']);
        $this->assertSame('20u · €45/u · €900 arbeid · —', $labor['summary']);
        $this->assertNull($labor['cost_per_m2']);
    }

    public function test_a_half_day_counts_as_four_hours(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '12:00:00',
        );

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(4.0, $labor['planned_hours']);
        $this->assertSame(180.0, $labor['labor_cost']);
    }

    public function test_zero_completed_square_meters_does_not_divide_by_zero(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
        );

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(8.0, $labor['planned_hours']);
        $this->assertSame(0.0, $labor['completed_m2']);
        $this->assertNull($labor['cost_per_m2']);
        $this->assertSame('8u · €45/u · €360 arbeid · —', $labor['summary']);
    }

    public function test_hides_the_summary_when_there_is_no_rate_and_no_hours(): void
    {
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200099',
            'customer_id' => $customer->id,
            'name' => 'Zonder inzet',
            'status' => 'gepland',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project);

        $this->assertNull($labor['summary']);
        $this->assertNull($labor['detail']);
        $this->assertSame(0.0, $labor['planned_hours']);
    }

    public function test_planned_hours_stay_on_the_scheduled_work_item(): void
    {
        [$project, $worker] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
        );
        $egaliseren = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 600,
            'begrote_uren' => 40,
            'status' => 'in_uitvoering',
        ]);
        $project->workItems()->where('name', 'Linoleum')->update(['begrote_uren' => 80]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $egaliseren->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-08'),
            Carbon::parse('2026-09-08'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $byName = collect($labor['items'])->keyBy('name');

        $this->assertSame(8.0, $byName['Linoleum']['planned_hours']);
        $this->assertSame(8.0, $byName['Primen & Egaliseren']['planned_hours']);
        $this->assertSame(16.0, $labor['planned_hours']);
        $this->assertSame(80.0, $byName['Linoleum']['budget_remaining']);
        $this->assertFalse($byName['Linoleum']['budget_remaining_over']);
        $this->assertSame('+8u', $byName['Linoleum']['hours_delta_label']);
        $this->assertSame('Begroot 80u | Ingepland 8u | Gemaakt 0u | Budget over 80u', $byName['Linoleum']['board_line']);
        $this->assertSame('Linoleum | Begroot 80u | Ingepland 8u | Gemaakt 0u | Budget over 80u', $byName['Linoleum']['compact']);
    }

    public function test_work_order_places_unassigned_hours_on_that_work_item(): void
    {
        [$project, $worker, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
        );
        $project->assignments()->update(['work_item_id' => null]);
        WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'assignment_type' => 'work_item',
            'unit' => 'm2',
            'status' => 'gepland',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $byName = collect($labor['items'])->keyBy('name');

        $this->assertSame(8.0, $byName['Linoleum']['planned_hours']);
        $this->assertSame('Ingepland 8u', $byName['Linoleum']['board_line']);
        $this->assertSame(8.0, $labor['planned_hours']);
    }

    public function test_warns_when_used_hours_reach_eighty_percent_of_budget(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
        );
        $project->workItems()->update(['begrote_uren' => 10]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(80, $item['used_percent']);
        $this->assertSame('warn', $item['tone']);
        $this->assertSame('uren bijna op', $item['warning']);
        $this->assertSame('Linoleum | Begroot 10u | Ingepland 8u | Gemaakt 0u | Budget over 10u', $item['compact']);
        $this->assertSame('8 / 10 uur', $item['bar_label']);
    }

    public function test_marks_overrun_when_used_hours_exceed_budget(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 600,
            orderedM2: 600,
            actualHours: 92,
        );
        $project->workItems()->update(['begrote_uren' => 80, 'begrote_hoeveelheid' => 600]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(92.0, $item['used_hours']);
        $this->assertSame(12.0, $item['overrun_hours']);
        $this->assertSame(115, $item['used_percent']);
        $this->assertSame('over', $item['tone']);
        $this->assertSame('Gemaakte uren liggen boven begroot', $item['warning']);
        $this->assertSame('Linoleum | Begroot 80u | Ingepland 24u | Gemaakt 92u | Budget over -12u', $item['compact']);
        $this->assertSame(3600.0, $item['budget_labor_cost']);
        $this->assertSame(6.0, $item['budget_unit_price']);
        $this->assertSame(4140.0, $item['actual_labor_cost']);
        $this->assertSame(6.9, $item['actual_unit_price']);
        $this->assertSame(0.9, $item['cost_delta']);
        $this->assertSame('Begroot €6,00/m² · Werkelijk €6,90/m² · +€0,90/m²', $item['finance']);
        $this->assertSame('over', $item['cost_delta_tone']);
        $this->assertSame('+€0,90/m²', $item['cost_delta_label']);
        $this->assertSame(6.0, $item['budget_unit_price']);
        $this->assertSame(6.9, $item['actual_unit_price']);
        $this->assertSame('⚠ Project: Gemaakte uren liggen boven begroot · Werkelijke arbeidsprijs per m² ligt boven begroot', $labor['overrun_label']);
        $this->assertSame('Begroot 80u | Ingepland 24u | Gemaakt 92u | Budget over -12u', $labor['budget_summary']);
        $this->assertSame(-12.0, $labor['budget_remaining']);
        $this->assertTrue($labor['budget_remaining_over']);
    }

    public function test_paid_extra_work_hours_are_kept_out_of_the_original_budget(): void
    {
        [$project, $worker, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 600,
            orderedM2: 600,
            actualHours: 80,
        );
        $item->update(['begrote_uren' => 80, 'begrote_hoeveelheid' => 600]);

        $extra = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'extra egaliseren',
            'unit' => 'uren',
            'ordered_quantity' => 16,
            'begrote_uren' => 16,
            'is_extra_work' => true,
            'planned_start_date' => '2026-09-10',
            'planned_end_date' => '2026-09-11',
            'status' => 'gepland',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $extra->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-11'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(80.0, $labor['budget_hours']);
        $this->assertSame(24.0, $labor['planned_hours']);
        $this->assertSame(80.0, $labor['actual_hours']);
        $this->assertSame(80.0, $labor['used_hours']);
        $this->assertSame(0.0, $labor['overrun_hours']);
        $this->assertSame(16.0, $labor['extra_planned_hours']);
        $this->assertSame(16.0, $labor['extra_budget_hours']);
        $this->assertSame('Extra werk 16u (niet in oorspronkelijke begroting)', $labor['extra_summary']);
        $this->assertSame('Begroot 80u | Ingepland 24u | Gemaakt 80u | Budget over 0u', $labor['budget_summary']);
    }

    public function test_service_work_uses_48_euro_even_without_a_stored_rate(): void
    {
        $customer = Customer::query()->create(['name' => 'Gemeente Deventer']);
        $project = Project::query()->create([
            'project_number' => '2026-001',
            'customer_id' => $customer->id,
            'name' => 'plint herstellen',
            'city' => 'Deventer',
            'kind' => ProjectKind::Service,
            'status' => 'gepland',
            'planned_start_date' => '2026-09-11',
            'planned_end_date' => '2026-09-11',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'plint herstellen',
            'unit' => 'uren',
            'ordered_quantity' => 4,
            'begrote_uren' => 4,
            'status' => 'gepland',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(48.0, $labor['hourly_rate']);
        $this->assertSame(192.0, $labor['budget_labor_cost']);
        $this->assertSame(48.0, $labor['items'][0]['hourly_rate']);
    }

    public function test_extra_work_uses_48_euro_when_the_project_rate_is_different(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
        );
        $extra = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'extra egaliseren',
            'unit' => 'uren',
            'ordered_quantity' => 4,
            'begrote_uren' => 4,
            'is_extra_work' => true,
            'status' => 'gepland',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(45.0, $labor['hourly_rate']);
        $this->assertSame(48.0, $labor['items_by_id'][$extra->id]['hourly_rate']);
        $this->assertSame(192.0, $labor['items_by_id'][$extra->id]['budget_labor_cost']);
    }

    public function test_hour_delta_is_planned_minus_actual_and_warns_on_overrun(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 240,
            actualHours: 14,
        );

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());

        $this->assertSame(8.0, $labor['planned_hours']);
        $this->assertSame(14.0, $labor['actual_hours']);
        $this->assertSame(-6.0, $labor['hours_delta']);
        $this->assertSame('-6u ⚠', $labor['hours_delta_label']);
        $this->assertTrue($labor['hours_over']);
    }

    public function test_work_item_hourly_rate_overrides_the_project_rate(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 80,
            orderedM2: 80,
            actualHours: 8,
        );
        $project->workItems()->update([
            'begrote_uren' => 8,
            'begrote_hoeveelheid' => 80,
            'uurtarief' => 50,
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(50.0, $item['hourly_rate']);
        $this->assertSame(45.0, $labor['hourly_rate']);
        $this->assertSame(400.0, $item['actual_labor_cost']);
        $this->assertSame(5.0, $item['actual_unit_price']);
        $this->assertSame(400.0, $item['budget_labor_cost']);
        $this->assertSame(5.0, $item['budget_unit_price']);
        $this->assertSame(0.0, $item['cost_delta']);
        $this->assertSame('none', $item['cost_delta_tone']);
    }

    public function test_keeps_budget_price_when_actual_hours_run_over(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 702,
            orderedM2: 702,
            actualHours: 38,
        );
        $project->workItems()->update([
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(1.93, $item['budget_unit_price']);
        $this->assertSame(2.44, $item['actual_unit_price']);
        $this->assertSame(0.51, $item['cost_delta']);
        $this->assertSame('over', $item['cost_delta_tone']);
        $this->assertSame('+€0,51/m²', $item['cost_delta_label']);
        $this->assertSame(38.0, $item['forecast_hours']);
        $this->assertSame(2.44, $item['forecast_unit_price']);
        $this->assertSame(
            "Begroot: 30,1u × €45 / 702m² = €1,93/m²\nPrognose: 38u × €45 / 702m² = €2,44/m²\nWerkelijk: 38u × €45 / 702m² = €2,44/m²\nVerschil: +€0,51/m²",
            $item['unit_price_title'],
        );
        $this->assertSame(1.93, $item['budget_cost_per_m2']);
        $this->assertSame(2.44, $labor['actual_cost_per_m2']);
        $this->assertNotSame($item['budget_unit_price'], $item['actual_unit_price']);
    }

    public function test_hides_actual_price_until_hours_and_completed_quantity_exist(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            orderedM2: 702,
            actualHours: 38,
        );
        $project->workItems()->update([
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(1.93, $item['budget_unit_price']);
        $this->assertNull($item['actual_unit_price']);
        $this->assertNull($item['cost_delta']);
        $this->assertSame('none', $item['cost_delta_tone']);
        $this->assertSame(38.0, $item['forecast_hours']);
        $this->assertSame(2.44, $item['forecast_unit_price']);
        $this->assertSame(0.51, $item['forecast_delta']);
        $this->assertSame('over', $item['forecast_delta_tone']);
        $this->assertSame('+26%', $item['forecast_over_percent_label']);
        $this->assertSame(
            "Begroot: 30,1u × €45 / 702m² = €1,93/m²\nPrognose: 38u × €45 / 702m² = €2,44/m²\nVerschil: +€0,51/m²",
            $item['unit_price_title'],
        );
    }

    public function test_prices_linear_meters_per_running_meter(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 40,
            orderedM2: 40,
            actualHours: 6,
        );
        $project->workItems()->update([
            'name' => 'Plinten',
            'unit' => 'm1',
            'begrote_uren' => 8,
            'begrote_hoeveelheid' => 40,
        ]);
        $project->workItems()->first()->progressEntries()->update(['unit' => 'm1']);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(9.0, $item['budget_unit_price']);
        $this->assertSame(6.75, $item['actual_unit_price']);
        $this->assertSame('ok', $item['cost_delta_tone']);
        $this->assertSame('-€2,25/m¹', $item['cost_delta_label']);
        $this->assertSame('m¹', $item['unit']);
        $this->assertSame(6.0, $item['forecast_hours']);
        $this->assertSame(6.75, $item['forecast_unit_price']);
        $this->assertSame('ok', $item['forecast_delta_tone']);
        $this->assertSame('-€2,25/m¹', $item['forecast_delta_label']);
    }

    public function test_forecasts_unit_price_from_expected_hours_and_ordered_quantity(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 2,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            orderedM2: 702,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 48])->save();
        $project->workItems()->update([
            'name' => 'Primen & Egaliseren',
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $item = $labor['items'][0];

        $this->assertSame(48.0, $item['planned_hours']);
        $this->assertSame(48.0, $item['forecast_hours']);
        $this->assertSame(2.06, $item['budget_unit_price']);
        $this->assertNull($item['actual_unit_price']);
        $this->assertSame(3.28, $item['forecast_unit_price']);
        $this->assertSame(1.22, $item['forecast_delta']);
        $this->assertSame('+€1,22/m²', $item['forecast_delta_label']);
        $this->assertSame('over', $item['forecast_delta_tone']);
        $this->assertSame(59, $item['forecast_over_percent']);
        $this->assertSame('+59%', $item['forecast_over_percent_label']);
    }

    public function test_forecast_hours_are_actual_plus_remaining_planned_while_work_is_open(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 2,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 200,
            orderedM2: 702,
            actualHours: 10,
        );
        $project->workItems()->update([
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ]);

        $item = app(ProjectLaborCalculator::class)->for($project->fresh())['items'][0];

        $this->assertSame(10.0, $item['actual_hours']);
        $this->assertSame(48.0, $item['planned_hours']);
        $this->assertSame(48.0, $item['forecast_hours']);
        $this->assertSame(3.28, $item['forecast_unit_price']);
    }

    public function test_forecast_hours_follow_actual_hours_when_they_already_exceed_the_plan(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 200,
            orderedM2: 702,
            actualHours: 12,
        );
        $project->workItems()->update([
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ]);

        $item = app(ProjectLaborCalculator::class)->for($project->fresh())['items'][0];

        $this->assertSame(8.0, $item['planned_hours']);
        $this->assertSame(12.0, $item['actual_hours']);
        $this->assertSame(12.0, $item['forecast_hours']);
        $this->assertSame(0.82, $item['forecast_unit_price']);
    }

    public function test_marks_a_modest_forecast_overrun_as_a_warning(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-10',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            orderedM2: 702,
            actualHours: 0,
        );
        $project->workItems()->update([
            'begrote_uren' => 30,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ]);

        $item = app(ProjectLaborCalculator::class)->for($project->fresh())['items'][0];

        $this->assertSame(32.0, $item['planned_hours']);
        $this->assertSame(2.05, $item['budget_unit_price']);
        $this->assertSame(2.19, $item['forecast_unit_price']);
        $this->assertSame('warn', $item['forecast_delta_tone']);
        $this->assertSame('+7%', $item['forecast_over_percent_label']);
    }

    public function test_hides_the_forecast_price_without_a_rate_or_ordered_quantity(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
        );
        $project->forceFill(['basis_uurtarief' => null])->save();
        $project->workItems()->update([
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => null,
        ]);

        $item = app(ProjectLaborCalculator::class)->for($project->fresh())['items'][0];

        $this->assertNull($item['hourly_rate']);
        $this->assertNull($item['budget_unit_price']);
        $this->assertNull($item['forecast_unit_price']);
        $this->assertNull($item['forecast_delta_label']);
        $this->assertSame('none', $item['forecast_delta_tone']);
    }

    public function test_forecasts_linear_meters_from_expected_hours_and_ordered_quantity(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-08',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            orderedM2: 40,
            actualHours: 0,
        );
        $project->workItems()->update([
            'name' => 'Plinten',
            'unit' => 'm1',
            'begrote_uren' => 8,
            'begrote_hoeveelheid' => 40,
        ]);

        $item = app(ProjectLaborCalculator::class)->for($project->fresh())['items'][0];

        $this->assertSame(16.0, $item['forecast_hours']);
        $this->assertSame(9.0, $item['budget_unit_price']);
        $this->assertSame(18.0, $item['forecast_unit_price']);
        $this->assertSame('+€9,00/m¹', $item['forecast_delta_label']);
        $this->assertSame('over', $item['forecast_delta_tone']);
        $this->assertSame('+100%', $item['forecast_over_percent_label']);
    }

    public function test_group_labor_price_uses_calculated_quantity_not_sibling_order_quantity(): void
    {
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            orderedM2: 1314.75,
        );
        $project->workItems()->update([
            'name' => 'Marmorette, Linoleum',
            'begrote_uren' => 246.76,
            'begrote_hoeveelheid' => 1409.77,
            'uurtarief' => 48,
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Lino Art, Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 871.71,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 2484.21,
            'begrote_uren' => 112.5,
            'uurtarief' => 48,
            'status' => 'gepland',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh());
        $linoleum = $labor['groups']['linoleum|m2'] ?? null;
        $priming = collect($labor['items'])->first(
            fn (array $item): bool => $item['name'] === 'Primen & Egaliseren'
        );

        $this->assertNotNull($linoleum);
        $this->assertEqualsWithDelta(246.76, $linoleum['budget_hours'], 0.01);
        $this->assertEqualsWithDelta(11844.48, $linoleum['budget_labor_cost'], 0.02);
        $this->assertEqualsWithDelta(8.40, $linoleum['budget_unit_price'], 0.001);
        $this->assertNotNull($priming);
        $this->assertNull($priming['budget_unit_price']);
    }

    /**
     * @return array{0: Project, 1: Worker, 2: WorkItem}
     */
    private function makeScheduledProject(
        int $people,
        string $start,
        string $end,
        string $startTime,
        string $endTime,
        float $completedM2 = 0,
        float $orderedM2 = 500,
        float $actualHours = 0,
    ): array {
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'basis_uurtarief' => 45,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => $orderedM2,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'status' => 'in_uitvoering',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => $people,
        ]);
        $assignment->applySchedule(
            Carbon::parse($start),
            Carbon::parse($end),
            $startTime,
            $endTime,
        );
        $assignment->save();

        if ($completedM2 > 0 || $actualHours > 0) {
            WorkProgressEntry::query()->create([
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'date' => $start,
                'completed_quantity' => $completedM2,
                'unit' => 'm2',
                'worked_hours' => $actualHours,
            ]);
        }

        return [$project, $worker, $item];
    }
}
