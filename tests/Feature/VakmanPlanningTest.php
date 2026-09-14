<?php

namespace Tests\Feature;

use App\Enums\WorkOrderType;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VakmanPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_vakman_week_agenda_shows_compact_day_cards_without_material_lists(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own, $other] = $this->seedProjects();
        $kees = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'uurtarief' => 87.5,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'Tarkett pvc Classics-English Oak, PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 80,
            'status' => 'gepland',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Mijn planning')
            ->assertSee('Week')
            ->assertSee('Maand')
            ->assertSee('Donderdag 10 september')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Zwolle')
            ->assertSee('08:00 – 16:00')
            ->assertSee('PVC')
            ->assertSee('Kees Jansen')
            ->assertSee('Bekijk werk')
            ->assertDontSee('Kindcentrum Veldhoeve')
            ->assertDontSee('Industrieweg 8')
            ->assertDontSee('Tarkett')
            ->assertDontSee('Classics-English Oak')
            ->assertDontSee('€')
            ->assertDontSee('87,50')
            ->assertDontSee('87.50')
            ->assertDontSee('uurtarief')
            ->assertDontSee('Gebruikers')
            ->assertDontSee('Vakmensen / ZZP')
            ->assertDontSee('Archief')
            ->assertDontSee('Werkbon')
            ->assertDontSee('Opdrachtbon')
            ->getContent();

        $this->assertStringContainsString('href="'.route('vakman.planning').'"', $html);
        $this->assertStringContainsString('href="'.route('vakman.planning.day', '2026-09-10').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/planning').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/projecten').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/gebruikers').'"', $html);
        $this->assertStringNotContainsString('images/decoloop.png', $html);
        $this->assertStringNotContainsString((string) $other->name, $html);
    }

    public function test_vakman_day_detail_shows_address_work_and_werkbon_without_prices(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $item = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'uurtarief' => 87.5,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $own->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $own->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'Entree',
            'square_meters' => 24,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => 24,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Donderdag 10 september')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Industrieweg 8, 8013 PM Zwolle')
            ->assertSee('PVC')
            ->assertSee('120')
            ->assertSee('Begane grond')
            ->assertSee('0.01 Entree')
            ->assertSee('Projectinformatie')
            ->assertSee('Werkbon')
            ->assertDontSee('Opdrachtbon')
            ->assertDontSee('€')
            ->assertDontSee('87,50');

        $this->actingAs($user)
            ->get(route('vakman.planning.werkbon', '2026-09-10'))
            ->assertOk()
            ->assertSee('Werkbon')
            ->assertSee('Laakse Tuinen')
            ->assertSee('PVC')
            ->assertDontSee('€')
            ->assertDontSee('87,50')
            ->assertDontSee('Opdrachtbon');
    }

    public function test_zzp_day_shows_opdrachtbon_with_agreed_price_and_hides_werkbon(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $own = $this->makeProject('Laakse Tuinen', [
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'status' => 'gepland',
        ]);
        WorkOrder::query()->create([
            'project_id' => $own->id,
            'work_item_id' => $item->id,
            'worker_id' => $nick->id,
            'assignment_type' => WorkOrderType::WorkItem,
            'assigned_quantity' => 120,
            'unit' => WorkUnit::SquareMeter,
            'unit_price' => 12.5,
            'status' => 'gepland',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertDontSee('Werkbon')
            ->assertDontSee('€');

        $this->actingAs($user)
            ->get(route('vakman.planning.opdrachtbon', ['date' => '2026-09-10', 'project' => $own]))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('PVC')
            ->assertSee('€ 12,50')
            ->assertSee('120');

        $this->actingAs($user)
            ->get(route('vakman.planning.werkbon', '2026-09-10'))
            ->assertForbidden();
    }

    public function test_eigen_vakman_cannot_open_opdrachtbon(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning.opdrachtbon', ['date' => '2026-09-10', 'project' => $own]))
            ->assertForbidden();
    }

    public function test_month_view_marks_days_with_work(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning', ['view' => 'month', 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Maand')
            ->assertSee('september 2026')
            ->assertSee('Laakse Tuinen')
            ->assertSee(route('vakman.planning.day', '2026-09-10'), false);
    }

    public function test_empty_vakman_planning_explains_that_nothing_is_scheduled(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine');
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Je staat deze week niet ingepland.');
    }

    public function test_planner_cannot_open_mijn_planning(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Worker, 1: Project, 2?: Project}
     */
    private function seedProjects(): array
    {
        $nick = $this->makeWorker('Nick Seine');
        $own = $this->makeProject('Laakse Tuinen', [
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
        ]);
        $other = $this->makeProject('Kindcentrum Veldhoeve');
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $this->makeWorker('Andere ploeg')->id,
            'project_id' => $other->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        return [$nick, $own, $other];
    }

    private function makeWorker(string $name, string $employmentType = 'eigen'): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => $employmentType,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(string $name, array $attributes = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create(array_merge([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ], $attributes));
    }
}
