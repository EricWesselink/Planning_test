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
use App\Services\DrawingRoomRelinker;
use App\Services\Meetstaat\DrawingColorMatcher;
use App\Services\Meetstaat\PdfPageGeometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class DrawingRoomLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_auto_links_unique_name_and_square_meters(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $this->addArea($project, 'begane grond', null, 'KDV slaapkamer 6', 4.06);
        $this->addArea($project, 'begane grond', null, 'multifunctionele ruimte', 83.13);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06, 'w' => 0.10, 'h' => 0.02],
                    ['page' => 1, 'text' => 'KDV slaapkamer', 'x' => 0.22, 'y' => 0.40, 'w' => 0.10, 'h' => 0.016],
                    ['page' => 1, 'text' => '4,06 m²', 'x' => 0.22, 'y' => 0.42, 'w' => 0.05, 'h' => 0.014],
                    ['page' => 1, 'text' => 'multifunctionele ruimte', 'x' => 0.55, 'y' => 0.30, 'w' => 0.14, 'h' => 0.016],
                    ['page' => 1, 'text' => '83,13 m²', 'x' => 0.55, 'y' => 0.32, 'w' => 0.06, 'h' => 0.014],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 2)
            ->assertJsonPath('counts.review', 0)
            ->assertJsonPath('counts.unmatched', 0);

        $kdv = $project->areas()->where('name', 'KDV slaapkamer 6')->first();
        $multi = $project->areas()->where('name', 'multifunctionele ruimte')->first();
        $this->assertNotNull(AreaDrawingMarker::query()->where('project_area_id', $kdv->id)->first());
        $this->assertEqualsWithDelta(0.22, (float) AreaDrawingMarker::query()->where('project_area_id', $kdv->id)->value('x'), 0.0001);
        $this->assertEqualsWithDelta(0.55, (float) AreaDrawingMarker::query()->where('project_area_id', $multi->id)->value('x'), 0.0001);
    }

    public function test_detect_uses_meters_to_split_duplicate_gang_names(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $gangA = $this->addArea($project, 'begane grond', null, 'gang', 12.50);
        $gangB = $this->addArea($project, 'begane grond', null, 'gang', 8.04);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => 'gang', 'x' => 0.18, 'y' => 0.33, 'w' => 0.04, 'h' => 0.014],
                    ['page' => 1, 'text' => '12,50 m²', 'x' => 0.18, 'y' => 0.35, 'w' => 0.05, 'h' => 0.014],
                    ['page' => 1, 'text' => 'gang', 'x' => 0.62, 'y' => 0.51, 'w' => 0.04, 'h' => 0.014],
                    ['page' => 1, 'text' => '8,04 m²', 'x' => 0.62, 'y' => 0.53, 'w' => 0.05, 'h' => 0.014],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 2)
            ->assertJsonPath('counts.review', 0);

        $this->assertEqualsWithDelta(0.18, (float) AreaDrawingMarker::query()->where('project_area_id', $gangA->id)->value('x'), 0.0001);
        $this->assertEqualsWithDelta(0.62, (float) AreaDrawingMarker::query()->where('project_area_id', $gangB->id)->value('x'), 0.0001);
        $this->assertNotSame(
            AreaDrawingMarker::query()->where('project_area_id', $gangA->id)->value('id'),
            AreaDrawingMarker::query()->where('project_area_id', $gangB->id)->value('id'),
        );
    }

    public function test_detect_links_tied_duplicate_names_left_to_right(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $left = $this->addArea($project, 'begane grond', null, 'gang', 12.50);
        $right = $this->addArea($project, 'begane grond', null, 'gang', 12.50);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => 'gang', 'x' => 0.18, 'y' => 0.33],
                    ['page' => 1, 'text' => '12,50 m²', 'x' => 0.18, 'y' => 0.35],
                    ['page' => 1, 'text' => 'gang', 'x' => 0.62, 'y' => 0.51],
                    ['page' => 1, 'text' => '12,50 m²', 'x' => 0.62, 'y' => 0.53],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 2)
            ->assertJsonPath('counts.manual', 0)
            ->assertJsonPath('counts.review', 0)
            ->assertJsonPath('counts.none', 0);

        $this->assertEqualsWithDelta(0.18, (float) AreaDrawingMarker::query()->where('project_area_id', $left->id)->value('x'), 0.0001);
        $this->assertEqualsWithDelta(0.62, (float) AreaDrawingMarker::query()->where('project_area_id', $right->id)->value('x'), 0.0001);
        $this->assertSame('midden', AreaDrawingMarker::query()->where('project_area_id', $left->id)->first()->confidenceLabel());
    }

    public function test_detect_does_not_link_across_floors(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $this->addArea($project, 'verdieping 1', null, 'multifunctionele ruimte', 83.13);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => 'multifunctionele ruimte', 'x' => 0.55, 'y' => 0.30],
                    ['page' => 1, 'text' => '83,13 m²', 'x' => 0.55, 'y' => 0.32],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 0)
            ->assertJsonPath('counts.unmatched', 1);

        $this->assertSame(0, AreaDrawingMarker::query()->count());
    }

    public function test_detect_keeps_unique_meters_for_unnamed_number_collision(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $numbered = $this->addArea($project, 'begane grond', '00.20', 'KDV slaapkamer', 0);
        $meters = $this->addArea($project, 'begane grond', null, 'KDV slaapkamer', 4.06);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => '00.20', 'x' => 0.21, 'y' => 0.39, 'w' => 0.04, 'h' => 0.014],
                    ['page' => 1, 'text' => 'KDV slaapkamer', 'x' => 0.22, 'y' => 0.40, 'w' => 0.10, 'h' => 0.016],
                    ['page' => 1, 'text' => '4,06 m²', 'x' => 0.22, 'y' => 0.42, 'w' => 0.05, 'h' => 0.014],
                ],
            ])
            ->assertOk();

        $this->assertNotNull(AreaDrawingMarker::query()->where('project_area_id', $meters->id)->first());
        $this->assertEqualsWithDelta(0.22, (float) AreaDrawingMarker::query()->where('project_area_id', $meters->id)->value('x'), 0.0001);
        $this->assertNotSame(
            AreaDrawingMarker::query()->where('project_area_id', $numbered->id)->value('x'),
            AreaDrawingMarker::query()->where('project_area_id', $meters->id)->value('x'),
        );
    }

    public function test_relink_from_stored_pdf_links_unique_sluisbuurt_rooms(): void
    {
        $bundle = RealDrawingFixtures::sluisbuurtBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte IKC Sluisbuurt-bundel ontbreekt.');
        }

        Storage::fake('local');
        [, $project] = $this->makeLinkedProject();
        $project->update(['name' => 'IKC Sluisbuurt']);
        $document = $project->plattegrond();
        $stored = Storage::disk('local')->path($document->file_path);
        $this->assertTrue(copy($bundle['drawing'], $stored));

        $extracted = (new PdfPageGeometry)->extract($bundle['drawing']);
        $rooms = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages($extracted['pages'] ?? [])['rooms'] ?? [];
        $kdv = collect($rooms)->first(function (array $room) {
            return str_contains(mb_strtolower((string) ($room['room_name'] ?? '')), 'slaapkamer')
                && abs((float) ($room['square_meters'] ?? 0) - 4.06) <= 0.05;
        });
        $multi = collect($rooms)->first(function (array $room) {
            return str_contains(mb_strtolower((string) ($room['room_name'] ?? '')), 'multifunctionele')
                && abs((float) ($room['square_meters'] ?? 0) - 83.13) <= 0.05;
        });
        $this->assertNotNull($kdv, 'Verwachtte KDV slaapkamer 4,06 m² op de Sluisbuurt-tekening.');
        $this->assertNotNull($multi, 'Verwachtte multifunctionele ruimte 83,13 m² op de Sluisbuurt-tekening.');

        $kdvArea = $this->addArea($project, (string) $kdv['floor'], null, 'KDV slaapkamer 6', 4.06);
        $multiArea = $this->addArea($project, (string) $multi['floor'], null, 'multifunctionele ruimte', 83.13);

        $report = app(DrawingRoomRelinker::class)->relink($project->fresh(['documents', 'areas.floor', 'areas.markers']));

        $this->assertNull($report['error']);
        $this->assertGreaterThanOrEqual(2, $report['counts']['auto']);
        $this->assertSame(0, $report['counts']['manual']);
        $this->assertNotNull(AreaDrawingMarker::query()->where('project_area_id', $kdvArea->id)->first());
        $this->assertTrue(AreaDrawingMarker::query()->where('project_area_id', $kdvArea->id)->first()->hasPosition());
        $this->assertTrue(AreaDrawingMarker::query()->where('project_area_id', $multiArea->id)->first()->hasPosition());
        $this->assertNotSame(
            AreaDrawingMarker::query()->where('project_area_id', $kdvArea->id)->value('id'),
            AreaDrawingMarker::query()->where('project_area_id', $multiArea->id)->value('id'),
        );
    }

    public function test_detect_links_numbered_kitchens_left_to_right_on_tussenlaag(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $kitchens = [];
        for ($index = 10; $index >= 1; $index--) {
            $kitchens[$index] = $this->addArea($project, 'tussenlaag', null, 'Keuken '.$index, 15.71);
        }
        $document = $project->plattegrond();

        $items = [
            ['page' => 1, 'text' => 'MIDDENLAAG (ENTREE)', 'x' => 0.82, 'y' => 0.06, 'w' => 0.14, 'h' => 0.02],
        ];
        for ($index = 1; $index <= 10; $index++) {
            $x = round(0.08 + (($index - 1) * 0.08), 2);
            $items[] = ['page' => 1, 'text' => 'keuken', 'x' => $x, 'y' => 0.40, 'w' => 0.05, 'h' => 0.014];
            $items[] = ['page' => 1, 'text' => '15,71 m²', 'x' => $x, 'y' => 0.42, 'w' => 0.05, 'h' => 0.014];
        }

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => $items,
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 10)
            ->assertJsonPath('counts.manual', 0)
            ->assertJsonPath('counts.none', 0);

        for ($index = 1; $index <= 10; $index++) {
            $expected = round(0.08 + (($index - 1) * 0.08), 2);
            $marker = AreaDrawingMarker::query()->where('project_area_id', $kitchens[$index]->id)->first();
            $this->assertNotNull($marker);
            $this->assertTrue($marker->hasPosition());
            $this->assertEqualsWithDelta($expected, (float) $marker->x, 0.0001);
            $this->assertNotNull($marker->drawing_room_id);
        }
    }

    public function test_detect_keeps_unmatched_internal_when_no_candidate_exists(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $this->addArea($project, 'begane grond', null, 'dakopbouw', 3.14);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 0)
            ->assertJsonPath('counts.manual', 0)
            ->assertJsonPath('counts.none', 1)
            ->assertJsonPath('review', []);

        $this->assertSame(0, AreaDrawingMarker::query()->count());
    }

    public function test_detect_joins_split_square_meter_tokens(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $kitchen = $this->addArea($project, 'tussenlaag', null, 'keuken', 15.71);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'MIDDENLAAG (ENTREE)', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => '15.71', 'x' => 0.22, 'y' => 0.31, 'w' => 0.03, 'h' => 0.012],
                    ['page' => 1, 'text' => 'm²', 'x' => 0.26, 'y' => 0.31, 'w' => 0.02, 'h' => 0.012],
                    ['page' => 1, 'text' => 'keuken', 'x' => 0.22, 'y' => 0.35, 'w' => 0.05, 'h' => 0.014],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 1)
            ->assertJsonPath('counts.manual', 0);

        $this->assertEqualsWithDelta(0.22, (float) AreaDrawingMarker::query()->where('project_area_id', $kitchen->id)->value('x'), 0.0001);
    }

    public function test_detect_reuses_same_name_rooms_when_the_list_has_extras(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeLinkedProject();
        $first = $this->addArea($project, 'begane grond', null, 'keuken', 15.71);
        $second = $this->addArea($project, 'begane grond', null, 'keuken', null);
        $third = $this->addArea($project, 'begane grond', null, 'keuken', null);
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.drawings.detect', [$project, $document]), [
                'items' => [
                    ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
                    ['page' => 1, 'text' => 'keuken', 'x' => 0.40, 'y' => 0.40, 'w' => 0.05, 'h' => 0.014],
                    ['page' => 1, 'text' => '15,71 m²', 'x' => 0.40, 'y' => 0.36, 'w' => 0.05, 'h' => 0.014],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('counts.auto', 3)
            ->assertJsonPath('counts.manual', 0)
            ->assertJsonPath('counts.none', 0);

        $this->assertEqualsWithDelta(0.40, (float) AreaDrawingMarker::query()->where('project_area_id', $first->id)->value('x'), 0.0001);
        $this->assertEqualsWithDelta(0.40, (float) AreaDrawingMarker::query()->where('project_area_id', $second->id)->value('x'), 0.0001);
        $this->assertEqualsWithDelta(0.40, (float) AreaDrawingMarker::query()->where('project_area_id', $third->id)->value('x'), 0.0001);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function makeLinkedProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Test tekening',
            'status' => 'gepland',
        ]);
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

        return [$user, $project->fresh(['documents', 'areas'])];
    }

    private function addArea(Project $project, string $floorName, ?string $number, string $name, ?float $meters): ProjectArea
    {
        $floor = ProjectFloor::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => $floorName],
            ['sort_order' => $project->floors()->count() + 1],
        );

        return ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => $number,
            'name' => $name,
            'square_meters' => $meters,
            'status' => AreaStatus::NietGestart,
        ]);
    }
}
