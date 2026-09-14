<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use App\Services\ProjectIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class GriftlandWorkVariantImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_work_code_materials_import_as_separate_work_items_without_truncating_names(): void
    {
        $bundle = RealDrawingFixtures::griftlandBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte Griftland-bundel ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $preview = (new RoomImportAssembler)->assemble(
            (new MeetstaatReader($extractor))->parseFile($bundle['meetstaat'], 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($bundle['drawing'], 'plattegrond.pdf'),
            (new MaterialenstaatParser($extractor))->parseFile($bundle['materials'], 'materialenstaat.pdf'),
        );

        $report = $preview['import_report'] ?? [];
        $parsed = (float) ($report['meetstaat_task_meters'] ?? 0);
        $kept = (float) ($report['task_meters'] ?? 0);
        $lost = (float) ($report['task_source_meters_lost'] ?? abs($parsed - $kept));

        $this->assertEqualsWithDelta(8456.65, $parsed, 0.005);
        $this->assertEqualsWithDelta(8456.65, $kept, 0.005);
        $this->assertEqualsWithDelta(0.0, $lost, 0.005);

        $project = app(ProjectIntakeService::class)->importPreview($preview, User::factory()->create());

        $names = $project->workItems()->pluck('name');
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $name), (string) $name);
            $this->assertFalse(
                str_contains((string) $name, 'Coral Brush') && str_contains((string) $name, 'Tarkett'),
                (string) $name
            );
        }

        $clusterLabels = [
            'Coral Brush',
            'Natural-black',
            'PU gietvloer antislip',
            'vloercoating',
            'Granit light',
            'iQ light green',
            'Natural-light blue',
            'Dusty Brick',
            'dusty green',
            'Natural blue',
            'directie levering',
        ];
        foreach ($clusterLabels as $label) {
            $this->assertTrue(
                $names->contains(fn ($name) => str_contains((string) $name, $label)),
                $label.' ontbreekt als aparte werkzaamheid.'
            );
        }

        $fromWorkCode = $names->filter(function ($name) {
            $flat = mb_strtolower((string) $name);

            return str_contains($flat, 'coral brush')
                || str_contains($flat, 'natural-black')
                || str_contains($flat, 'gietvloer')
                || str_contains($flat, 'vloercoating')
                || str_contains($flat, 'granit light')
                || str_contains($flat, 'iq light green')
                || str_contains($flat, 'natural-light blue')
                || str_contains($flat, 'dusty brick')
                || str_contains($flat, 'dusty green')
                || str_contains($flat, 'natural blue')
                || str_contains($flat, 'directie levering');
        });
        $this->assertGreaterThanOrEqual(12, $fromWorkCode->unique()->count());
        $plainGietvloer = $names->filter(
            fn ($name) => str_contains((string) $name, 'PU gietvloer')
                && ! str_contains(mb_strtolower((string) $name), 'antislip')
        );
        $this->assertGreaterThanOrEqual(1, $plainGietvloer->count());
        $importedSqm = $project->areas()->with('tasks')->get()->sum(
            function ($area): float {
                return (float) $area->tasks
                    ->filter(fn ($task) => (string) $task->unit->value === 'm2')
                    ->sum(fn ($task) => (float) $task->ordered_quantity);
            }
        );
        $this->assertEqualsWithDelta(8456.65, $importedSqm, 0.05);
    }
}
