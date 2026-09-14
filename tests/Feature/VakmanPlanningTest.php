<?php

namespace Tests\Feature;

use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VakmanPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_vakman_sees_only_own_planning_work_address_and_colleagues(): void
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
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Mijn planning')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Industrieweg 8, 8013 PM Zwolle')
            ->assertSee('PVC leggen')
            ->assertSee('Kees Jansen')
            ->assertDontSee('Kindcentrum Veldhoeve')
            ->assertDontSee('€')
            ->assertDontSee('87,50')
            ->assertDontSee('87.50')
            ->assertDontSee('uurtarief')
            ->assertDontSee('Gebruikers')
            ->assertDontSee('Vakmensen / ZZP')
            ->assertDontSee('Archief')
            ->getContent();

        $this->assertStringContainsString('href="'.route('vakman.planning').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/planning').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/projecten').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/gebruikers').'"', $html);
        $this->assertStringNotContainsString('images/decoloop.png', $html);
        $this->assertStringNotContainsString((string) $other->name, $html);
    }

    public function test_empty_vakman_planning_explains_that_nothing_is_scheduled(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine');
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Je staat de komende weken niet ingepland.');
    }

    public function test_planner_cannot_open_mijn_planning(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Worker, 1: Project, 2: Project}
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

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
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
