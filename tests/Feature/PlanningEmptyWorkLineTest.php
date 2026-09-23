<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningEmptyWorkLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_line_can_be_turned_off_adjusted_and_deleted(): void
    {
        [$user, $project, $floor, $rubber] = $this->projectWithEmptyLine();
        $planningUrl = route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]);

        $this->actingAs($user)
            ->get($planningUrl)
            ->assertOk()
            ->assertSee('plan-empty-line', false)
            ->assertSee('Verwijder')
            ->assertSee('Rubber tegels')
            ->assertSee('Linoleum')
            ->assertSee(route('planning.work-line.update', $rubber), false)
            ->assertDontSee(route('planning.work-line.update', $floor), false);

        $this->actingAs($user)
            ->from($planningUrl)
            ->patch(route('planning.work-line.update', $rubber), [
                'included' => '0',
                'quantity' => '0',
            ])
            ->assertRedirect($planningUrl);

        $this->assertFalse((bool) $rubber->fresh()->planning_included);
        $this->actingAs($user)
            ->get($planningUrl)
            ->assertOk()
            ->assertSee('plan-line--off', false)
            ->assertSee('Rubber tegels');

        $this->actingAs($user)
            ->from($planningUrl)
            ->patch(route('planning.work-line.update', $rubber), [
                'included' => '1',
                'quantity' => '40',
            ])
            ->assertRedirect($planningUrl);

        $rubber->refresh();
        $this->assertTrue((bool) $rubber->planning_included);
        $this->assertEqualsWithDelta(40, (float) $rubber->ordered_quantity, 0.01);
        $this->actingAs($user)
            ->get($planningUrl)
            ->assertOk()
            ->assertSee('40 m²')
            ->assertDontSee('plan-empty-line', false);

        $spare = $this->emptyLine($project, 'Overig vloerwerk');
        $this->actingAs($user)
            ->from($planningUrl)
            ->delete(route('planning.work-line.destroy', $spare))
            ->assertRedirect($planningUrl);

        $this->assertNull(WorkItem::query()->find($spare->id));
        $this->actingAs($user)
            ->get($planningUrl)
            ->assertOk()
            ->assertDontSee(route('planning.work-line.destroy', $spare), false);
    }

    public function test_a_line_with_square_meters_or_inzet_cannot_be_removed_here(): void
    {
        [$user, $project, $floor, $rubber] = $this->projectWithEmptyLine();
        $planningUrl = route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]);

        $this->actingAs($user)
            ->from($planningUrl)
            ->patch(route('planning.work-line.update', $floor), [
                'included' => '0',
                'quantity' => '1',
            ])
            ->assertRedirect($planningUrl)
            ->assertSessionHasErrors('work_line');

        $this->assertEqualsWithDelta(10, (float) $floor->fresh()->ordered_quantity, 0.01);

        $this->actingAs($user)
            ->from($planningUrl)
            ->delete(route('planning.work-line.destroy', $floor))
            ->assertRedirect($planningUrl)
            ->assertSessionHasErrors('work_line');

        $this->assertNotNull($floor->fresh());

        $worker = Worker::query()->create([
            'name' => 'Nick',
            'employment_type' => 'eigen',
            'people_count' => 1,
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $rubber->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->from($planningUrl)
            ->delete(route('planning.work-line.destroy', $rubber))
            ->assertRedirect($planningUrl)
            ->assertSessionHasErrors('work_line');

        $this->assertNotNull($rubber->fresh());
    }

    public function test_someone_who_cannot_manage_planning_cannot_change_an_empty_line(): void
    {
        [, , , $rubber] = $this->projectWithEmptyLine();
        $viewer = User::factory()->uitvoerder()->create();

        $this->actingAs($viewer)
            ->patch(route('planning.work-line.update', $rubber), [
                'included' => '0',
                'quantity' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $rubber->fresh()->planning_included);
    }

    public function test_read_only_user_cannot_delete_an_empty_line(): void
    {
        [, , , $rubber] = $this->projectWithEmptyLine();
        $reader = User::factory()->create(['role' => UserRole::AlleenLezen]);

        $this->actingAs($reader)
            ->delete(route('planning.work-line.destroy', $rubber))
            ->assertForbidden();

        $this->assertNotNull($rubber->fresh());
    }

    /**
     * @return array{0: User, 1: Project, 2: WorkItem, 3: WorkItem}
     */
    private function projectWithEmptyLine(): array
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Philadelphia']);
        $project = Project::query()->create([
            'project_number' => '4843-1',
            'customer_id' => $customer->id,
            'name' => 'Kampen Philadelphia Kaarsenmakerij',
            'city' => 'Kampen',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $floor = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
        ]);
        $rubber = $this->emptyLine($project, 'Rubber tegels');

        return [$user, $project, $floor, $rubber];
    }

    private function emptyLine(Project $project, string $name): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'begrote_uren' => 4,
            'status' => 'gepland',
        ]);
    }
}
