<?php

namespace Tests\Feature;

use App\Enums\AssignmentKind;
use App\Enums\InternalBusinessUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class InternalDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_store_internal_deployment(): void
    {
        $this->postJson(route('planning.internal.store'), $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_uitvoerder_cannot_store_internal_deployment(): void
    {
        $worker = $this->worker();

        $this->actingAs(User::factory()->uitvoerder()->create())
            ->postJson(route('planning.internal.store'), $this->payload($worker))
            ->assertForbidden();

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_store_requires_worker_unit_description_and_dates(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('planning.internal.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'worker_id' => 'Kies een vakman of team.',
                'business_unit' => 'Kies een bedrijfsonderdeel.',
                'description' => 'Vul een omschrijving in.',
                'start_date' => 'Vul een van-datum in.',
                'end_date' => 'Vul een t/m-datum in.',
            ]);
    }

    public function test_rejects_an_unknown_business_unit(): void
    {
        $worker = $this->worker();

        $this->actingAs(User::factory()->create())
            ->postJson(route('planning.internal.store'), [
                ...$this->payload($worker),
                'business_unit' => 'geen-onderdeel',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'business_unit' => 'Kies een bedrijfsonderdeel.',
            ]);

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_internal_deployment_occupies_a_worker_without_creating_a_project(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker();
        $project = $this->floorProject();
        $item = $this->floorWork($project);
        $floor = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-15',
            'people_count' => 1,
            'hours_per_day' => 8,
        ]);
        $projects = Project::query()->count();
        $customers = Customer::query()->count();
        $works = WorkItem::query()->count();
        $orders = WorkOrder::query()->count();

        $this->actingAs($user)
            ->postJson(route('planning.internal.store'), $this->payload($worker))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame($projects, Project::query()->count());
        $this->assertSame($customers, Customer::query()->count());
        $this->assertSame($works, WorkItem::query()->count());
        $this->assertSame($orders, WorkOrder::query()->count());
        $floor->refresh();
        $this->assertSame($project->id, $floor->project_id);
        $this->assertSame('2026-09-14', $floor->start_date->toDateString());
        $this->assertSame('2026-09-15', $floor->end_date->toDateString());
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'project_id' => null,
            'kind' => AssignmentKind::Internal->value,
            'business_unit' => InternalBusinessUnit::NicoDekvloeren->value,
            'description' => 'Werk voor Nico Dekvloeren',
            'notes' => 'Niet op een vloerproject',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Interne inzet – Nico Dekvloeren')
            ->assertSee('Werk voor Nico Dekvloeren')
            ->assertSee('Niet op een vloerproject')
            ->assertSee('data-internal="1"', false);

        $this->actingAs($user)
            ->postJson(route('planning.internal.store'), [
                ...$this->payload($worker),
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-15',
            ])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);

        $this->assertSame(2, WorkerAssignment::query()->count());

        $blocked = $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
                'people_count' => 1,
                'hours' => 8,
            ]);

        $blocked->assertStatus(409);
        $this->assertStringContainsString('Interne inzet', (string) $blocked->json('message'));
        $this->assertStringContainsString('Nico Dekvloeren', (string) $blocked->json('message'));
        $this->assertSame(1, WorkerAssignment::query()->where('project_id', $project->id)->count());
    }

    public function test_internal_deployment_can_be_shortened_and_deleted(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker();
        $this->actingAs($user)
            ->postJson(route('planning.internal.store'), $this->payload($worker))
            ->assertOk();
        $assignment = WorkerAssignment::query()->where('kind', AssignmentKind::Internal->value)->firstOrFail();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'end_date' => '2026-09-08',
                'description' => 'Werk voor Nico Dekvloeren, korter',
                'business_unit' => InternalBusinessUnit::ScreensZonwering->value,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('2026-09-08', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-08', $assignment->end_date->toDateString());
        $this->assertSame('Werk voor Nico Dekvloeren, korter', $assignment->description);
        $this->assertSame(InternalBusinessUnit::ScreensZonwering, $assignment->business_unit);
        $this->assertNull($assignment->project_id);

        $this->actingAs($user)
            ->deleteJson(route('planning.assignments.destroy', $assignment))
            ->assertOk();

        $this->assertModelMissing($assignment);
    }

    public function test_weekplanning_pdf_names_the_internal_deployment(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker();
        $this->actingAs($user)
            ->postJson(route('planning.internal.store'), $this->payload($worker))
            ->assertOk();

        $response = $this->actingAs($user)
            ->get(route('planning.weekplanning', ['week' => '2026-09-07']));

        $response->assertOk();
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($response->getContent())->getText()) ?? '';
        $this->assertStringContainsString('Interne inzet – Nico Dekvloeren – Werk voor Nico Dekvloeren', $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?Worker $worker = null): array
    {
        $worker?->load('crewPeople');

        return [
            'worker_id' => $worker?->id ?? 0,
            'crew_member_ids' => $worker?->crewPeople->pluck('id')->all() ?? [],
            'business_unit' => InternalBusinessUnit::NicoDekvloeren->value,
            'description' => 'Werk voor Nico Dekvloeren',
            'notes' => 'Niet op een vloerproject',
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
        ];
    }

    private function worker(): Worker
    {
        return Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => 1,
            'specialty' => 'Linoleum, Coating',
            'active' => true,
        ]);
    }

    private function floorProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);

        return Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-15',
        ]);
    }

    private function floorWork(Project $project): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-15',
            'status' => 'in_uitvoering',
        ]);
    }
}
