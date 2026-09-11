<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\ShopWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlanningKindFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_works_shows_projects_first_then_winkelwerk_section(): void
    {
        $user = User::factory()->create();
        $this->createConstruction('Laakse Tuinen');
        $this->createWinkel($user, 'Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('name="kind"', false)
            ->assertSee('>Alle werken</option>', false)
            ->assertSee('>Projecten</option>', false)
            ->assertSee('>Winkelwerk</option>', false)
            ->assertSee('plan-line--section', false)
            ->assertSee('>Nicon Vloeren</div>', false)
            ->assertSee('>Kloppenburg Interieur</div>', false)
            ->assertSee('Laakse Tuinen')
            ->assertSee('Jansen - Hengelo');
    }

    public function test_projects_filter_hides_winkelwerk_and_section(): void
    {
        $user = User::factory()->create();
        $this->createConstruction('Laakse Tuinen');
        $this->createWinkel($user, 'Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'kind' => ProjectKind::Project->value]))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Jansen - Hengelo')
            ->assertDontSee('plan-line--section', false)
            ->assertDontSee('>Kloppenburg Interieur</div>', false);
    }

    public function test_winkelwerk_filter_hides_projects_without_section_header(): void
    {
        $user = User::factory()->create();
        $this->createConstruction('Laakse Tuinen');
        $this->createWinkel($user, 'Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'kind' => ProjectKind::Winkel->value]))
            ->assertOk()
            ->assertSee('Jansen - Hengelo')
            ->assertDontSee('Laakse Tuinen')
            ->assertDontSee('plan-line--section', false)
            ->assertDontSee('>Kloppenburg Interieur</div>', false);
    }

    public function test_kind_filter_combines_with_worker_filter(): void
    {
        $user = User::factory()->create();
        $kees = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $other = Worker::query()->create([
            'name' => 'Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $construction = $this->createConstruction('Laakse Tuinen');
        $winkel = $this->createWinkel($user, 'Jansen', 'Hengelo');
        $otherWinkel = $this->createWinkel($user, 'Bakker', 'Almelo', 'marmoleum');

        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $construction->id,
            'work_item_id' => $construction->workItems()->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $winkel->id,
            'work_item_id' => $winkel->workItems()->first()->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $other->id,
            'project_id' => $otherWinkel->id,
            'work_item_id' => $otherWinkel->workItems()->first()->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'kind' => ProjectKind::Winkel->value,
                'worker_id' => $kees->id,
            ]))
            ->assertOk()
            ->assertSee('Jansen - Hengelo')
            ->assertDontSee('>Laakse Tuinen</span>', false)
            ->assertDontSee('data-project-id="'.$otherWinkel->id.'"', false);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'kind' => ProjectKind::Project->value,
                'worker_id' => $kees->id,
            ]))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('data-project-id="'.$winkel->id.'"', false)
            ->assertDontSee('data-project-id="'.$otherWinkel->id.'"', false);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'worker_id' => $kees->id,
            ]))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertSee('Jansen - Hengelo')
            ->assertDontSee('data-project-id="'.$otherWinkel->id.'"', false)
            ->assertSee('>Kloppenburg Interieur</div>', false);
    }

    public function test_kind_filter_does_not_change_or_delete_projects(): void
    {
        $user = User::factory()->create();
        $construction = $this->createConstruction('Laakse Tuinen');
        $winkel = $this->createWinkel($user, 'Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'kind' => ProjectKind::Project->value]))
            ->assertOk();

        $this->assertSame(2, Project::query()->count());
        $this->assertDatabaseHas('projects', ['id' => $construction->id, 'kind' => ProjectKind::Project->value]);
        $this->assertDatabaseHas('projects', ['id' => $winkel->id, 'kind' => ProjectKind::Winkel->value]);
    }

    private function createConstruction(string $name): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'kind' => ProjectKind::Project,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        return $project->fresh('workItems');
    }

    private function createWinkel(User $user, string $customer, string $city, string $activitySlug = 'pvc'): Project
    {
        Storage::fake('local');
        $activity = WorkActivity::query()->where('slug', $activitySlug)->firstOrFail();

        return app(ShopWorkService::class)->create([
            'customer_name' => $customer,
            'city' => $city,
            'work_activity_ids' => [$activity->id],
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ], $user);
    }
}
