<?php

namespace Tests\Unit;

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
        $this->assertSame(
            "Begroot: 30,1u × €45 / 702m² = €1,93/m²\nWerkelijk: 38u × €45 / 702m² = €2,44/m²\nVerschil: +€0,51/m²",
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
        $this->assertSame('Begroot: 30,1u × €45 / 702m² = €1,93/m²', $item['unit_price_title']);
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
