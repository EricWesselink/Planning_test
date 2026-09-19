<?php

namespace Tests\Feature;

use App\Enums\CalculationStatus;
use App\Enums\UserRole;
use App\Enums\WorkTicketKind;
use App\Models\Calculation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReadOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_user_can_open_office_overviews(): void
    {
        $user = User::factory()->alleenLezen()->create();
        $project = $this->makeProject();
        $calculation = $this->makeCalculation($user);
        $ticket = $this->makeWorkTicket($project);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Alleen lezen');
        $this->actingAs($user)->get(route('projects.index'))->assertOk();
        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->actingAs($user)->get(route('calculations.index'))->assertOk();
        $this->actingAs($user)->get(route('calculations.show', $calculation))->assertOk();
        $this->actingAs($user)->get(route('workers.index'))->assertOk();
        $this->actingAs($user)->get(route('work-tickets.show', $ticket))->assertOk();
        $this->actingAs($user)->get(route('projects.pdf'))->assertOk();
    }

    public function test_read_only_user_cannot_mutate_records_via_direct_requests(): void
    {
        $user = User::factory()->alleenLezen()->create();
        $project = $this->makeProject(['name' => 'Kindcentrum']);
        $calculation = $this->makeCalculation($user);
        $ticket = $this->makeWorkTicket($project);
        $worker = Worker::query()->create(['name' => 'Albert', 'employment_type' => 'eigen', 'active' => true]);
        $target = User::factory()->create(['name' => 'Planner Piet']);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['city' => 'Utrecht'])
            ->assertForbidden();
        $this->assertSame('Amersfoort', $project->fresh()->city);

        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertForbidden();
        $this->assertModelExists($project);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-01',
                'people_count' => 1,
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('worker_assignments', [
            'project_id' => $project->id,
            'start_date' => '2026-12-01',
        ]);

        $this->actingAs($user)
            ->post(route('projects.plattegrond.store', $project), [
                'plattegrond' => UploadedFile::fake()->image('plan.jpg', 40, 30),
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), ['name' => 'Gewijzigd', 'dated_on' => '2026-03-06'])
            ->assertForbidden();
        $this->assertSame('Offerte Wepro', $calculation->fresh()->name);

        $this->actingAs($user)
            ->patch(route('work-tickets.hours.update', $ticket), ['worked_hours' => 8])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), ['name' => 'Gewijzigd'])
            ->assertForbidden();
        $this->assertSame('Albert', $worker->fresh()->name);

        $this->actingAs($user)
            ->patch(route('users.update', $target), [
                'name' => 'Hacker',
                'email' => $target->email,
                'role' => UserRole::Admin->value,
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertForbidden();
        $this->assertSame('Planner Piet', $target->fresh()->name);
        $this->assertSame(UserRole::Planner, $target->fresh()->role);
    }

    public function test_read_only_user_does_not_see_user_management(): void
    {
        $user = User::factory()->alleenLezen()->create();

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('href="'.url('/gebruikers').'"', false);
    }

    private function makeProject(array $overrides = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create(array_merge([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum Veldhoeve',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ], $overrides));
    }

    private function makeCalculation(User $user): Calculation
    {
        return Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'status' => CalculationStatus::Concept,
            'created_by' => $user->id,
        ]);
    }

    private function makeWorkTicket(Project $project): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => 'Nick',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        return WorkTicket::query()->create([
            'number' => 'WB-2026-0001',
            'kind' => WorkTicketKind::Werkbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
    }
}
