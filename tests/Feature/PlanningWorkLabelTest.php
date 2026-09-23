<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Models\WorkItem;
use App\Services\PlanningBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PlanningWorkLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_can_set_a_planning_line_to_a_work_activity_name(): void
    {
        [$user, $project, $linoleum, $otherLinoleum, $pvc, $activity] = $this->projectWithLines();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('plan-work-label', false)
            ->assertSee('Showroomtapijt')
            ->assertSee('Linoleum');

        $planningUrl = route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]);

        $this->actingAs($user)
            ->from($planningUrl)
            ->patch(route('planning.work-label.update', $linoleum), [
                'work_activity_id' => $activity->id,
            ])
            ->assertRedirect($planningUrl);

        $this->assertSame($activity->id, $linoleum->fresh()->planning_work_activity_id);
        $this->assertSame($activity->id, $otherLinoleum->fresh()->planning_work_activity_id);
        $this->assertNull($pvc->fresh()->planning_work_activity_id);

        $titles = $this->lineTitles($user, $project);
        $this->assertContains('Showroomtapijt', $titles);
        $this->assertNotContains('Linoleum', $titles);
        $this->assertContains('PVC', $titles);

        $activity->update(['name' => 'Showroomtapijt nieuw']);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('value="'.$activity->id.'" selected', false)
            ->assertSee('Showroomtapijt nieuw');

        $this->assertContains('Showroomtapijt nieuw', $this->lineTitles($user, $project));
    }

    public function test_clearing_the_choice_restores_the_automatic_label(): void
    {
        [$user, $project, $linoleum, $otherLinoleum, , $activity] = $this->projectWithLines();
        $linoleum->update(['planning_work_activity_id' => $activity->id]);
        $otherLinoleum->update(['planning_work_activity_id' => $activity->id]);

        $this->actingAs($user)
            ->patchJson(route('planning.work-label.update', $linoleum), [
                'work_activity_id' => null,
            ])
            ->assertOk();

        $this->assertNull($linoleum->fresh()->planning_work_activity_id);
        $this->assertNull($otherLinoleum->fresh()->planning_work_activity_id);
        $this->assertContains('Linoleum', $this->lineTitles($user, $project));
    }

    public function test_inactive_work_activity_is_rejected(): void
    {
        [$user, , $linoleum, , , $activity] = $this->projectWithLines();
        $activity->update(['is_active' => false]);

        $this->actingAs($user)
            ->patchJson(route('planning.work-label.update', $linoleum), [
                'work_activity_id' => $activity->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.work_activity_id.0', 'Kies een werkzaamheid uit beheer.');

        $this->assertNull($linoleum->fresh()->planning_work_activity_id);
    }

    public function test_uitvoerder_sees_the_label_without_the_choice_and_cannot_change_it(): void
    {
        [, $project, $linoleum, , , $activity] = $this->projectWithLines();
        $linoleum->update(['planning_work_activity_id' => $activity->id]);
        $viewer = User::factory()->uitvoerder()->create();

        $this->actingAs($viewer)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Showroomtapijt')
            ->assertDontSee('plan-work-label', false);

        $this->actingAs($viewer)
            ->patchJson(route('planning.work-label.update', $linoleum), [
                'work_activity_id' => null,
            ])
            ->assertForbidden();

        $this->assertSame($activity->id, $linoleum->fresh()->planning_work_activity_id);
    }

    public function test_unauthenticated_work_label_update_returns_401(): void
    {
        [, , $linoleum, , , $activity] = $this->projectWithLines();

        $this->patchJson(route('planning.work-label.update', $linoleum), [
            'work_activity_id' => $activity->id,
        ])->assertUnauthorized();

        $this->assertNull($linoleum->fresh()->planning_work_activity_id);
    }

    public function test_read_only_user_cannot_change_the_planning_label(): void
    {
        [, , $linoleum, , , $activity] = $this->projectWithLines();
        $reader = User::factory()->create(['role' => UserRole::AlleenLezen]);

        $this->actingAs($reader)
            ->patchJson(route('planning.work-label.update', $linoleum), [
                'work_activity_id' => $activity->id,
            ])
            ->assertForbidden();

        $this->assertNull($linoleum->fresh()->planning_work_activity_id);
    }

    /**
     * @return array{0: User, 1: Project, 2: WorkItem, 3: WorkItem, 4: WorkItem, 5: WorkActivity}
     */
    private function projectWithLines(): array
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Hegeman']);
        $project = Project::query()->create([
            'project_number' => '4843-1',
            'customer_id' => $customer->id,
            'name' => 'COA Oisterwijk',
            'city' => 'Oisterwijk',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
        ]);
        $otherLinoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 20,
            'status' => 'gepland',
        ]);
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'BTB Liplas PVC tegel',
            'unit' => 'm2',
            'ordered_quantity' => 5,
            'status' => 'gepland',
        ]);
        $category = WorkActivityCategory::query()->where('slug', 'vloeren')->firstOrFail();
        $activity = WorkActivity::query()->create([
            'work_activity_category_id' => $category->id,
            'name' => 'Showroomtapijt',
            'slug' => 'showroomtapijt',
            'sort_order' => 80,
            'is_active' => true,
        ]);

        return [$user, $project, $linoleum, $otherLinoleum, $pvc, $activity];
    }

    /**
     * @return list<string>
     */
    private function lineTitles(User $user, Project $project): array
    {
        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]);
        $request->setUserResolver(fn () => $user);
        $row = collect(app(PlanningBoardService::class)->build($request)['rows'])
            ->firstWhere('id', $project->id);

        return collect($row['children'] ?? [])->pluck('title')->all();
    }
}
