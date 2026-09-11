<?php

namespace Tests\Feature;

use App\Enums\ImportSourceRole;
use App\Services\CalculationExcelParser;
use App\Services\Meetstaat\ImportDocumentClassifier;
use App\Services\Meetstaat\ImportPreviewBuilder;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Tests\Support\RealDrawingFixtures;
use Tests\Support\SimplePdf;
use Tests\TestCase;

/**
 * Bronrollen zijn inhoudelijk: geen vaste A+B+C-flow, geen projectnaam-regels.
 */
class ImportSourceRoleArchitectureTest extends TestCase
{
    public function test_room_level_material_document_is_task_source_not_totals_only(): void
    {
        $text = <<<'TXT'
Meetstaat
Bouwlaag: begane grond
Marmoleum Real, 3120 rosato, Linoleum
0.07 groepsruimte 50,97 m²
0.08 berging 24,01 m²
Totaal 75,00 m²
TXT;
        $result = $this->classifier()->fromText($text);
        $roles = $this->classifier()->detectRoles($result['type'], $text);

        $this->assertSame('meetstaat', $result['type']);
        $this->assertContains(ImportSourceRole::TaskSource->value, $roles);
        $this->assertContains(ImportSourceRole::MaterialTotalSource->value, $roles);
        $this->assertDoesNotContainProjectSpecificRules($text);
    }

    public function test_material_list_totals_without_rooms_are_material_total_source(): void
    {
        $text = <<<'TXT'
Material List
English Oak Classics
Netto : 2645.86 m²
Bruto : 2800.00 m²
PU coating
Netto : 384.74 m²
TXT;
        $result = $this->classifier()->fromText($text);
        $roles = $this->classifier()->detectRoles($result['type'], $text);

        $this->assertSame('materialenstaat', $result['type']);
        $this->assertContains(ImportSourceRole::MaterialTotalSource->value, $roles);
        $this->assertNotContains(ImportSourceRole::TaskSource->value, $roles);
    }

    public function test_snijmaten_content_is_material_room_hint_source(): void
    {
        $text = <<<'TXT'
Snijmaten
Bouwlaag: begane grond
tekenlokaal Tarkett vinyl iQ 12.40
magazijn tekenen Tarkett vinyl iQ 8.10
TXT;
        $result = $this->classifier()->fromText($text);
        $roles = $this->classifier()->detectRoles($result['type'], $text);

        $this->assertSame('snijmaten', $result['type']);
        $this->assertContains(ImportSourceRole::MaterialRoomHintSource->value, $roles);
    }

    public function test_drawing_content_exposes_room_and_legend_roles(): void
    {
        $roles = $this->classifier()->detectRoles('plattegrond', 'Legenda Marmoleum Real schaal 1:100', [
            'areas' => [['room_name' => 'tekenlokaal', 'square_meters' => 90.2]],
            'legend' => [['material' => 'Marmoleum Real', 'declared_total' => 100.0]],
        ]);

        $this->assertContains(ImportSourceRole::RoomSource->value, $roles);
        $this->assertContains(ImportSourceRole::DrawingLegendSource->value, $roles);
    }

    public function test_misleading_material_list_filename_still_routes_room_tasks_to_meetstaat_bucket(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawingPath === null || ! is_file($meetstaatPath)) {
            $this->markTestSkipped('Echte Laakse-fixtures ontbreken.');
        }

        $builder = app(ImportPreviewBuilder::class);
        $built = $builder->build([
            [
                'file' => new UploadedFile($meetstaatPath, 'materiaal lijst (4).pdf', 'application/pdf', null, true),
                'type' => null,
            ],
            [
                'file' => new UploadedFile($drawingPath, 'tekening(6).pdf', 'application/pdf', null, true),
                'type' => null,
            ],
        ]);

        $this->assertTrue($built['preview']['sources']['meetstaat'] ?? false);
        $this->assertTrue($built['preview']['sources']['plattegrond'] ?? false);
        $this->assertNotEmpty($built['preview']['source_analysis'] ?? []);

        $analysis = collect($built['preview']['source_analysis']);
        $taskDoc = $analysis->first(fn (array $row) => in_array(ImportSourceRole::TaskSource->value, $row['roles'] ?? [], true));
        $roomDoc = $analysis->first(fn (array $row) => in_array(ImportSourceRole::RoomSource->value, $row['roles'] ?? [], true));
        $this->assertNotNull($taskDoc);
        $this->assertNotNull($roomDoc);
        $this->assertStringContainsString('area_tasks', (string) ($built['preview']['source_priority_note'] ?? ''));

        $egels = collect($built['preview']['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '0.35'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'egel')
        );
        $this->assertNotNull($egels);
        $flooringTasks = collect($egels['tasks'] ?? [])->filter(function (array $task) {
            $unit = (string) ($task['unit'] ?? '');

            return in_array($unit, ['m2', 'm²'], true) && (float) ($task['quantity'] ?? 0) > 0;
        });
        $this->assertGreaterThanOrEqual(2, $flooringTasks->count(), '0.35 egels: één fysieke ruimte, meerdere materiaaltaken.');
    }

    public function test_named_style_bundle_keeps_griftland_room_count_without_project_name_rules(): void
    {
        $path = RealDrawingFixtures::griftlandDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('Echte Griftland-plattegrond ontbreekt.');
        }

        $builder = app(ImportPreviewBuilder::class);
        $built = $builder->build([[
            'file' => new UploadedFile($path, 'Plattegrond.pdf', 'application/pdf', null, true),
            'type' => null,
        ]]);

        $this->assertSame(115, count($built['preview']['areas']));
        $roomDoc = collect($built['preview']['source_analysis'] ?? [])->first(
            fn (array $row) => in_array(ImportSourceRole::RoomSource->value, $row['roles'] ?? [], true)
        );
        $this->assertNotNull($roomDoc);
        $this->assertSame('Hoog', $roomDoc['reliability_label']);
    }

    public function test_generic_pdf_classification_does_not_depend_on_customer_filename_tokens(): void
    {
        $path = SimplePdf::path("Materialenstaat\nMarmoleum Real\nNetto : 100 m²\n");
        $file = new UploadedFile($path, 'bestand.pdf', 'application/pdf', null, true);
        $result = $this->classifier()->classify($file);

        $this->assertSame('materialenstaat', $result['type']);
        $this->assertContains(ImportSourceRole::MaterialTotalSource->value, $result['roles']);
        $this->assertArrayHasKey('usable_data', $result);
        $this->assertArrayHasKey('reliability_label', $result);
    }

    private function classifier(): ImportDocumentClassifier
    {
        return new ImportDocumentClassifier(
            new PdfTextExtractor,
            new SpreadsheetReader,
            new CalculationExcelParser,
        );
    }

    private function assertDoesNotContainProjectSpecificRules(string $haystack): void
    {
        $flat = mb_strtolower($haystack);
        $this->assertStringNotContainsString('laakse', $flat);
        $this->assertStringNotContainsString('griftland', $flat);
    }
}
