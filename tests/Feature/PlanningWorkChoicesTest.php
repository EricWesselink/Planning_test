<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkItem;
use App\Services\PlanningBoardService;
use App\Services\ShopWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlanningWorkChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reused_board_groups_keep_the_same_work_choices(): void
    {
        $user = User::factory()->create();
        $normal = $this->normalProject();
        $small = $this->smallProject();
        Storage::fake('local');
        $activity = WorkActivity::query()->firstOrFail();
        $winkel = app(ShopWorkService::class)->create([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$activity->id],
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ], $user);

        $direct = app(PlanningBoardService::class);
        $before = [];
        foreach ([$normal, $small, $winkel] as $project) {
            $before[$project->id] = $direct->plannableWorkChoices($project->fresh(['workItems.planningActivity']));
        }

        $request = Request::create('/planning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $board = app(PlanningBoardService::class);
        $board->build($request);
        $after = [];
        foreach ([$normal, $small, $winkel] as $project) {
            $after[$project->id] = $board->plannableWorkChoices($project->fresh(['workItems.planningActivity']));
        }

        $this->assertSame($before, $after);
        $byName = $normal->workItems->keyBy('name');
        $choiceIds = array_column($before[$normal->id], 'id');
        $this->assertNotContains($byName['Lege groep']->id, $choiceIds);
        $this->assertNotContains($byName['Ongepland meerwerk']->id, $choiceIds);
        $this->assertContains($byName['Meerwerk trap']->id, $choiceIds);
        $this->assertContains($byName['Egaliseren']->id, $choiceIds);
        $this->assertContains($byName['Linoleum']->id, $choiceIds);
        $this->assertContains($byName['PVC stroken']->id, $choiceIds);
        $this->assertNotEmpty($before[$small->id]);
        $this->assertNotEmpty($before[$winkel->id]);
        $this->assertSame(
            array_column($before[$small->id], 'id'),
            array_column($after[$small->id], 'id'),
        );
        $this->assertSame(
            array_column($before[$winkel->id], 'member_ids'),
            array_column($after[$winkel->id], 'member_ids'),
        );
    }

    private function normalProject(): Project
    {
        $project = Project::query()->create([
            'project_number' => '260200092',
            'customer_id' => Customer::query()->create(['name' => 'Gemeente'])->id,
            'name' => 'Meerdere werkzaamheden',
            'city' => 'Amersfoort',
            'kind' => ProjectKind::Project,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        foreach ([
            ['Linoleum', 'm2', 100, false, null],
            ['PVC stroken', 'm2', 40, false, null],
            ['Lege groep', 'm2', 0, false, null],
            ['Meerwerk trap', 'm2', 12, true, null],
            ['Ongepland meerwerk', 'm2', 0, true, null],
        ] as [$name, $unit, $quantity, $extra, $start]) {
            WorkItem::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'unit' => $unit,
                'ordered_quantity' => $quantity,
                'is_extra_work' => $extra,
                'planned_start_date' => $start,
                'status' => 'gepland',
            ]);
        }
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'begrote_uren' => 10,
            'status' => 'gepland',
        ]);

        return $project->fresh('workItems');
    }

    private function smallProject(): Project
    {
        $project = Project::query()->create([
            'project_number' => '260200093',
            'customer_id' => Customer::query()->create(['name' => 'Klein'])->id,
            'name' => 'Klein werk',
            'kind' => ProjectKind::Klein,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-08',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten',
            'unit' => 'm1',
            'ordered_quantity' => 12,
            'status' => 'gepland',
        ]);

        return $project->fresh('workItems');
    }
}
