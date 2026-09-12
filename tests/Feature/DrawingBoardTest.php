<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\SnagStatus;
use App\Enums\WorkPhase;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\RoomMarkerMatcher;
use App\Services\RoomWorkSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DrawingBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_shows_rooms_and_ondergrond_tasks(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('0.07')
            ->assertSee('groepsruimte')
            ->assertSee('Opslaan en verwerken')
            ->assertSee('Alles aanvinken')
            ->assertSee('Primen & Egaliseren')
            ->assertDontSee('Voorbereiden / schuren')
            ->assertDontSee('Ondergrond / egaliseren')
            ->assertDontSee('Primeren')
            ->assertSee('Marmoleum')
            ->assertSee('Linoleum')
            ->assertSee('Hele werk')
            ->assertSee('Deze verdieping')
            ->assertSee('Ruimtes selecteren')
            ->assertSee('Selectie bekijken')
            ->assertSee('Wis selectie')
            ->assertSee('Geselecteerde ruimtes')
            ->assertSee('Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype.')
            ->assertSee('tik om extra aan te vinken')
            ->assertDontSee('id="draw-link"', false)
            ->assertSee('Positie aanpassen')
            ->assertDontSee('id="edit-area-number"', false)
            ->assertSee('Niets gedaan = niets extra')
            ->assertSee('E✓ V✓ P✓ = gereed onderdeel onder de kamernaam')
            ->assertSee('Geselecteerde ruimte')
            ->assertSee('data-kind="ondergrond"', false)
            ->assertSee('data-kind="linoleum"', false)
            ->assertSee('id="work-legend"', false)
            ->assertSee('Open')
            ->assertSee('Toegewezen')
            ->assertSee('In behandeling')
            ->assertSee('Gereed gemeld')
            ->assertSee('Afgehandeld')
            ->assertDontSee('Goedgekeurd')
            ->assertDontSee('PDF-debug')
            ->assertDontSee('Gevonden kamernamen')
            ->assertSee('Voortgang')
            ->assertSee('Opleverpunten')
            ->assertSee('id="draw-work"', false)
            ->assertSee('Materialen kiezen')
            ->assertSee('Alle zichtbare')
            ->assertSee('id="pick-work-rooms"', false)
            ->assertSee('id="draw-work-panel"', false)
            ->assertSee('Alles selecteren')
            ->assertSee('Wis selectie')
            ->assertSee('Toepassen')
            ->assertSee('id="room-count-label"', false)
            ->assertSee('id="snag-status"', false)
            ->assertSee('for="snag-status"', false)
            ->assertSee('Annuleren')
            ->assertSee('placeholder="Aantal"', false)
            ->assertDontSee('id="complete-hours"', false)
            ->assertDontSee('placeholder="Uren"', false)
            ->assertDontSee('Tekstpositie corrigeren');
    }

    public function test_planner_sees_progress_read_only_without_input_fields(): void
    {
        Storage::fake('local');
        [, $project] = $this->makeProject();
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('is-readonly', false)
            ->assertSee('Alleen ter inzage')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('id="draw-work"', false)
            ->assertSee('Materialen kiezen')
            ->assertSee('id="draw-work-panel"', false)
            ->assertSee('Wis selectie')
            ->assertSee('Toepassen')
            ->assertDontSee('id="complete-form"', false)
            ->assertDontSee('id="complete-worker"', false)
            ->assertDontSee('id="complete-submit"', false)
            ->assertDontSee('id="select-all-tasks"', false)
            ->assertDontSee('Opslaan en verwerken')
            ->assertDontSee('Alles aanvinken')
            ->assertDontSee('Hele werk')
            ->assertDontSee('Deze verdieping')
            ->assertDontSee('id="pick-all-rooms"', false)
            ->assertDontSee('id="pick-work-rooms"', false)
            ->assertDontSee('Alle zichtbare')
            ->assertDontSee('placeholder="Aantal"', false)
            ->assertDontSee('Zelfde vakman? Vink extra ruimtes en onderdelen aan.')
            ->assertDontSee('Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype.')
            ->assertDontSee('tik om extra aan te vinken');
    }

    public function test_uitvoerder_still_sees_the_progress_form(): void
    {
        Storage::fake('local');
        [, $project] = $this->makeProject();
        $uitvoerder = User::factory()->uitvoerder()->create();

        $this->actingAs($uitvoerder)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="complete-form"', false)
            ->assertSee('Opslaan en verwerken')
            ->assertSee('Alles aanvinken')
            ->assertSee('Hele werk')
            ->assertSee('Deze verdieping')
            ->assertDontSee('Alleen ter inzage')
            ->assertDontSee('is-readonly', false);
    }

    public function test_drawing_toolbar_lists_work_types_per_room(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $coating = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PU Coating',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
            'sort_order' => 20,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $coating->id,
            'ordered_quantity' => 10,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="draw-work"', false)
            ->assertSee('Materialen kiezen')
            ->assertSee('Alle zichtbare')
            ->getContent();

        $this->assertStringContainsString('data-works=', $html);
        $this->assertStringContainsString('id="room-count-label"', $html);
        $this->assertStringContainsString('id="draw-work-qty"', $html);
        $this->assertStringContainsString('id="room-work-qty"', $html);
        $this->assertStringContainsString('class="draw-work-qty"', $html);
        $this->assertStringContainsString('data-work-all', $html);
        $this->assertStringContainsString('data-work-key=', $html);
        $this->assertStringContainsString('id="draw-work-toggle"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('id="draw-work-panel"', $html);
        $this->assertStringContainsString('id="draw-work-list"', $html);
        $this->assertStringContainsString('id="draw-work-apply"', $html);
        $this->assertStringContainsString('id="outsource-selection-data"', $html);
        $this->assertTrue(
            (bool) preg_match('/data-works="[^"]*ondergrond[^"]*"/', $html),
            'Elke ruimte moet haar onderdelen in de lijst zetten zodat het filter ze kan verbergen.',
        );

        $this->assertStringContainsString('Alles selecteren', $html);
        $this->assertStringContainsString('Wis selectie', $html);
        $this->assertStringContainsString('Toepassen', $html);

        $this->assertSame(1, preg_match('/id="board-data">([^<]*)<\/script>/', $html, $matches));
        $board = json_decode($matches[1], true);
        $filters = collect($board['work_filters'] ?? []);
        $this->assertTrue($filters->contains(fn (array $work) => $work['key'] === 'ondergrond' && $work['label'] === 'Primen & Egaliseren'));
        $this->assertTrue($filters->contains(fn (array $work) => str_contains(mb_strtolower($work['label']), 'coating')));
        $this->assertSame('ondergrond', $filters->first()['key'] ?? null);

        $first = collect($board['areas'])->firstWhere('number', '0.07');
        $second = collect($board['areas'])->firstWhere('number', '0.09');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $firstKeys = collect($first['works'] ?? [])->pluck('key');
        $secondKeys = collect($second['works'] ?? [])->pluck('key');
        $this->assertTrue($firstKeys->contains('ondergrond'));
        $this->assertTrue($secondKeys->contains('ondergrond'));
        $this->assertTrue($firstKeys->contains(fn (string $key) => str_contains($key, 'vloer')));
        $coatingWork = collect($first['works'] ?? [])->first(
            fn (array $work) => str_contains(mb_strtolower((string) ($work['label'] ?? '')), 'coating')
        );
        $this->assertNotNull($coatingWork);
        $coatingKey = $coatingWork['key'] ?? null;
        $this->assertFalse($secondKeys->contains($coatingKey));
        $ondergrond = collect($first['works'] ?? [])->firstWhere('key', 'ondergrond');
        $secondOndergrond = collect($second['works'] ?? [])->firstWhere('key', 'ondergrond');
        $this->assertSame(50.97, (float) ($ondergrond['quantity'] ?? 0));
        $this->assertSame('m2', $ondergrond['unit'] ?? null);
        $this->assertSame(59.0, (float) ($secondOndergrond['quantity'] ?? 0));
        $this->assertSame(10.0, (float) ($coatingWork['quantity'] ?? 0));
        $this->assertSame('m2', $coatingWork['unit'] ?? null);
    }

    public function test_drawing_lists_each_pvc_product_as_its_own_onderdeel(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $floor = $project->floors()->first();
        $taraflex = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Taraflex multi-use, 6350 light cherry 6,3 mm, n.t.b., PVC / Vinyl',
            'unit' => 'm2',
            'ordered_quantity' => 110.37,
            'status' => 'gepland',
            'sort_order' => 20,
        ]);
        $chapman = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'IVC Ultimo Chapman Oak, 24245, PVC - LVT',
            'unit' => 'm2',
            'ordered_quantity' => 32.65,
            'status' => 'gepland',
            'sort_order' => 21,
        ]);
        $oefen = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.62',
            'name' => 'oefenruimte',
            'square_meters' => 83.65,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $oefen->id,
            'work_item_id' => $taraflex->id,
            'ordered_quantity' => 83.65,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);
        $podo = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.56',
            'name' => 'podo therapie',
            'square_meters' => 26.43,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $podo->id,
            'work_item_id' => $chapman->id,
            'ordered_quantity' => 26.43,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Taraflex')
            ->assertSee('Chapman Oak')
            ->getContent();

        $this->assertSame(1, preg_match('/id="board-data">([^<]*)<\/script>/', $html, $matches));
        $board = json_decode($matches[1], true);
        $labels = collect($board['work_filters'] ?? [])->pluck('label');
        $this->assertTrue($labels->contains(fn (string $label) => str_contains($label, 'Taraflex')));
        $this->assertTrue($labels->contains(fn (string $label) => str_contains($label, 'Chapman Oak')));
        $taraflexKey = collect($board['areas'])
            ->firstWhere('number', '1.62')['works'] ?? [];
        $chapmanKey = collect($board['areas'])
            ->firstWhere('number', '1.56')['works'] ?? [];
        $taraflexWork = collect($taraflexKey)->first(fn (array $work) => str_contains((string) ($work['label'] ?? ''), 'Taraflex'));
        $chapmanWork = collect($chapmanKey)->first(fn (array $work) => str_contains((string) ($work['label'] ?? ''), 'Chapman'));
        $this->assertNotNull($taraflexWork);
        $this->assertNotNull($chapmanWork);
        $this->assertNotSame($taraflexWork['key'] ?? null, $chapmanWork['key'] ?? null);
    }

    public function test_drawing_filters_rooms_by_floor_and_onderdeel(): void
    {
        $js = file_get_contents(resource_path('js/drawing-board.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("getElementById('draw-work')", $js);
        $this->assertStringContainsString('function applyRoomFilters', $js);
        $this->assertStringContainsString('function pickWorkGroup', $js);
        $this->assertStringContainsString('function areaMatchesWork', $js);
        $this->assertStringContainsString('function selectedFloorName', $js);
        $this->assertStringContainsString('function matchingAreas', $js);
        $this->assertStringContainsString('function selectedWorkMeasure', $js);
        $this->assertStringContainsString('function refreshWorkQuantity', $js);
        $this->assertStringContainsString('function hasWorkFilter', $js);
        $this->assertStringContainsString('workFilterKeys', $js);
        $this->assertStringContainsString('[data-work-key]', $js);
        $this->assertStringContainsString('outsource-selection-data', $js);
        $this->assertStringContainsString('buildOutsourceSelection', $js);
        $this->assertStringContainsString('shortWorkLabel', $js);
        $this->assertStringContainsString('groupedWorkFilters', $js);
        $this->assertStringContainsString('floorAreas', $js);
        $this->assertStringContainsString("querySelectorAll('.draw-work-qty')", $js);
        $this->assertStringContainsString('function prunePickedToFilter', $js);
        $this->assertStringContainsString('document.body.appendChild', $js);
        $this->assertStringContainsString("classList.add('is-open')", $js);
        $this->assertStringContainsString('aria-expanded', $js);
        $this->assertStringContainsString('Alles aanvinken', $js);
        $this->assertStringContainsString('is-work-filter', $js);
        $this->assertStringContainsString('is-filtered-out', $js);
        $this->assertStringContainsString('.room-name-overlay.is-filtered-out', $css);
        $this->assertStringContainsString('.status-badge.is-filtered-out', $css);
        $this->assertStringContainsString('.board-left.is-work-filter', $css);
        $this->assertStringContainsString('.draw-work-qty', $css);
        $this->assertStringContainsString('.draw-work-menu', $css);
        $this->assertStringContainsString('.draw-work-panel', $css);
        $this->assertStringContainsString('.draw-work-panel.is-open', $css);
        $this->assertStringContainsString('max-height: 400px', $css);
        $this->assertStringContainsString('#dc2626', $css);
    }

    public function test_drawing_board_selects_rooms_for_meetstaat_totals(): void
    {
        $js = file_get_contents(resource_path('js/drawing-board.js'));
        $css = file_get_contents(resource_path('css/app.css'));
        $view = file_get_contents(resource_path('views/projects/show.blade.php'));

        $this->assertStringContainsString("getElementById('pick-rooms-btn')", $js);
        $this->assertStringContainsString('function setRoomMeasureMode', $js);
        $this->assertStringContainsString('function toggleMeasuredRoom', $js);
        $this->assertStringContainsString('measureSelectedRooms', $js);
        $this->assertStringContainsString('hitTestContours', $js);
        $this->assertStringContainsString('roomContour', $js);
        $this->assertStringContainsString("source: 'rooms'", $js);
        $this->assertStringContainsString('room-measure-shape', $js);
        $this->assertStringContainsString('Ruimtes selecteren', $view);
        $this->assertStringContainsString('Selectie bekijken', $view);
        $this->assertStringContainsString('Wis selectie', $view);
        $this->assertStringContainsString('id="room-measure-panel"', $view);
        $this->assertStringContainsString('#draw-hit .room-measure-shape', $css);
        $this->assertStringContainsString('#draw-hit .room-measure-shape.is-on', $css);
        $this->assertStringContainsString('.room-measure-btn.is-on', $css);
        $this->assertStringNotContainsString('.room-select-fill', $css);
        $this->assertStringNotContainsString('Selectie uitbesteden', $view);
        $this->assertStringNotContainsString('Selectie uitbesteden', $js);
    }

    public function test_progress_form_shows_the_team_name_instead_of_the_company(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $team = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'people_count' => 2,
            'active' => true,
        ]);
        $this->assignToProject($project, $team);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/id="complete-worker"[^>]*>.*?<\/select>/s', $html, $select));
        $this->assertStringContainsString('>Team Wespro</option>', $select[0]);
        $this->assertStringNotContainsString('>Harm Wesselink</option>', $select[0]);
    }

    public function test_progress_form_lists_only_workers_planned_on_the_project(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $team = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Wepro Bouw',
            'active' => true,
        ]);
        $this->assignToProject($project, $team);
        Worker::query()->create([
            'name' => 'Piet de Vries',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/id="complete-worker"[^>]*>.*?<\/select>/s', $html, $select));
        $this->assertStringContainsString('>Albert</option>', $select[0]);
        $this->assertStringContainsString('>Wepro</option>', $select[0]);
        $this->assertStringNotContainsString('>Piet de Vries</option>', $select[0]);
        $this->assertStringNotContainsString('Niemand ingepland op dit werk', $select[0]);
    }

    public function test_progress_rejects_a_worker_who_is_not_planned_on_the_project(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $outsider = Worker::query()->create([
            'name' => 'Piet de Vries',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $task = $area->tasks()->first();

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$task->id],
                'worker_id' => $outsider->id,
                'date' => '2026-09-09',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('worker_id');

        $this->assertNull($task->fresh()->completed_by);
    }

    public function test_completed_work_shows_the_team_name(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $team = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'active' => true,
        ]);
        $this->assignToProject($project, $team);
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();

        $this->actingAs($user)
            ->postJson(route('projects.areas.group', [$project, $area]), [
                'group' => 'ondergrond',
                'worker_id' => $team->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.tasks.0.worker', 'Team Wespro');
    }

    public function test_selected_room_shows_a_green_bar_on_the_drawing(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.room-focus-bar', $css);
        $this->assertStringContainsString('.room-row.is-picked', $css);
        $this->assertStringContainsString('.room-focus-bar.is-extra', $css);
        $this->assertStringContainsString('.room-selection-outline', $css);
        $this->assertMatchesRegularExpression('/\.room-selection-outline\s*\{[^}]*display:\s*none/s', $css);
        $this->assertStringNotContainsString('.room-select-fill', $css);
        $this->assertMatchesRegularExpression('/\.room-focus-bar\s*\{[^}]*background:\s*var\(--color-nicon-ok\)/s', $css);
        $this->assertMatchesRegularExpression('/\.room-focus-bar\s*\{[^}]*color:\s*#fff/s', $css);
        $this->assertStringNotContainsString('.room-label.is-selected', $css);
        $this->assertStringContainsString('.status-mark.kind-ondergrond', $css);
        $this->assertStringContainsString('.status-mark.kind-linoleum', $css);
        $this->assertStringContainsString('.status-mark.kind-plinten', $css);
        $this->assertStringContainsString('.status-mark.is-provisional', $css);
    }

    public function test_voortgang_layer_does_not_draw_opleverpunten(): void
    {
        $js = file_get_contents(resource_path('js/drawing-board.js'));

        $this->assertStringContainsString('const showSnags = layerShowsSnags(layer);', $js);
        $this->assertStringContainsString('function togglePickedRoom', $js);
        $this->assertStringContainsString('function workSaveError', $js);
        $this->assertStringContainsString('processMany', $js);
        $this->assertStringContainsString('approveMany', $js);
        $this->assertDoesNotMatchRegularExpression("/showSnags = layer !== 'rooms' \\|\\|/", $js);
    }

    public function test_ondergrond_is_one_onderdeel(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $names = $area->tasks->map(fn (AreaTask $task) => $task->workItem?->name);

        $this->assertTrue($names->contains('Primen & Egaliseren'));
        $this->assertFalse($names->contains('Voorbereiden / schuren'));
        $this->assertFalse($names->contains('Primeren'));
        $this->assertCount(1, $area->tasks->filter(fn (AreaTask $task) => $task->phase()->group() === 'ondergrond'));
    }

    public function test_floor_card_shows_laid_material_and_splits_types(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $mat = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Coral Bright, Entreemat',
            'unit' => 'm2',
            'ordered_quantity' => 4,
            'status' => 'gepland',
            'sort_order' => 15,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $mat->id,
            'ordered_quantity' => 4,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area->fresh(['tasks.workItem'])]))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Marmoleum Real, Linoleum'])
            ->assertJsonFragment(['label' => 'Coral Bright, Entreemat'])
            ->assertJsonMissing(['label' => 'Vloer']);
    }

    public function test_existing_ondergrond_steps_are_merged_into_one(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $primer = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primeren',
            'unit' => 'm2',
            'ordered_quantity' => 50.97,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $primer->id,
            'ordered_quantity' => 50.97,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $names = $area->fresh(['tasks.workItem'])->tasks->map(fn (AreaTask $task) => $task->workItem?->name);
        $this->assertFalse($names->contains('Primeren'));
        $this->assertFalse($names->contains('Voorbereiden / schuren'));
        $this->assertTrue($names->contains('Primen & Egaliseren'));
        $this->assertCount(1, $area->fresh(['tasks.workItem'])->tasks->filter(fn (AreaTask $task) => $task->phase()->group() === 'ondergrond'));
    }

    public function test_completing_ondergrond_group_updates_drawing_status(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();

        $this->actingAs($user)
            ->postJson(route('projects.areas.group', [$project, $area]), [
                'group' => 'ondergrond',
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'partial')
            ->assertJsonPath('area.status_label', 'Deels gereed')
            ->assertJsonPath('area.progress', '1/2')
            ->assertJsonPath('area.phases.egaliseren', true)
            ->assertJsonPath('groups.0.done', true);

        $this->assertSame(AreaStatus::InUitvoering, $area->fresh()->status);
    }

    public function test_area_detail_shows_ordered_completed_and_remaining_square_meters(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $vloer = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Vloer);

        $before = $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->assertOk()
            ->json('groups');
        $vloerGroup = collect($before)->first(fn (array $group) => in_array($vloer->id, $group['task_ids'], true));
        $this->assertNotNull($vloerGroup);
        $this->assertSame('Opdracht 50,97 | Gereed 0,00 | Rest 50,97 m²', $vloerGroup['progress_label']);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$vloer->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk();

        $after = $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area->fresh()]))
            ->json('groups');
        $vloerGroup = collect($after)->first(fn (array $group) => in_array($vloer->id, $group['task_ids'], true));
        $this->assertSame('Opdracht 50,97 | Gereed 50,97 | Rest 0,00 m²', $vloerGroup['progress_label']);
        $this->assertTrue($vloerGroup['done']);
    }

    public function test_processing_selected_tasks_on_0_07_keeps_gereed_after_reload(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $plint = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'unit' => 'm1',
            'ordered_quantity' => 25.31,
            'status' => 'gepland',
            'sort_order' => 20,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $plint->id,
            'ordered_quantity' => 25.31,
            'unit' => 'm1',
            'status' => 'niet_gestart',
        ]);
        $area = $area->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
        $vloer = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Vloer);
        $plinten = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Plinten);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id, $vloer->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('area.number', '0.07')
            ->assertJsonPath('area.progress', '2/3')
            ->assertJsonPath('area.status_label', 'Deels gereed')
            ->assertJsonPath('area.tone', 'partial')
            ->assertJsonPath('groups.0.done', true)
            ->assertJsonPath('groups.0.label', 'Primen & Egaliseren')
            ->assertJsonPath('groups.1.done', true)
            ->assertJsonPath('groups.1.label', 'Marmoleum Real, Linoleum')
            ->assertJsonPath('groups.2.done', false)
            ->assertJsonPath('groups.2.label', 'Plinten wit')
            ->assertJsonPath('groups.0.color_key', 'ondergrond')
            ->assertJsonPath('groups.1.color_key', 'linoleum')
            ->assertJsonPath('groups.2.color_key', 'plinten')
            ->assertJsonPath('area.dots.0.key', 'ondergrond')
            ->assertJsonPath('area.dots.1.key', 'linoleum')
            ->assertJsonCount(2, 'area.dots')
            ->assertJsonPath('area.phases.egaliseren', true)
            ->assertJsonPath('area.phases.vloer', true)
            ->assertJsonPath('area.phases.plinten', false);

        $this->assertSame(AreaStatus::Gereed, $primen->fresh()->status);
        $this->assertSame(AreaStatus::Gereed, $vloer->fresh()->status);
        $this->assertSame(AreaStatus::NietGestart, $plinten->fresh()->status);
        $this->assertDatabaseCount('work_progress_entries', 2);

        $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->assertOk()
            ->assertJsonPath('area.progress', '2/3')
            ->assertJsonPath('area.status_label', 'Deels gereed')
            ->assertJsonPath('groups.0.done', true)
            ->assertJsonPath('groups.1.done', true)
            ->assertJsonPath('groups.2.done', false)
            ->assertJsonCount(2, 'area.dots');

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$plinten->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'done')
            ->assertJsonCount(3, 'area.dots')
            ->assertJsonPath('area.dots.0.key', 'ondergrond')
            ->assertJsonPath('area.dots.1.key', 'linoleum')
            ->assertJsonPath('area.dots.2.key', 'plinten')
            ->assertJsonPath('area.phases.egaliseren', true)
            ->assertJsonPath('area.phases.vloer', true)
            ->assertJsonPath('area.phases.plinten', true);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Gereed')
            ->assertSee('Plinten wit');
    }

    public function test_partial_quantity_does_not_mark_task_gereed(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
                'quantity' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.done', false)
            ->assertJsonPath('groups.0.partial', true);

        $this->assertSame(AreaStatus::InUitvoering, $primen->fresh()->status);
        $this->assertEqualsWithDelta(40.97, $primen->fresh()->remainingQuantity(), 0.01);
    }

    public function test_zero_quantity_marks_the_whole_remaining_done(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
                'quantity' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.done', true)
            ->assertJsonPath('groups.0.label', 'Primen & Egaliseren');

        $this->assertSame(AreaStatus::Gereed, $primen->fresh()->status);
        $this->assertEqualsWithDelta(0, $primen->fresh()->remainingQuantity(), 0.01);
    }

    public function test_gereed_group_can_be_turned_off_again(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.done', true);

        $this->assertSame(AreaStatus::Gereed, $primen->fresh()->status);
        $this->assertDatabaseCount('work_progress_entries', 1);

        $this->actingAs($user)
            ->postJson(route('projects.areas.reopen', [$project, $area]), [
                'task_ids' => [$primen->id],
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.done', false)
            ->assertJsonPath('groups.0.partial', false)
            ->assertJsonPath('area.tone', 'open')
            ->assertJsonPath('area.status_label', 'Open');

        $this->assertSame(AreaStatus::NietGestart, $primen->fresh()->status);
        $this->assertNull($primen->fresh()->completed_by);
        $this->assertDatabaseCount('work_progress_entries', 0);
        $this->assertSame('gepland', $primen->fresh(['workItem'])->workItem->status);
    }

    public function test_processing_the_same_task_twice_does_not_double_count(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('groups.0.done', true);

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$primen->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('work_progress_entries', 1);
        $this->assertEqualsWithDelta(0, $primen->fresh()->remainingQuantity(), 0.01);
    }

    public function test_process_requires_selected_tasks(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.07')->first();

        $this->actingAs($user)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertStatus(422);
    }

    public function test_same_onderdeel_on_multiple_rooms_is_marked_gereed_for_one_vakman(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $first = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $second = $project->areas()->where('area_number', '0.09')->first()->fresh(['tasks.workItem']);
        $primenFirst = $first->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
        $primenSecond = $second->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.work.process', $project), [
                'task_ids' => [$primenFirst->id, $primenSecond->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('areas.0.area.number', '0.07')
            ->assertJsonPath('areas.0.area.progress', '1/2')
            ->assertJsonPath('areas.0.area.tone', 'partial')
            ->assertJsonPath('areas.0.groups.0.done', true)
            ->assertJsonPath('areas.0.groups.0.label', 'Primen & Egaliseren')
            ->assertJsonPath('areas.1.area.number', '0.09')
            ->assertJsonPath('areas.1.area.progress', '1/2')
            ->assertJsonPath('areas.1.groups.0.done', true);

        $this->assertSame(AreaStatus::Gereed, $primenFirst->fresh()->status);
        $this->assertSame(AreaStatus::Gereed, $primenSecond->fresh()->status);
        $this->assertSame($worker->id, $primenFirst->fresh()->completed_by);
        $this->assertSame($worker->id, $primenSecond->fresh()->completed_by);
        $this->assertDatabaseCount('work_progress_entries', 2);
    }

    public function test_same_onderdeel_on_multiple_rooms_can_be_reopened(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $first = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $second = $project->areas()->where('area_number', '0.09')->first()->fresh(['tasks.workItem']);
        $primenFirst = $first->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
        $primenSecond = $second->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.work.process', $project), [
                'task_ids' => [$primenFirst->id, $primenSecond->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('projects.work.reopen', $project), [
                'task_ids' => [$primenFirst->id, $primenSecond->id],
            ])
            ->assertOk()
            ->assertJsonPath('areas.0.groups.0.done', false)
            ->assertJsonPath('areas.0.area.tone', 'open')
            ->assertJsonPath('areas.1.groups.0.done', false)
            ->assertJsonPath('areas.1.area.tone', 'open');

        $this->assertSame(AreaStatus::NietGestart, $primenFirst->fresh()->status);
        $this->assertSame(AreaStatus::NietGestart, $primenSecond->fresh()->status);
        $this->assertDatabaseCount('work_progress_entries', 0);
    }

    public function test_bulk_process_rejects_tasks_from_another_project(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $own = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $own->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreignArea = ProjectArea::query()->create([
            'project_id' => $other->id,
            'area_number' => '9.99',
            'name' => 'vreemde ruimte',
            'square_meters' => 10,
            'status' => 'niet_gestart',
        ]);
        $foreignWork = WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'Marmoleum Real',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        $foreignTask = AreaTask::query()->create([
            'project_area_id' => $foreignArea->id,
            'work_item_id' => $foreignWork->id,
            'ordered_quantity' => 10,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.work.process', $project), [
                'task_ids' => [$primen->id, $foreignTask->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Een of meer werkzaamheden horen niet bij dit project.');

        $this->assertSame(AreaStatus::NietGestart, $primen->fresh()->status);
        $this->assertSame(AreaStatus::NietGestart, $foreignTask->fresh()->status);
        $this->assertDatabaseCount('work_progress_entries', 0);
    }

    public function test_bulk_process_requires_selected_tasks(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('projects.work.process', $project), [
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertStatus(422);
    }

    public function test_unauthenticated_bulk_process_returns_401(): void
    {
        Storage::fake('local');
        [, $project, $worker] = $this->makeProject();

        $this->postJson(route('projects.work.process', $project), [
            'task_ids' => [1],
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
        ])->assertUnauthorized();
    }

    public function test_board_loads_work_groups_for_multiple_rooms_in_one_request(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $first = $project->areas()->where('area_number', '0.07')->first();
        $second = $project->areas()->where('area_number', '0.09')->first();

        $this->actingAs($user)
            ->postJson(route('projects.areas.details', $project), [
                'area_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'areas')
            ->assertJsonPath('areas.0.area.id', $first->id)
            ->assertJsonPath('areas.1.area.id', $second->id)
            ->assertJsonPath('areas.0.groups.0.label', 'Primen & Egaliseren')
            ->assertJsonPath('areas.1.groups.0.label', 'Primen & Egaliseren');
    }

    public function test_room_details_batch_rejects_rooms_from_another_project(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $own = $project->areas()->where('area_number', '0.07')->first();
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreign = ProjectArea::query()->create([
            'project_id' => $other->id,
            'area_number' => '9.99',
            'name' => 'vreemde ruimte',
            'square_meters' => 10,
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.areas.details', $project), [
                'area_ids' => [$own->id, $foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Een of meer ruimtes horen niet bij dit project.');
    }

    public function test_unauthenticated_room_details_batch_returns_401(): void
    {
        Storage::fake('local');
        [, $project] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.07')->first();

        $this->postJson(route('projects.areas.details', $project), [
            'area_ids' => [$area->id],
        ])->assertUnauthorized();
    }

    public function test_completing_primen_egaliseren_marks_the_onderdeel_done(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        app(RoomWorkSetup::class)->ensureProject($project);
        $area = $project->areas()->where('area_number', '0.07')->first();
        $task = $area->tasks()->get()->first(fn (AreaTask $item) => $item->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.tasks.complete', [$project, $task]), [
                'worker_id' => $worker->id,
                'date' => '2026-09-02',
            ])
            ->assertOk()
            ->assertJsonPath('area.progress', '1/2')
            ->assertJsonPath('area.phases.egaliseren', true)
            ->assertJsonPath('groups.0.done', true)
            ->assertJsonPath('groups.0.label', 'Primen & Egaliseren');
    }

    public function test_auto_detect_saves_normalized_markers(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '0.07', 'x' => 0.22, 'y' => 0.41],
                    ['page' => 1, 'text' => '0.09', 'x' => 0.40, 'y' => 0.38],
                    ['page' => 1, 'text' => '0.12', 'x' => 0.55, 'y' => 0.44],
                    ['page' => 1, 'text' => '0.19a', 'x' => 0.61, 'y' => 0.52],
                    ['page' => 1, 'text' => '0.24', 'x' => 0.70, 'y' => 0.33],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 5);

        $this->assertDatabaseHas('area_drawing_markers', [
            'project_document_id' => $document->id,
            'page' => 1,
        ]);
        $marker = AreaDrawingMarker::query()->whereHas('area', fn ($q) => $q->where('area_number', '0.07'))->first();
        $this->assertEqualsWithDelta(0.22, (float) $marker->x, 0.0001);
        $this->assertEqualsWithDelta(0.41, (float) $marker->y, 0.0001);
    }

    public function test_detect_matches_ground_floor_room_labels(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.13' => ['algemeen', 22],
        ]);
        $document = $project->plattegrond();

        $response = $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '0.07 groepsruimte', 'x' => 0.18, 'y' => 0.22, 'w' => 0.10, 'h' => 0.016],
                    ['page' => 1, 'text' => '0.09 groepsruimte', 'x' => 0.36, 'y' => 0.21, 'w' => 0.10, 'h' => 0.016],
                    ['page' => 1, 'text' => '0.12 schoolleiding', 'x' => 0.54, 'y' => 0.23, 'w' => 0.11, 'h' => 0.016],
                    ['page' => 1, 'text' => '0.13 algemeen', 'x' => 0.54, 'y' => 0.40, 'w' => 0.09, 'h' => 0.016],
                    ['page' => 1, 'text' => '0.19a administratie', 'x' => 0.70, 'y' => 0.33, 'w' => 0.12, 'h' => 0.016],
                    ['page' => 1, 'text' => '00.0.077 gr grooeepspsrruuiimmtete', 'x' => 0.18, 'y' => 0.60, 'w' => 0.16, 'h' => 0.018],
                ],
            ])
            ->assertOk();

        $numbers = collect($response->json('matches'))->pluck('number');
        $this->assertTrue($numbers->contains('0.07'));
        $this->assertTrue($numbers->contains('0.09'));
        $this->assertTrue($numbers->contains('0.12'));
        $this->assertTrue($numbers->contains('0.13'));
        $this->assertTrue($numbers->contains('0.19a'));

        $match07 = collect($response->json('matches'))->firstWhere('number', '0.07');
        $this->assertSame('0.07 groepsruimte', $match07['found_text']);
        $this->assertEqualsWithDelta(0.18, $match07['x'], 0.0001);
        $this->assertEqualsWithDelta(0.22, $match07['y'], 0.0001);
        $this->assertEqualsWithDelta(0.10, $this->markerFor($project, '0.07')->width, 0.0001);
        $this->assertEqualsWithDelta(0.70, $this->markerFor($project, '0.19a')->x, 0.0001);
    }

    public function test_detect_uses_text_layer_rooms_and_ocr_only_for_missing_numbers(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $document = $project->plattegrond();

        $response = $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '50.97 m²', 'x' => 0.08, 'y' => 0.25, 'w' => 0.02, 'h' => 0.01, 'source' => 'text'],
                ],
                'rooms' => [
                    ['number' => '0.07', 'page' => 1, 'x' => 0.1148, 'y' => 0.2952, 'w' => 0.0353, 'h' => 0.0061, 'label_text' => '0.07 groepsruimte', 'source' => 'text'],
                    ['number' => '0.09', 'page' => 1, 'x' => 0.2492, 'y' => 0.2985, 'w' => 0.0353, 'h' => 0.0061, 'label_text' => '0.09 groepsruimte', 'source' => 'text'],
                    ['number' => '0.12', 'page' => 1, 'x' => 0.3321, 'y' => 0.3158, 'w' => 0.0352, 'h' => 0.0061, 'label_text' => '0.12 schoolleiding', 'source' => 'text'],
                    ['number' => '0.19a', 'page' => 1, 'x' => 0.4963, 'y' => 0.2881, 'w' => 0.0372, 'h' => 0.0061, 'label_text' => '0.19a administratie', 'source' => 'ocr'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 4);

        $match07 = collect($response->json('matches'))->firstWhere('number', '0.07');
        $match19a = collect($response->json('matches'))->firstWhere('number', '0.19a');
        $this->assertSame('text', $match07['found_via']);
        $this->assertSame('0.07 groepsruimte', $match07['found_text']);
        $this->assertEqualsWithDelta(0.1148, $match07['x'], 0.0001);
        $this->assertEqualsWithDelta(0.2952, $match07['y'], 0.0001);
        $this->assertSame('ocr', $match19a['found_via']);
        $this->assertEqualsWithDelta(0.4963, $match19a['x'], 0.0001);
        $this->assertSame('text', $this->markerFor($project, '0.07')->source);
        $this->assertSame('ocr', $this->markerFor($project, '0.19a')->source);
    }

    public function test_detect_keeps_0_10_leerplein_off_the_sporthal_entree(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.10' => ['leerplein OB', 104.54],
        ]);
        $floorId = $project->areas()->first()->project_floor_id;
        $entree = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floorId,
            'area_number' => '0.10',
            'name' => 'entree',
            'square_meters' => 5.78,
            'status' => 'niet_gestart',
        ]);
        $leerplein = $project->areas()->where('name', 'leerplein OB')->first();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'rooms' => [
                    ['number' => '0.10', 'page' => 1, 'x' => 0.1867, 'y' => 0.4174, 'w' => 0.09, 'h' => 0.018, 'label_text' => '0.10 leerplein OB', 'source' => 'text'],
                    ['number' => '0.10', 'page' => 1, 'x' => 0.2941, 'y' => 0.3940, 'w' => 0.09, 'h' => 0.018, 'label_text' => '0.10 leerplein OB', 'source' => 'text'],
                ],
            ])
            ->assertOk();

        $marker = AreaDrawingMarker::query()->where('project_area_id', $leerplein->id)->first();
        $this->assertNotNull($marker);
        $this->assertSame('0.10 leerplein OB', $marker->label_text);
        $this->assertEqualsWithDelta(0.1867, (float) $marker->x, 0.0001);
        $this->assertEqualsWithDelta(0.4174, (float) $marker->y, 0.0001);
        $this->assertGreaterThanOrEqual(0.08, (float) $marker->width);
        $this->assertDatabaseMissing('area_drawing_markers', [
            'project_area_id' => $entree->id,
        ]);
    }

    public function test_detect_does_not_attach_1_19_to_1_19a_or_1_17(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '1.17' => ['groepsruimte', 42],
            '1.19' => ['groepsruimte', 38],
            '1.19a' => ['berging', 8],
            '1.20' => ['groepsruimte', 40],
        ]);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '1.17 groepsruimte', 'x' => 0.12, 'y' => 0.22],
                    ['page' => 1, 'text' => '1.19', 'x' => 0.40, 'y' => 0.21],
                    ['page' => 1, 'text' => '1.19a', 'x' => 0.41, 'y' => 0.48],
                    ['page' => 1, 'text' => '1.20', 'x' => 0.68, 'y' => 0.23],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 4);

        $this->assertEqualsWithDelta(0.12, $this->markerFor($project, '1.17')->x, 0.0001);
        $this->assertEqualsWithDelta(0.40, $this->markerFor($project, '1.19')->x, 0.0001);
        $this->assertEqualsWithDelta(0.41, $this->markerFor($project, '1.19a')->x, 0.0001);
        $this->assertEqualsWithDelta(0.68, $this->markerFor($project, '1.20')->x, 0.0001);
        $this->assertEqualsWithDelta(0.48, $this->markerFor($project, '1.19a')->y, 0.0001);
        $this->assertNotEqualsWithDelta(0.48, (float) $this->markerFor($project, '1.19')->y, 0.01);
    }

    public function test_area_endpoint_returns_the_clicked_room_not_another(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '2.16' => ["piranha's", 48.12],
            '2.15' => ['roggen', 46.4],
            '2.14' => ['krabben', 44.8],
            '2.24' => ['werkplein', 80],
            '2.03' => ['toiletruimte', 6.25],
        ]);
        $floor2 = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 2,
        ]);
        $document = $project->plattegrond();
        $rooms = [
            '0.07' => 1,
            '2.16' => 2,
            '2.15' => 2,
            '2.14' => 2,
            '2.24' => 2,
            '2.03' => 2,
        ];
        foreach ($rooms as $number => $page) {
            $area = $project->areas()->where('area_number', $number)->first();
            $this->assertNotNull($area, "Verwachtte ruimte {$number}");
            if ($page === 2) {
                $area->update(['project_floor_id' => $floor2->id]);
            }
            AreaDrawingMarker::query()->create([
                'project_area_id' => $area->id,
                'project_document_id' => $document->id,
                'page' => $page,
                'x' => 0.20,
                'y' => 0.30,
                'width' => 0.10,
                'height' => 0.018,
                'label_text' => $area->label(),
                'source' => 'auto',
            ]);
        }

        $clicks = ['2.16', '2.15', '2.14', '2.24', '2.03', '0.07'];
        $previousId = null;
        foreach ($clicks as $number) {
            $area = $project->areas()->where('area_number', $number)->first();
            $response = $this->actingAs($user)
                ->getJson(route('projects.areas.show', [$project, $area]))
                ->assertOk()
                ->assertJsonPath('area.id', $area->id)
                ->assertJsonPath('area.number', $number)
                ->assertJsonPath('area.name', $area->displayName())
                ->assertJsonPath('area.m2_label', number_format((float) $area->square_meters, 2, ',', '.').' m²')
                ->assertJsonPath('area.marker.page', $rooms[$number]);

            $this->assertNotSame($previousId, $response->json('area.id'));
            $this->assertSame($area->id, $response->json('area.id'));
            if ($number !== '0.07') {
                $this->assertNotSame('0.07 groepsruimte', trim($response->json('area.number').' '.$response->json('area.name')));
                $this->assertSame(2, $response->json('area.marker.page'));
                $this->assertSame('1e verdieping', $response->json('area.floor'));
            } else {
                $this->assertSame(1, $response->json('area.marker.page'));
                $this->assertSame('begane grond', $response->json('area.floor'));
            }
            $previousId = $area->id;
        }
    }

    public function test_board_uses_project_area_id_for_list_and_panel(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '2.16' => ["piranha's", 48],
        ]);
        $first = $project->areas()->orderBy('id')->first();
        $piranhas = $project->areas()->where('area_number', '2.16')->first();

        $response = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('data-area-id="'.$first->id.'"', false)
            ->assertSee('data-area-id="'.$piranhas->id.'"', false)
            ->assertSee('data-selected="'.$first->id.'"', false)
            ->assertDontSee('id="room-panel" data-area-id="'.$piranhas->id.'"', false);

        $this->assertMatchesRegularExpression(
            '/id="room-panel"[^>]*data-area-id="'.preg_quote((string) $first->id, '/').'"/',
            $response->getContent()
        );
    }

    public function test_board_keeps_drawing_stage_inside_project_board_grid(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $board = $xpath->query('//*[@id="project-board"]')->item(0);

        $this->assertNotNull($board);
        $this->assertNotNull($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " board-tabs ")]', $board)->item(0));
        $this->assertNotNull($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " board-left ")]', $board)->item(0));
        $this->assertNotNull($xpath->query('.//*[@id="draw-stage"]', $board)->item(0));
        $this->assertNotNull($xpath->query('.//*[@id="room-panel"]', $board)->item(0));
        $this->assertNotNull(
            $xpath->query('.//header[contains(concat(" ", normalize-space(@class), " "), " board-top ")]//nav[contains(concat(" ", normalize-space(@class), " "), " board-tabs ")]', $board)->item(0)
        );
    }

    public function test_area_from_another_project_is_not_returned(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreign = ProjectArea::query()->create([
            'project_id' => $other->id,
            'area_number' => '2.16',
            'name' => "piranha's",
            'square_meters' => 48,
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $foreign]))
            ->assertNotFound();
    }

    public function test_detect_saves_label_click_box_next_to_text(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '1.17' => ['kreeften', 42],
        ]);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '1.17 kreeften', 'x' => 0.19, 'y' => 0.26, 'w' => 0.11, 'h' => 0.017],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.found_text', '1.17 kreeften')
            ->assertJsonPath('matches.0.number', '1.17')
            ->assertJsonPath('matches.0.name', 'kreeften');

        $marker = $this->markerFor($project, '1.17');
        $this->assertEqualsWithDelta(0.19, (float) $marker->x, 0.0001);
        $this->assertEqualsWithDelta(0.26, (float) $marker->y, 0.0001);
        $this->assertEqualsWithDelta(0.11, (float) $marker->width, 0.0001);
        $this->assertSame('1.17 kreeften', $marker->label_text);
    }

    public function test_detect_does_not_share_markers_across_duplicate_room_numbers(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.10' => ['leerplein OB', 54.66],
        ]);
        $hall = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond sporthal',
            'sort_order' => 4,
        ]);
        $leerplein = $project->areas()->where('area_number', '0.10')->where('name', 'leerplein OB')->first();
        $entree = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $hall->id,
            'area_number' => '0.10',
            'name' => 'entree',
            'square_meters' => 5.78,
            'status' => 'niet_gestart',
        ]);
        $document = $project->plattegrond();
        AreaDrawingMarker::query()->create([
            'project_area_id' => $entree->id,
            'project_document_id' => $document->id,
            'page' => 1,
            'x' => 0.1867,
            'y' => 0.4174,
            'width' => 0.08,
            'height' => 0.018,
            'label_text' => '0.10 leerplein OB',
            'source' => 'auto',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '0.10 leerplein OB', 'x' => 0.1867, 'y' => 0.4174, 'w' => 0.08, 'h' => 0.018],
                    ['page' => 4, 'text' => '0.10 entree', 'x' => 0.22, 'y' => 0.31, 'w' => 0.06, 'h' => 0.016],
                ],
                'rooms' => [
                    ['number' => '0.10', 'page' => 1, 'x' => 0.1867, 'y' => 0.4174, 'w' => 0.08, 'h' => 0.016, 'label_text' => '0.10 leerplein OB', 'source' => 'text'],
                ],
            ])
            ->assertOk();

        $leerpleinMarker = AreaDrawingMarker::query()->where('project_area_id', $leerplein->id)->first();
        $entreeMarker = AreaDrawingMarker::query()->where('project_area_id', $entree->id)->first();

        $this->assertNotNull($leerpleinMarker);
        $this->assertEqualsWithDelta(0.1867, (float) $leerpleinMarker->x, 0.0001);
        $this->assertSame(1, $leerpleinMarker->page);
        $this->assertNotNull($entreeMarker);
        $this->assertSame(4, $entreeMarker->page);
        $this->assertEqualsWithDelta(0.22, (float) $entreeMarker->x, 0.0001);
        $this->assertNotEqualsWithDelta((float) $leerpleinMarker->x, (float) $entreeMarker->x, 0.01);
    }

    public function test_room_list_is_sorted_numerically_within_a_floor(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.10' => ['leerplein OB', 54.66],
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSeeInOrder([
                'class="room-num">0.07</span>',
                'class="room-num">0.09</span>',
                'class="room-num">0.10</span>',
                'class="room-num">0.12</span>',
            ], false);
    }

    public function test_manual_position_and_room_label_can_be_corrected_and_survive_refresh(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.08bergin' => ['g', 24.01],
        ]);
        $area = $project->areas()->where('area_number', '0.08bergin')->first();
        $document = $project->plattegrond();

        $this->assertSame('0.08', $area->displayNumber());
        $this->assertSame('bergin', $area->displayName());

        $this->actingAs($user)
            ->postJson(route('projects.areas.marker', [$project, $area]), [
                'document_id' => $document->id,
                'page' => 1,
                'x' => 0.42,
                'y' => 0.31,
                'width' => 0.10,
                'height' => 0.022,
                'area_number' => '0.08',
                'name' => 'berging',
                'label_text' => '0.08 berging',
            ])
            ->assertOk()
            ->assertJsonPath('area.id', $area->id)
            ->assertJsonPath('area.number', '0.08')
            ->assertJsonPath('area.name', 'berging')
            ->assertJsonPath('area.number_raw', '0.08')
            ->assertJsonPath('area.name_raw', 'berging')
            ->assertJsonPath('marker.source', 'manual')
            ->assertJsonPath('area.tone', 'open');

        $area->refresh();
        $this->assertSame('0.08', $area->area_number);
        $this->assertSame('berging', $area->name);

        $marker = $this->markerFor($project, '0.08');
        $this->assertSame($area->id, $marker->project_area_id);
        $this->assertEqualsWithDelta(0.37, (float) $marker->x, 0.01);
        $this->assertEqualsWithDelta(0.299, (float) $marker->y, 0.01);

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => '0.08 berging', 'x' => 0.11, 'y' => 0.12, 'w' => 0.08, 'h' => 0.016],
                ],
            ])
            ->assertOk();

        $marker->refresh();
        $this->assertSame('manual', $marker->source);
        $this->assertEqualsWithDelta(0.37, (float) $marker->x, 0.01);
        $this->assertEqualsWithDelta(0.299, (float) $marker->y, 0.01);

        $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->assertOk()
            ->assertJsonPath('area.id', $area->id)
            ->assertJsonPath('area.number', '0.08')
            ->assertJsonPath('area.name', 'berging')
            ->assertJsonPath('area.tone', 'open')
            ->assertJsonPath('area.marker.source', 'manual');

        $this->assertEqualsWithDelta(0.37, (float) $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->json('area.marker.x'), 0.01);
        $this->assertSame('manual', $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->json('area.marker.source'));
    }

    public function test_detect_does_not_overwrite_manual_position_if_loaded_markers_are_stale(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '0.21' => ['speellokaal', 80],
        ]);
        $area = $project->areas()->where('area_number', '0.21')->first();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.areas.marker', [$project, $area]), [
                'document_id' => $document->id,
                'page' => 1,
                'x' => 0.42,
                'y' => 0.31,
                'width' => 0.03,
                'height' => 0.012,
                'label_text' => '0.21 speellokaal',
            ])
            ->assertOk();

        $project->load(['areas.markers']);
        $stale = $project->areas->firstWhere('id', $area->id)->markers->first();
        $this->assertNotNull($stale);
        $stale->source = 'auto';
        $stale->x = 0.11;
        $stale->y = 0.12;

        app(RoomMarkerMatcher::class)->match($project, $document, [
            ['page' => 1, 'text' => '0.21 speellokaal', 'x' => 0.11, 'y' => 0.12, 'w' => 0.08, 'h' => 0.016],
        ]);

        $marker = $this->markerFor($project, '0.21');
        $this->assertSame('manual', $marker->source);
        $this->assertEqualsWithDelta(0.405, (float) $marker->x, 0.01);
        $this->assertEqualsWithDelta(0.304, (float) $marker->y, 0.01);
    }

    public function test_open_room_keeps_marker_coordinates_without_progress_tone(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.09')->first();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.areas.marker', [$project, $area]), [
                'document_id' => $document->id,
                'page' => 1,
                'x' => 0.36,
                'y' => 0.21,
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'open');

        $this->actingAs($user)
            ->getJson(route('projects.areas.show', [$project, $area]))
            ->assertOk()
            ->assertJsonPath('area.tone', 'open')
            ->assertJsonPath('area.marker.page', 1);
    }

    public function test_manual_text_position_can_be_saved(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject([
            '1.17' => ['kreeften', 42],
        ]);
        $area = $project->areas()->where('area_number', '1.17')->first();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.areas.marker', [$project, $area]), [
                'document_id' => $document->id,
                'page' => 1,
                'x' => 0.25,
                'y' => 0.26,
                'label_text' => '1.17 kreeften',
            ])
            ->assertOk()
            ->assertJsonPath('marker.source', 'manual')
            ->assertJsonPath('marker.label_text', '1.17 kreeften');

        $marker = $this->markerFor($project, '1.17');
        $this->assertEqualsWithDelta(0.21, (float) $marker->x, 0.01);
        $this->assertNotNull($marker->width);
    }

    public function test_manual_marker_and_snag_can_be_placed(): void
    {
        Storage::fake('local');
        [$user, $project, $worker] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.areas.marker', [$project, $area]), [
                'document_id' => $document->id,
                'page' => 1,
                'x' => 0.25,
                'y' => 0.4,
            ])
            ->assertOk()
            ->assertJsonPath('marker.source', 'manual');

        $this->actingAs($user)
            ->post(route('projects.snags.store', $project), [
                'x' => 0.26,
                'y' => 0.41,
                'drawing_page' => 1,
                'document_id' => $document->id,
                'description' => 'Kim niet recht',
                'assigned_worker_id' => $worker->id,
                'photo' => UploadedFile::fake()->image('schade.jpg', 640, 480),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('snag_items', [
            'project_id' => $project->id,
            'number' => 1,
            'status' => SnagStatus::Open->value,
            'description' => 'Kim niet recht',
            'assigned_worker_id' => $worker->id,
        ]);
        $this->assertDatabaseCount('snag_photos', 1);

        $snag = $project->snags()->first();
        $this->assertSame(1, $snag->number);

        $this->actingAs($user)
            ->getJson(route('projects.snags.show', [$project, $snag]))
            ->assertOk()
            ->assertJsonPath('snag.id', $snag->id)
            ->assertJsonPath('snag.number', 1)
            ->assertJsonPath('snag.area', '0.07 groepsruimte')
            ->assertJsonPath('snag.description', 'Kim niet recht')
            ->assertJsonPath('snag.worker', 'Albert');

        $other = $this->actingAs($user)
            ->post(route('projects.snags.store', $project), [
                'x' => 0.72,
                'y' => 0.61,
                'drawing_page' => 1,
                'document_id' => $document->id,
                'description' => 'Ander punt',
                'assigned_worker_id' => $worker->id,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('snag');

        $this->assertNotSame($snag->id, $other['id']);
        $this->actingAs($user)
            ->getJson(route('projects.snags.show', [$project, $snag]))
            ->assertOk()
            ->assertJsonPath('snag.id', $snag->id)
            ->assertJsonPath('snag.number', 1)
            ->assertJsonPath('snag.description', 'Kim niet recht');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="snag-popup"', false)
            ->assertSee('Bekijken / bewerken')
            ->assertSee('Status wijzigen')
            ->assertSee('Positie aanpassen')
            ->assertSee('"id":'.$snag->id, false)
            ->assertSee('0.07 groepsruimte');
    }

    /** @return array{0: User, 1: Project, 2: Worker} */
    private function makeProject(array $extraRooms = []): array
    {
        $user = User::factory()->projectleider()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $work = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Marmoleum Real',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        $rooms = array_merge([
            '0.07' => ['groepsruimte', 50.97],
            '0.09' => ['groepsruimte', 59],
            '0.12' => ['schoolleiding', 19],
            '0.19a' => ['administratie', 12],
            '0.24' => ['hal', 18],
        ], $extraRooms);
        foreach ($rooms as $number => $row) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $row[0],
                'square_meters' => $row[1],
                'status' => 'niet_gestart',
            ]);
            AreaTask::query()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $work->id,
                'ordered_quantity' => $row[1],
                'unit' => 'm2',
                'status' => 'niet_gestart',
            ]);
        }
        $file = UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf');
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', 'plan.pdf', 'local');
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);
        $this->assignToProject($project, $worker, $work);
        $project->load('documents');

        return [$user, $project->fresh(['areas', 'documents']), $worker];
    }

    private function assignToProject(Project $project, Worker $worker, ?WorkItem $item = null): void
    {
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item?->id ?? $project->workItems()->value('id'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'hours_per_day' => 8,
        ]);
    }

    private function markerFor(Project $project, string $number): AreaDrawingMarker
    {
        $marker = AreaDrawingMarker::query()
            ->whereHas('area', fn ($query) => $query->where('project_id', $project->id)->where('area_number', $number))
            ->first();

        $this->assertNotNull($marker, "Verwachtte een marker voor ruimte {$number}");

        return $marker;
    }
}
