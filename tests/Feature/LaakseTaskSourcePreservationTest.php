<?php

namespace Tests\Feature;

use App\Enums\ImportDecision;
use App\Models\Project;
use App\Models\User;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class LaakseTaskSourcePreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assembled_area_tasks_preserve_all_meetstaat_task_source_meters_by_floor_and_material(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawingPath === null || ! is_file($meetstaatPath)) {
            $this->markTestSkipped('Echte Laakse-fixtures ontbreken.');
        }

        $extractor = new PdfTextExtractor;
        $meetstaat = (new MeetstaatReader($extractor))->parseFile($meetstaatPath, 'meetstaat.pdf');
        $drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
        $assembler = new RoomImportAssembler;
        $preview = $assembler->assemble($meetstaat, $drawing);

        $parsedAreas = $meetstaat['areas'] ?? [];
        $finalAreas = $preview['areas'] ?? [];

        $this->assertEqualsWithDelta(
            $assembler->sumFlooringTaskMeters($parsedAreas),
            $assembler->sumFlooringTaskMeters($finalAreas),
            0.01,
            'SUM(parsed TASK_SOURCE) moet gelijk zijn aan SUM(final area_tasks).'
        );
        $this->assertEqualsWithDelta(3440.46, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($preview['import_report']['task_source_meters_lost'] ?? 99), 0.01);
        $this->assertSame(ImportDecision::Ready->value, $preview['import_closure']['decision']);
        $this->assertCount(5, $preview['floors']);

        $parsedByFloor = $assembler->flooringTaskMetersByFloor($parsedAreas);
        $finalByFloor = $assembler->flooringTaskMetersByFloor($finalAreas);
        foreach ($parsedByFloor as $floor => $meters) {
            $this->assertArrayHasKey($floor, $finalByFloor, 'Bouwlaag mag niet verdwijnen: '.$floor);
            $this->assertEqualsWithDelta($meters, $finalByFloor[$floor], 0.01, 'Bouwlaag-taken verloren: '.$floor);
        }

        $parsedByMaterial = $assembler->flooringTaskMetersByMaterial($parsedAreas);
        $finalByMaterial = $assembler->flooringTaskMetersByMaterial($finalAreas);
        foreach ($parsedByMaterial as $material => $meters) {
            $this->assertArrayHasKey($material, $finalByMaterial, 'Materiaal mag niet verdwijnen: '.$material);
            $this->assertEqualsWithDelta($meters, $finalByMaterial[$material], 0.01, 'Materiaal-taken verloren: '.$material);
        }
    }

    public function test_ready_import_ignores_truncated_posted_areas_and_keeps_full_task_source(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawingPath === null || ! is_file($meetstaatPath)) {
            $this->markTestSkipped('Echte Laakse-fixtures ontbreken.');
        }

        $user = User::factory()->create();
        $extractor = new PdfTextExtractor;
        $preview = (new RoomImportAssembler)->assemble(
            (new MeetstaatReader($extractor))->parseFile($meetstaatPath, 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf'),
        );
        $this->assertTrue($preview['import_closure']['ready']);
        $this->assertSame(ImportDecision::Ready->value, $preview['import_closure']['decision']);

        $token = 'task-source-preserve-token';
        Cache::put('meetstaat.'.$token, [
            'preview' => $preview,
            'file' => null,
            'original' => 'Meetbon_Laakse_Tuinen.pdf',
            'plattegrond' => null,
            'plattegrond_original' => 'tekening.pdf',
            'uploads' => [],
            'extra' => [],
        ], now()->addHour());

        // Zonder areas[] (zoals READY_AUTOMATIC-formulier dat area-inputs disabled):
        // truncated areas zouden anders via max_input_vars de preview vernietigen.
        $lossIfApplied = (new RoomImportAssembler)->taskSourceLossMeters($preview, [
            'areas' => array_slice($preview['areas'], 0, 40),
            'closure_baselines' => $preview['closure_baselines'],
        ]);
        $this->assertGreaterThan(1000.0, $lossIfApplied, 'Truncatie moet aantoonbaar TASK_SOURCE-verlies geven.');

        $response = $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Laakse Tuinen Task Source',
            'project_number' => '260299900',
            'areas' => [
                0 => [
                    'floor' => 'begane grond',
                    'room_number' => '0.01',
                    'room_name' => 'truncated',
                    'square_meters' => '1,00',
                    'source' => 'meetstaat',
                    'confidence' => 'hoog',
                    'recognized_via' => 'meetstaat',
                    'fill_color' => '',
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                        'quantity' => '1,00',
                        'unit' => 'm2',
                        'perimeter' => '',
                        'seams' => '',
                    ]],
                ],
            ],
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $project = Project::query()->where('project_number', '260299900')->first();
        $this->assertNotNull($project, 'Import must create the project from cached READY preview.');
        $this->assertGreaterThan(100, $project->areas()->count());

        $importedTaskMeters = 0.0;
        $project->load('areas.tasks');
        foreach ($project->areas as $area) {
            foreach ($area->tasks as $task) {
                $unit = $task->unit instanceof \BackedEnum ? $task->unit->value : (string) ($task->unit ?? 'm2');
                if ($unit === 'm2' || $unit === 'm²') {
                    $importedTaskMeters += (float) $task->ordered_quantity;
                }
            }
        }
        $this->assertEqualsWithDelta(3440.46, $importedTaskMeters, 0.05);
        $this->assertNull(Cache::get('meetstaat.'.$token), 'Succesvolle import wist de preview-cache.');
    }

    public function test_task_source_loss_blocks_ready_automatic(): void
    {
        $preview = [
            'sources' => [
                'meetstaat' => true,
                'plattegrond' => false,
                'materialenstaat' => false,
                'snijmaten' => false,
                'kleur' => false,
            ],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => 50.97,
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 20.0,
                ]],
                'source' => 'meetstaat',
                'confidence' => 'hoog',
                'needs_review' => false,
            ]],
            'closure_baselines' => [
                'meetstaat_areas' => [[
                    'floor' => 'begane grond',
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 50.97,
                    ]],
                ]],
            ],
            'uncertain' => [],
            'duplicates' => [],
            'legend' => [],
            'import_report' => [
                'task_meters' => 20.0,
                'meetstaat_task_meters' => 50.97,
                'task_source_meters_parsed' => 50.97,
                'task_source_meters_kept' => 20.0,
                'task_source_meters_lost' => 30.97,
                'task_meters_expected' => 50.97,
                'materials' => [[
                    'material' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'found_task_meters' => 20.0,
                    'expected_task_meters' => 50.97,
                    'difference' => -30.97,
                    'status' => 'controleren',
                ]],
                'floors' => [],
            ],
            'expected_task_totals' => [
                'known' => true,
                'project_total' => 50.97,
            ],
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertFalse($closure['ready']);
        $this->assertSame(ImportDecision::TechnicalError->value, $closure['decision']);
        $this->assertTrue(collect($closure['checks'])->contains(
            fn (array $check) => ($check['key'] ?? '') === 'task_source' && ($check['ok'] ?? true) === false
        ));
    }

    private function meetstaatUpload(): UploadedFile
    {
        return new UploadedFile(
            RealDrawingFixtures::laakseTuinenMeetstaatPath(),
            'Meetbon_Laakse_Tuinen.pdf',
            'application/pdf',
            null,
            true
        );
    }
}
