<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\PlanningAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningWeekAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_dialog_can_choose_exact_dates_or_week_numbers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('Wanneer')
            ->assertSee('Exacte datum')
            ->assertSee('Weeknummer(s)')
            ->assertSee('Van week')
            ->assertSee('Tot week')
            ->assertSee('id="plan-start"', false)
            ->assertSee('id="plan-end"', false)
            ->assertSee('data-week-year="2026"', false);
    }

    public function test_stores_week_forty_through_forty_four_as_a_provisional_period_without_hours(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 40,
                'end_week' => 44,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->isProvisional());
        $this->assertSame('2026-09-28', $assignment->start_date->toDateString());
        $this->assertSame('2026-10-30', $assignment->end_date->toDateString());
        $this->assertSame(0.0, (float) $assignment->hours_per_day);
        $this->assertSame(0.0, $assignment->plannedHoursValue());
        $this->assertNull($assignment->intervalOnDate(Carbon::parse('2026-09-28')));
        $this->assertSame(0.0, $assignment->hoursOnDate(Carbon::parse('2026-09-28')));
        $this->assertTrue($assignment->coversDate(Carbon::parse('2026-09-28')));
        $this->assertFalse($assignment->coversDate(Carbon::parse('2026-10-03')));
    }

    public function test_stores_a_single_week_as_monday_through_friday(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 42,
                'end_week' => 42,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->first();
        $this->assertSame('2026-10-12', $assignment->start_date->toDateString());
        $this->assertSame('2026-10-16', $assignment->end_date->toDateString());
        $this->assertTrue($assignment->isProvisional());
        $this->assertSame(0.0, $assignment->plannedHoursValue());
    }

    public function test_week_period_does_not_fill_man_days(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 37,
                'end_week' => 37,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk();

        $days = collect([
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
            Carbon::parse('2026-09-09'),
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-11'),
        ]);
        $remaining = app(PlanningAvailabilityService::class)->remainingManDays($days);

        $this->assertSame(5.0, $remaining);
    }

    public function test_exact_dates_still_plan_eight_hours_a_day(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'dates',
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-11',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->first();
        $this->assertFalse($assignment->isProvisional());
        $this->assertSame(40.0, $assignment->plannedHoursValue());
        $this->assertSame(8.0, $assignment->hoursOnDate(Carbon::parse('2026-09-07')));
    }

    public function test_updates_a_week_period_and_can_switch_to_exact_hours(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 40,
                'end_week' => 40,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk();
        $assignment = WorkerAssignment::query()->first();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'when' => 'dates',
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-28',
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertFalse($assignment->isProvisional());
        $this->assertSame(8.0, $assignment->plannedHoursValue());
        $this->assertSame('2026-09-28', $assignment->start_date->toDateString());
    }

    public function test_deletes_a_week_period(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 40,
                'end_week' => 40,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk();
        $assignment = WorkerAssignment::query()->first();

        $this->actingAs($user)
            ->deleteJson(route('planning.assignments.destroy', $assignment))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('worker_assignments', ['id' => $assignment->id]);
    }

    public function test_rejects_a_tot_week_before_van_week(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 44,
                'end_week' => 40,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tot week moet op of na Van week liggen.');

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_board_renders_a_provisional_week_bar(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'when' => 'weeks',
                'start_week' => 37,
                'end_week' => 37,
                'year' => 2026,
                'people_count' => 1,
            ])
            ->assertOk();
        $assignment = WorkerAssignment::query()->first();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('data-provisional="1"', false)
            ->assertSee('person-bar--provisional', false)
            ->assertSee('voorlopig', false)
            ->assertSee('data-shift-id="'.$assignment->id.'"', false);
    }

    /**
     * @return array{0: Worker, 1: Project, 2: WorkItem}
     */
    private function makeProject(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => 1,
            'specialty' => 'Linoleum, PVC, Tapijt, Coating',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        return [$worker, $project, $linoleum];
    }
}
