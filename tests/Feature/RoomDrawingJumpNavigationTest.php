<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Models\AreaDrawingMarker;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Services\ProjectBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomDrawingJumpNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_payload_keeps_distinct_markers_for_duplicate_room_names(): void
    {
        Storage::fake('local');
        [$project, $rooms] = $this->makeDuplicateNameProject();

        $payload = app(ProjectBoardService::class)->payload($project->fresh([
            'floors.areas.markers',
            'areas.markers',
            'areas.floor',
            'documents',
            'snags',
            'areas.tasks.workItem',
        ]));

        $byId = collect($payload['areas'])->keyBy('id');
        foreach ($rooms as $room) {
            $summary = $byId->get($room['area']->id);
            $this->assertNotNull($summary);
            $this->assertSame($room['name'], $summary['name']);
            $this->assertTrue($summary['has_position']);
            $this->assertSame($room['page'], (int) $summary['marker']['page']);
            $this->assertEqualsWithDelta($room['x'], (float) $summary['marker']['x'], 0.0001);
            $this->assertEqualsWithDelta($room['y'], (float) $summary['marker']['y'], 0.0001);
        }

        $kleedkamers = collect($payload['areas'])->where('name', 'kleedkamer')->values();
        $this->assertCount(2, $kleedkamers);
        $this->assertNotEquals(
            $kleedkamers[0]['marker']['x'].'|'.$kleedkamers[0]['marker']['y'],
            $kleedkamers[1]['marker']['x'].'|'.$kleedkamers[1]['marker']['y'],
        );
    }

    public function test_drawing_board_list_click_never_resolves_jump_by_room_name(): void
    {
        $js = file_get_contents(resource_path('js/drawing-board.js'));

        $this->assertStringContainsString('selectArea(row.dataset.areaId)', $js);
        $this->assertStringContainsString('storedJumpTarget(area)', $js);
        $this->assertStringContainsString('mergeAreaFromServer', $js);
        $this->assertStringContainsString('Opgeslagen import-geometry niet laten overschrijven', $js);
        $this->assertStringContainsString('if (!data.canManuallyLinkRooms)', $js);
        $this->assertStringContainsString('Automatisch gekoppeld:', $js);
        $this->assertStringContainsString('Handmatig nodig:', $js);
        $this->assertStringContainsString('Aanwijzen op tekening', $js);
        $this->assertStringNotContainsString('Controleren: meerdere gelijkwaardige plekken op de tekening', $js);
        $this->assertStringContainsString('persistRoomLink', $js);
        $this->assertStringContainsString('exactRoomHitForArea', $js);
        $this->assertStringNotContainsString('room-jump-highlight', $js);
        $this->assertStringNotContainsString('appendJumpHighlight', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/function centerOn\(area\)[\s\S]*areaForLabelHit/',
            $js
        );
    }

    public function test_board_payload_numbers_duplicate_room_names_without_renaming_stored_name(): void
    {
        Storage::fake('local');
        [$project, $rooms] = $this->makeUntitledDuplicateProject();

        $payload = app(ProjectBoardService::class)->payload($project->fresh([
            'floors.areas.markers',
            'areas.markers',
            'areas.floor',
            'documents',
            'snags',
            'areas.tasks.workItem',
        ]));

        $byId = collect($payload['areas'])->keyBy('id');
        $this->assertSame('gang', $byId[$rooms[0]['area']->id]['name']);
        $this->assertSame('gang', $byId[$rooms[0]['area']->id]['name_raw']);
        $this->assertSame('Gang 1', $byId[$rooms[0]['area']->id]['unique_name']);
        $this->assertSame('Gang 2', $byId[$rooms[1]['area']->id]['unique_name']);
        $this->assertSame('Gang 3', $byId[$rooms[2]['area']->id]['unique_name']);
        $this->assertSame('Entree 4 1', $byId[$rooms[3]['area']->id]['unique_name']);
        $this->assertSame('Entree 4 2', $byId[$rooms[4]['area']->id]['unique_name']);
        $this->assertSame('aula', $byId[$rooms[5]['area']->id]['unique_name']);
        $this->assertFalse($byId[$rooms[2]['area']->id]['has_position']);
        $this->assertTrue($byId[$rooms[0]['area']->id]['has_position']);
    }

    public function test_board_list_renders_numbered_duplicate_names(): void
    {
        Storage::fake('local');
        [$project] = $this->makeUntitledDuplicateProject();
        $user = User::query()->find($project->supervisor_user_id);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Gang 1', false)
            ->assertSee('Gang 2', false)
            ->assertSee('Gang 3', false)
            ->assertSee('Entree 4 1', false)
            ->assertSee('Entree 4 2', false)
            ->assertSee('aula', false)
            ->assertDontSee('>gang</span>', false);
    }

    public function test_board_payload_numbers_duplicates_by_drawing_position(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => 'P-UNIQUE-X',
            'customer_id' => $customer->id,
            'name' => 'Spatial names',
            'supervisor_user_id' => $user->id,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $path = 'projects/'.$project->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        $right = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'name' => 'gang',
            'square_meters' => 5.72,
            'status' => AreaStatus::NietGestart,
            'sort_order' => 1,
        ]);
        $left = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'name' => 'gang',
            'square_meters' => 5.72,
            'status' => AreaStatus::NietGestart,
            'sort_order' => 2,
        ]);
        foreach ([[$right, 0.70, 0.30], [$left, 0.20, 0.32]] as [$area, $x, $y]) {
            AreaDrawingMarker::query()->create([
                'project_area_id' => $area->id,
                'project_document_id' => $document->id,
                'page' => 1,
                'x' => $x,
                'y' => $y,
                'width' => 0.08,
                'height' => 0.05,
                'label_text' => 'gang',
                'confidence' => 0.82,
                'source' => 'auto',
            ]);
        }

        $payload = app(ProjectBoardService::class)->payload($project->fresh([
            'floors.areas.markers',
            'areas.markers',
            'areas.floor',
            'documents',
            'snags',
            'areas.tasks.workItem',
        ]));
        $byId = collect($payload['areas'])->keyBy('id');

        $this->assertSame('Gang 1', $byId[$left->id]['unique_name']);
        $this->assertSame('Gang 2', $byId[$right->id]['unique_name']);
    }

    public function test_projectleider_board_omits_manual_place_route(): void
    {
        Storage::fake('local');
        [$project] = $this->makeUntitledDuplicateProject();
        $user = User::query()->find($project->supervisor_user_id);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"canManuallyLinkRooms":false', $html);
        $this->assertStringContainsString('"place":null', $html);
    }

    public function test_admin_board_exposes_manual_place_route(): void
    {
        Storage::fake('local');
        [$project] = $this->makeUntitledDuplicateProject();
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"canManuallyLinkRooms":true', $html);
        $this->assertStringContainsString('/projecten/'.$project->id.'/ruimtes/__AREA__/marker', $html);
    }

    public function test_drawing_board_overlays_use_unique_name_and_area_id(): void
    {
        $js = file_get_contents(resource_path('js/drawing-board.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('function uniqueName(area)', $js);
        $this->assertStringContainsString('appendNameOverlay', $js);
        $this->assertStringContainsString('room-name-overlay', $js);
        $this->assertStringContainsString('selectArea(area.id, { fromPin: true })', $js);
        $this->assertStringContainsString('area?.unique_name', $js);
        $this->assertStringContainsString('name.textContent = uniqueName(area)', $js);
        $this->assertStringContainsString('.room-name-overlay', $css);
        $this->assertStringNotContainsString('.room-jump-highlight', $css);
        $this->assertMatchesRegularExpression(
            '/storedJumpTarget\(area\)[\s\S]{0,180}appendNameOverlay\(area, target\.box\)/',
            $js
        );
        $this->assertStringContainsString('Tekening kon niet worden geladen', $js);
        $this->assertMatchesRegularExpression(
            '/loadDrawing\(\)\.catch\([\s\S]*?\)\.finally\(\(\) => \{[\s\S]*selectArea\(selectedId/',
            $js
        );
        $this->assertStringContainsString('pdfRenderTask.cancel()', $js);
    }

    public function test_board_payload_exposes_jump_target_from_stored_marker_id(): void
    {
        Storage::fake('local');
        [$project, $rooms] = $this->makeDuplicateNameProject();
        $room = $rooms[0];

        $payload = app(ProjectBoardService::class)->payload($project->fresh([
            'floors.areas.markers',
            'areas.markers',
            'areas.floor',
            'documents',
            'snags',
            'areas.tasks.workItem',
        ]));

        $summary = collect($payload['areas'])->firstWhere('id', $room['area']->id);
        $marker = $room['area']->markers()->first();

        $this->assertNotNull($summary['jump_target']);
        $this->assertSame($room['area']->id, $summary['jump_target']['project_area_id']);
        $this->assertSame($marker->id, $summary['jump_target']['drawing_marker_id']);
        $this->assertSame($room['page'], $summary['jump_target']['page']);
        $this->assertEqualsWithDelta($room['x'] + 0.04, (float) $summary['jump_target']['center_x'], 0.0001);
        $this->assertEqualsWithDelta($room['y'] + 0.025, (float) $summary['jump_target']['center_y'], 0.0001);
        $this->assertEqualsWithDelta($room['x'], (float) $summary['jump_target']['bbox']['x'], 0.0001);
        $this->assertSame($marker->id, $summary['marker']['id']);
    }

    public function test_detect_links_prefixed_room_numbers_exactly(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Prefixed']);
        $project = Project::query()->create([
            'project_number' => 'P-JUMP-2',
            'customer_id' => $customer->id,
            'name' => 'Prefixed rooms',
            'supervisor_user_id' => $user->id,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $path = 'projects/'.$project->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        $defs = [
            ['P.0.17', 'theorielokaal', 1, 0.22, 0.31],
            ['P.0.18', 'wasruimte', 1, 0.40, 0.55],
            ['P.0.17a', 'kantoor', 2, 0.18, 0.22],
            ['P.0.170', 'berging', 1, 0.70, 0.20],
        ];
        $areas = [];
        foreach ($defs as $index => [$number, $name, $page, $x, $y]) {
            $areas[$number] = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $name,
                'square_meters' => 10 + $index,
                'status' => AreaStatus::NietGestart,
                'sort_order' => $index + 1,
            ]);
        }

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'P.0.17 theorielokaal', 'x' => 0.22, 'y' => 0.31, 'w' => 0.12, 'h' => 0.02],
                    ['page' => 1, 'text' => 'P.0.18 wasruimte', 'x' => 0.40, 'y' => 0.55, 'w' => 0.12, 'h' => 0.02],
                    ['page' => 2, 'text' => 'P.0.17a kantoor', 'x' => 0.18, 'y' => 0.22, 'w' => 0.10, 'h' => 0.02],
                    ['page' => 1, 'text' => 'P.0.170 berging', 'x' => 0.70, 'y' => 0.20, 'w' => 0.10, 'h' => 0.02],
                ],
                'rooms' => [
                    ['number' => 'P.0.17', 'page' => 1, 'x' => 0.22, 'y' => 0.31, 'w' => 0.12, 'h' => 0.02, 'label_text' => 'P.0.17 theorielokaal', 'source' => 'text'],
                    ['number' => 'P.0.18', 'page' => 1, 'x' => 0.40, 'y' => 0.55, 'w' => 0.12, 'h' => 0.02, 'label_text' => 'P.0.18 wasruimte', 'source' => 'text'],
                    ['number' => 'P.0.17a', 'page' => 2, 'x' => 0.18, 'y' => 0.22, 'w' => 0.10, 'h' => 0.02, 'label_text' => 'P.0.17a kantoor', 'source' => 'text'],
                    ['number' => 'P.0.170', 'page' => 1, 'x' => 0.70, 'y' => 0.20, 'w' => 0.10, 'h' => 0.02, 'label_text' => 'P.0.170 berging', 'source' => 'text'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 4);

        $theory = AreaDrawingMarker::query()->where('project_area_id', $areas['P.0.17']->id)->first();
        $wash = AreaDrawingMarker::query()->where('project_area_id', $areas['P.0.18']->id)->first();
        $office = AreaDrawingMarker::query()->where('project_area_id', $areas['P.0.17a']->id)->first();
        $storage = AreaDrawingMarker::query()->where('project_area_id', $areas['P.0.170']->id)->first();

        $this->assertNotNull($theory);
        $this->assertSame(1, $theory->page);
        $this->assertEqualsWithDelta(0.22, (float) $theory->x, 0.0001);
        $this->assertSame(1, $wash->page);
        $this->assertEqualsWithDelta(0.40, (float) $wash->x, 0.0001);
        $this->assertSame(2, $office->page);
        $this->assertEqualsWithDelta(0.18, (float) $office->x, 0.0001);
        $this->assertSame(1, $storage->page);
        $this->assertEqualsWithDelta(0.70, (float) $storage->x, 0.0001);
        $this->assertNotNull($theory->jumpTarget());
        $this->assertSame($theory->id, $theory->jumpTarget()['drawing_marker_id']);
    }

    /**
     * @return array{0: Project, 1: list<array{area: ProjectArea, name: string, page: int, x: float, y: float}>}
     */
    private function makeDuplicateNameProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Griftland']);
        $project = Project::query()->create([
            'project_number' => 'P-JUMP-1',
            'customer_id' => $customer->id,
            'name' => 'Griftland jump test',
            'supervisor_user_id' => $user->id,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $path = 'projects/'.$project->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        $defs = [
            ['0.15', 'instructieruimte', 1, 0.20, 0.30],
            ['0.16', 'instructieruimte', 1, 0.55, 0.32],
            ['1.01', 'kleedkamer', 2, 0.18, 0.40],
            ['1.02', 'kleedkamer', 2, 0.62, 0.44],
            ['0.40', 'berging', 1, 0.10, 0.70],
            ['0.41', 'berging', 1, 0.72, 0.68],
            ['0.50', 'kunstplein', 1, 0.40, 0.50],
            ['0.60', 'tekenlokaal', 1, 0.80, 0.20],
        ];

        $rooms = [];
        foreach ($defs as $index => [$number, $name, $page, $x, $y]) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $name,
                'square_meters' => 12 + $index,
                'status' => AreaStatus::NietGestart,
                'sort_order' => $index + 1,
            ]);
            AreaDrawingMarker::query()->create([
                'project_area_id' => $area->id,
                'project_document_id' => $document->id,
                'page' => $page,
                'x' => $x,
                'y' => $y,
                'width' => 0.08,
                'height' => 0.05,
                'label_text' => $number.' '.$name,
                'polygon' => [
                    ['x' => $x, 'y' => $y],
                    ['x' => $x + 0.08, 'y' => $y],
                    ['x' => $x + 0.08, 'y' => $y + 0.05],
                    ['x' => $x, 'y' => $y + 0.05],
                ],
                'confidence' => 1,
                'source' => 'import',
            ]);
            $rooms[] = [
                'area' => $area,
                'name' => $name,
                'page' => $page,
                'x' => $x,
                'y' => $y,
            ];
        }

        return [$project, $rooms];
    }

    /**
     * @return array{0: Project, 1: list<array{area: ProjectArea, name: string, page: int, x: float, y: float}>}
     */
    private function makeUntitledDuplicateProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Griftland']);
        $project = Project::query()->create([
            'project_number' => 'P-UNIQUE-1',
            'customer_id' => $customer->id,
            'name' => 'Griftland unique names',
            'supervisor_user_id' => $user->id,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $path = 'projects/'.$project->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        $defs = [
            ['', 'gang', 1, 0.20, 0.30, true],
            ['', 'gang', 1, 0.45, 0.32, true],
            ['', 'gang', 1, 0.70, 0.34, false],
            ['', 'entree 4', 1, 0.18, 0.60, true],
            ['', 'entree 4', 1, 0.62, 0.64, true],
            ['0.01', 'aula', 1, 0.80, 0.20, true],
        ];

        $rooms = [];
        foreach ($defs as $index => [$number, $name, $page, $x, $y, $linked]) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number !== '' ? $number : null,
                'name' => $name,
                'square_meters' => 10 + $index,
                'status' => AreaStatus::NietGestart,
                'sort_order' => $index + 1,
            ]);
            if ($linked) {
                AreaDrawingMarker::query()->create([
                    'project_area_id' => $area->id,
                    'project_document_id' => $document->id,
                    'page' => $page,
                    'x' => $x,
                    'y' => $y,
                    'width' => 0.08,
                    'height' => 0.05,
                    'label_text' => $name,
                    'polygon' => [
                        ['x' => $x, 'y' => $y],
                        ['x' => $x + 0.08, 'y' => $y],
                        ['x' => $x + 0.08, 'y' => $y + 0.05],
                        ['x' => $x, 'y' => $y + 0.05],
                    ],
                    'confidence' => 1,
                    'source' => 'import',
                ]);
            }
            $rooms[] = [
                'area' => $area,
                'name' => $name,
                'page' => $page,
                'x' => $x,
                'y' => $y,
            ];
        }

        return [$project, $rooms];
    }
}
