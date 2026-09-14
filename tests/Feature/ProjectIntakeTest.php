<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\ProjectIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_new_project(): void
    {
        $this->get(route('projects.create'))->assertRedirect(route('login'));
    }

    public function test_planner_can_create_project_from_meetstaat_and_mark_a_room_done(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $site = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $csv = UploadedFile::fake()->createWithContent('meetstaat.csv', implode("\n", [
            'nummer,naam,verdieping,m2,pvc',
            '01,Apotheek,Begane grond,120,120',
            '02,Wachtruimte,Begane grond,40,40',
        ])."\n");
        $plan = UploadedFile::fake()->image('plattegrond.jpg', 80, 60);

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Apotheek Zwolle',
            'customer_name' => 'Apotheek Centrum',
            'city' => 'Zwolle',
            'meetstaat' => $csv,
            'plattegrond' => $plan,
        ]);

        $project = Project::query()->where('name', 'Apotheek Zwolle')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('customers', ['name' => 'Apotheek Centrum']);
        $this->assertSame(2, $project->areas()->count());
        $this->assertSame(1, $project->workItems()->count());
        $this->assertSame(160.0, (float) $project->workItems()->first()->ordered_quantity);
        $this->assertSame(1, $project->documents()->where('document_type', 'plattegrond')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->count());

        $area = $project->areas()->where('name', 'Apotheek')->first();
        $this->assertNotNull($area);
        $task = $area->tasks()->first();
        $this->assertNotNull($task);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Apotheek')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('Vloer')
            ->assertDontSee('>Klaar<', false);

        $this->actingAs($site)->post(route('projects.areas.tick', [$project, $area]), [
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'phase' => 'vloer',
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame(AreaStatus::Gereed, $task->status);
        $this->assertDatabaseHas('work_progress_entries', [
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'project_area_id' => $task->project_area_id,
            'completed_quantity' => $task->ordered_quantity,
        ]);

        $this->actingAs($site)->post(route('projects.areas.tick', [$project, $area]), [
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'phase' => 'egaliseren',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_items', [
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
        ]);
        $this->assertTrue(
            $area->fresh(['tasks.workItem', 'tasks.completedByWorker'])
                ->phaseIsDone(WorkPhase::Egaliseren)
        );

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Apotheek')
            ->assertSee('Albert');
    }

    public function test_existing_customer_is_reused(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Customer::query()->create(['name' => 'TMZ Meubelenbelt', 'city' => 'Zwolle']);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Fase 3',
            'customer_name' => 'TMZ Meubelenbelt',
            'city' => 'Zwolle',
        ])->assertRedirect();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Project::query()->count());
    }

    public function test_import_preview_attaches_area_task_when_work_name_shares_material_identity(): void
    {
        $user = User::factory()->create();
        $preview = [
            'header' => [
                'project_number' => '251000099',
                'project_name' => 'Identity intake',
                'customer_name' => 'Nicon vloeren',
                'address' => null,
                'postal_code' => null,
                'city' => 'Test',
                'date' => null,
                'reference' => null,
            ],
            'works' => [
                [
                    'name' => 'Tarkett pvc Classics-English Oak grege PVC Tarkett PVC',
                    'unit' => 'm2',
                    'calculated_total' => 100.0,
                    'source_names' => ['Tarkett pvc Classics-English Oak grege PVC Tarkett PVC'],
                ],
            ],
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.01',
                    'room_name' => 'hal',
                    'square_meters' => 100.0,
                    'tasks' => [
                        [
                            'work_name' => 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT',
                            'quantity' => 100.0,
                            'unit' => 'm2',
                            'perimeter' => 0,
                            'seams' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $project = app(ProjectIntakeService::class)->importPreview($preview, $user);

        $area = $project->areas()->first();
        $this->assertNotNull($area);
        $this->assertSame(1, AreaTask::query()->where('project_area_id', $area->id)->count());
        $this->assertEqualsWithDelta(
            100.0,
            (float) AreaTask::query()->where('project_area_id', $area->id)->sum('ordered_quantity'),
            0.001
        );
    }

    public function test_duplicate_preview_tasks_for_the_same_work_are_merged_on_import(): void
    {
        $user = User::factory()->create();
        $preview = [
            'header' => [
                'project_number' => '251000100',
                'project_name' => 'Duplicate tasks',
                'customer_name' => 'Nicon vloeren',
                'address' => null,
                'postal_code' => null,
                'city' => 'Test',
                'date' => null,
                'reference' => null,
            ],
            'works' => [
                [
                    'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'calculated_total' => 30.0,
                    'source_names' => ['Marmoleum Real, 3120 rosato, Linoleum'],
                ],
            ],
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.01',
                    'room_name' => 'hal',
                    'square_meters' => 30.0,
                    'tasks' => [
                        [
                            'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                            'quantity' => 10.0,
                            'unit' => 'm2',
                            'perimeter' => 0,
                            'seams' => 0,
                        ],
                        [
                            'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                            'quantity' => 20.0,
                            'unit' => 'm2',
                            'perimeter' => 0,
                            'seams' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $project = app(ProjectIntakeService::class)->importPreview($preview, $user);

        $area = $project->areas()->first();
        $this->assertNotNull($area);
        $this->assertSame(1, AreaTask::query()->where('project_area_id', $area->id)->count());
        $this->assertEqualsWithDelta(
            30.0,
            (float) AreaTask::query()->where('project_area_id', $area->id)->sum('ordered_quantity'),
            0.001
        );
    }
}
