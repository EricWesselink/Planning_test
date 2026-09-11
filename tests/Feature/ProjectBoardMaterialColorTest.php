<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\ProjectBoardService;
use App\Support\MaterialColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectBoardMaterialColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_payload_exposes_material_colors_separate_from_status_tone(): void
    {
        Storage::fake('local');
        [$user, $project, $area] = $this->makeBoardProject();

        $payload = app(ProjectBoardService::class)->payload($project->fresh([
            'floors.areas.tasks.workItem',
            'floors.areas.markers',
            'areas.tasks.workItem',
            'areas.markers',
            'areas.floor',
            'documents',
            'snags',
        ]));

        $summary = collect($payload['areas'])->firstWhere('id', $area->id);
        $this->assertNotNull($summary);
        $this->assertSame('#8d8676', $summary['material_color']);
        $this->assertSame('open', $summary['tone']);
        $this->assertTrue($summary['has_position']);
        $this->assertSame(1, $summary['marker']['page']);

        $detail = app(ProjectBoardService::class)->areaDetail($area->fresh(['tasks.workItem', 'markers', 'floor', 'project.documents']));
        $vloer = collect($detail['groups'])->first(
            fn (array $group) => str_contains(mb_strtolower($group['label'] ?? ''), 'dark sand')
        );
        $this->assertNotNull($vloer);
        $this->assertSame('#8d8676', $vloer['display_color']);
        $this->assertSame('Open', $vloer['status_label']);
    }

    public function test_board_page_renders_material_color_variables_on_room_rows(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeBoardProject();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('--material-color: #8d8676', false)
            ->assertSee('work-swatch', false);
    }

    public function test_selection_outline_css_is_separate_from_material_fill(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $js = file_get_contents(resource_path('js/drawing-board.js'));

        $this->assertStringContainsString('.room-selection-outline', $css);
        $this->assertMatchesRegularExpression('/\.room-selection-outline\s*\{[^}]*display:\s*none/s', $css);
        $this->assertStringNotContainsString("setAttribute('class', 'room-selection-outline')", $js);
        $this->assertStringContainsString('focusOnRoom', $js);
        $this->assertStringContainsString('storedJumpTarget', $js);
        $this->assertStringContainsString('mergeAreaFromServer', $js);
        $this->assertStringContainsString('Positie op tekening niet bekend', $js);
        $this->assertStringContainsString('hasReliableRoomPosition', $js);
        $this->assertStringContainsString('paintNameOverlay', $js);
        $this->assertStringContainsString('area.material_color', $js);
        $this->assertStringContainsString('[room-jump]', $js);
        $this->assertStringNotContainsString('areaForLabelHit(item)', $js);
        $this->assertStringNotContainsString('.room-select-fill', $css);
        $this->assertStringNotContainsString('.room-label.is-selected', $css);
        $this->assertSame(MaterialColor::UNKNOWN, MaterialColor::resolve(null, '???'));
    }

    public function test_flooring_card_does_not_use_the_plint_legend_color(): void
    {
        Storage::fake('local');
        [$user, $project, $area] = $this->makeBoardProject();
        $plint = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'display_color' => '#db4ddf',
            'unit' => 'm1',
            'ordered_quantity' => 18,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);
        $oak = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'IVC Ultimo Chapman Oak, 24245, PVC - LVT',
            'display_color' => '#db4ddf',
            'unit' => 'm2',
            'ordered_quantity' => 16.33,
            'status' => 'gepland',
            'sort_order' => 3,
        ]);
        $area->forceFill(['fill_color' => '#db4ddf'])->save();
        $area->tasks()->delete();
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $oak->id,
            'ordered_quantity' => 16.33,
            'unit' => 'm2',
            'status' => AreaStatus::NietGestart,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $plint->id,
            'ordered_quantity' => 18,
            'unit' => 'm1',
            'status' => AreaStatus::NietGestart,
        ]);

        $detail = app(ProjectBoardService::class)->areaDetail($area->fresh(['tasks.workItem', 'markers', 'floor', 'project.documents']));
        $vloer = collect($detail['groups'])->first(
            fn (array $group) => str_contains(mb_strtolower($group['label'] ?? ''), 'chapman')
        );
        $plintGroup = collect($detail['groups'])->first(
            fn (array $group) => str_contains(mb_strtolower($group['label'] ?? ''), 'plint')
        );

        $this->assertNotNull($vloer);
        $this->assertSame('#c4a484', $vloer['display_color']);
        $this->assertNotNull($plintGroup);
        $this->assertSame('#db4ddf', $plintGroup['display_color']);
        $this->assertSame('#c4a484', $area->fresh(['tasks.workItem', 'project.workItems'])->materialColor());
    }

    public function test_flooring_stays_distinct_from_plint_and_carpet_legend_colors(): void
    {
        Storage::fake('local');
        [$user, $project, $oakRoom] = $this->makeBoardProject();
        $floor = $oakRoom->floor;

        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plint, wit, Plinten 3267.00 cm',
            'display_color' => '#db4ddf',
            'unit' => 'm1',
            'ordered_quantity' => 32.67,
            'status' => 'gepland',
            'sort_order' => 4,
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plint, wit, Plinten 11438.60 cm',
            'display_color' => '#db4ddf',
            'unit' => 'm1',
            'ordered_quantity' => 114.38,
            'status' => 'gepland',
            'sort_order' => 5,
        ]);
        $stolenPlint = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'display_color' => '#5f72f5',
            'unit' => 'm1',
            'ordered_quantity' => 18,
            'status' => 'gepland',
            'sort_order' => 6,
        ]);
        $oak = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'IVC Ultimo Chapman Oak, 24245, PVC - LVT',
            'display_color' => '#db4ddf',
            'unit' => 'm2',
            'ordered_quantity' => 16.33,
            'status' => 'gepland',
            'sort_order' => 7,
        ]);
        $desso = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Desso Airmaster Atmos B747 9092, Tapijttegels',
            'display_color' => '#5f72f5',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'gepland',
            'sort_order' => 8,
        ]);
        $taraflex = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Taraflex multi-use, 6350 light cherry 6,3 mm, n.t.b., PVC / Vinyl',
            'display_color' => '#ee5086',
            'unit' => 'm2',
            'ordered_quantity' => 110.37,
            'status' => 'gepland',
            'sort_order' => 9,
        ]);

        $oakRoom->forceFill(['fill_color' => '#d75fd1', 'name' => 'cabine 3'])->save();
        $oakRoom->tasks()->delete();
        AreaTask::query()->create([
            'project_area_id' => $oakRoom->id,
            'work_item_id' => $oak->id,
            'ordered_quantity' => 16.33,
            'unit' => 'm2',
            'status' => AreaStatus::NietGestart,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $oakRoom->id,
            'work_item_id' => $stolenPlint->id,
            'ordered_quantity' => 18,
            'unit' => 'm1',
            'status' => AreaStatus::NietGestart,
        ]);

        $dessoRoom = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.10',
            'name' => 'leer en meer 1',
            'square_meters' => 12.4,
            'fill_color' => '#5f72f5',
            'status' => AreaStatus::NietGestart,
            'sort_order' => 2,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $dessoRoom->id,
            'work_item_id' => $desso->id,
            'ordered_quantity' => 12.4,
            'unit' => 'm2',
            'status' => AreaStatus::NietGestart,
        ]);

        $taraflexRoom = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.20',
            'name' => 'oefenruimte',
            'square_meters' => 32.1,
            'fill_color' => '#ee5086',
            'status' => AreaStatus::NietGestart,
            'sort_order' => 3,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $taraflexRoom->id,
            'work_item_id' => $taraflex->id,
            'ordered_quantity' => 32.1,
            'unit' => 'm2',
            'status' => AreaStatus::NietGestart,
        ]);

        $service = app(ProjectBoardService::class);
        $oakDetail = $service->areaDetail($oakRoom->fresh(['tasks.workItem', 'markers', 'floor', 'project.documents', 'project.workItems']));
        $oakGroup = collect($oakDetail['groups'])->first(
            fn (array $group) => str_contains(mb_strtolower($group['label'] ?? ''), 'chapman')
        );
        $plintGroup = collect($oakDetail['groups'])->first(
            fn (array $group) => str_contains(mb_strtolower($group['label'] ?? ''), 'plint')
        );

        $this->assertSame('#c4a484', $oakRoom->fresh(['tasks.workItem', 'project.workItems'])->materialColor());
        $this->assertSame('#c4a484', $oakGroup['display_color']);
        $this->assertSame('#db4ddf', $plintGroup['display_color']);
        $this->assertSame('#5f72f5', $dessoRoom->fresh(['tasks.workItem', 'project.workItems'])->materialColor());
        $this->assertSame('#ee5086', $taraflexRoom->fresh(['tasks.workItem', 'project.workItems'])->materialColor());
    }

    /**
     * @return array{0: User, 1: Project, 2: ProjectArea}
     */
    private function makeBoardProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Test']);
        $project = Project::query()->create([
            'project_number' => 'P-COLOR-1',
            'customer_id' => $customer->id,
            'name' => 'Kleurtest',
            'supervisor_user_id' => $user->id,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'installaties wko',
            'square_meters' => 38.79,
            'fill_color' => '#8d8676',
            'status' => AreaStatus::NietGestart,
            'sort_order' => 1,
        ]);
        $work = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Dark Sand, PVC',
            'display_color' => '#8d8676',
            'unit' => 'm2',
            'ordered_quantity' => 38.79,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $work->id,
            'ordered_quantity' => 38.79,
            'unit' => 'm2',
            'status' => AreaStatus::NietGestart,
        ]);
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plan.pdf',
            'file_path' => 'projects/'.$project->id.'/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'ok',
            'uploaded_by' => $user->id,
        ]);
        AreaDrawingMarker::query()->create([
            'project_area_id' => $area->id,
            'project_document_id' => $document->id,
            'page' => 1,
            'x' => 0.12,
            'y' => 0.34,
            'width' => 0.08,
            'height' => 0.05,
            'label_text' => '0.01 installaties wko',
            'polygon' => [
                ['x' => 0.10, 'y' => 0.30],
                ['x' => 0.22, 'y' => 0.30],
                ['x' => 0.22, 'y' => 0.42],
                ['x' => 0.10, 'y' => 0.42],
            ],
            'confidence' => 0.9,
            'source' => 'auto',
        ]);

        return [$user, $project, $area];
    }
}
