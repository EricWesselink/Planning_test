<?php

namespace Tests\Unit;

use App\Services\CalculationExcelParser;
use App\Services\CalculationImportService;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MaterialIdentity;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Illuminate\Http\UploadedFile;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class PolitieDrachtenImportTest extends TestCase
{
    public function test_work_codes_keep_wrapped_descriptions_as_one_work(): void
    {
        $parsed = (new NiconMeetbonParser)->parse($this->meetstaatText());
        $names = collect($parsed['works'])->pluck('name');

        $this->assertSame([], $parsed['uncertain']);

        $this->assertTrue($names->contains(fn ($name) => str_starts_with((string) $name, '43.20.01a')));
        $this->assertTrue($names->contains(fn ($name) => str_starts_with((string) $name, '43.20.03a')));
        $this->assertTrue($names->contains(fn ($name) => str_contains((string) $name, 'Entreemat')));
        $this->assertTrue($names->contains(fn ($name) => str_contains((string) $name, 'donkergrijs')));
        $this->assertFalse($names->contains('Entreemat'));
        $this->assertFalse($names->contains('Tapijttegels'));
        $this->assertFalse($names->contains('Coating'));
        $this->assertFalse($names->contains('Linoleum'));
        $this->assertFalse($names->contains(fn ($name) => (bool) preg_match('/^donkergrijs/i', (string) $name)));
        $this->assertCount(1, $names->filter(fn ($name) => str_contains((string) $name, '43.20.03a')));
        $this->assertCount(1, $names->filter(fn ($name) => str_contains((string) $name, '43.20.03b')));
    }

    public function test_meetstaat_tasks_survive_merge_with_material_list_excel_and_unmatched_drawing(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse($this->meetstaatText());
        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
43.20.03a Epoxy gietvloer (sp) S 3500-N, donkergrijs, Coating
Netto : 4741,99 m²
43.20.05a Tapijttegel Desso Airmaster, Tapijttegels
Netto : 8016,11 m²
TXT);
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '00.99',
                'room_name' => 'tekeningrest',
                'square_meters' => 12.0,
                'source' => 'plattegrond',
                'tasks' => [],
            ]],
            'legend' => [],
            'works' => [],
            'warnings' => [],
            'uncertain' => [],
        ];

        $parsedMeters = $this->taskMeters($meetstaat['areas']);
        $parsedTasks = $this->taskCount($meetstaat['areas']);
        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing, $materials);
        $preview = app(CalculationImportService::class)->attach($preview, [[
            'file' => new UploadedFile(__FILE__, 'calc.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'parsed' => app(CalculationExcelParser::class)->parse([
                ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
                ['L', '100', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '20', 'uur', '48', '960'],
                ['M', '100', 'M', 'Elastische vloerbedekking', '43.20.03a Epoxy gietvloer', '1677.90', 'm2', '9', '15101.1'],
            ], 'calc.xlsx'),
        ]]);

        $keptMeters = $this->taskMeters($preview['areas']);
        $keptMeetstaatTasks = $this->taskCount($preview['areas']);
        $epoxy = collect($preview['works'])->first(fn (array $work) => str_contains((string) $work['name'], '43.20.03a'));
        $product = collect($preview['calculation']['products'] ?? [])->first(
            fn (array $row) => str_contains((string) ($row['name'] ?? ''), '43.20.03a')
        );
        $unmatched = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '00.20'
        );
        $coatingFloor = collect($preview['areas'])->first(
            fn (array $area) => str_contains((string) ($area['floor'] ?? ''), 'Coatingvloeren')
        );

        $this->assertEqualsWithDelta(1009.18, $parsedMeters, 0.02);
        $this->assertEqualsWithDelta($parsedMeters, $keptMeters, 0.05);
        $this->assertSame($parsedTasks, $keptMeetstaatTasks);
        $this->assertEqualsWithDelta(0.0, (float) ($preview['import_report']['task_source_meters_lost'] ?? 99), 0.05);
        $this->assertEqualsWithDelta($parsedMeters, (float) ($preview['import_report']['task_meters_expected'] ?? 0), 0.05);
        $this->assertEqualsWithDelta($parsedMeters, (float) ($preview['import_report']['task_meters'] ?? 0), 0.05);
        $this->assertNotNull($epoxy);
        $this->assertEqualsWithDelta(876.68, (float) $epoxy['declared_total'], 0.05);
        $this->assertNotEquals(4741.99, (float) $epoxy['declared_total']);
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(876.68, (float) $product['leading_quantity'], 0.05);
        $this->assertEqualsWithDelta(1677.90, (float) $product['excel_quantity'], 0.05);
        $this->assertTrue((bool) ($product['meetstaat_is_leading'] ?? false));
        $this->assertNotNull($unmatched);
        $this->assertNotEmpty($unmatched['tasks'] ?? []);
        $this->assertNotNull($coatingFloor);
        $this->assertNotSame('Kelvinlaan 2 begane grond', $coatingFloor['floor']);
        $this->assertTrue(collect($preview['areas'])->contains(
            fn (array $area) => ($area['room_number'] ?? '') === '00.99'
        ));
        $this->assertEqualsWithDelta($parsedMeters, (float) ($preview['import_closure']['totals']['expected'] ?? 0), 0.05);
        $this->assertEqualsWithDelta($parsedMeters, (float) ($preview['import_closure']['totals']['processed'] ?? 0), 0.05);
    }

    public function test_real_drachten_meetstaat_keeps_471_tasks_and_allows_import_with_drawing_warnings(): void
    {
        $bundle = RealDrawingFixtures::drachtenBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte Politie Drachten-bundel ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $meetstaat = (new MeetstaatReader($extractor))
            ->parseFile($bundle['meetstaat'], 'meetstaat.pdf');
        $parsedTasks = $this->taskCount($meetstaat['areas']);
        $parsedMeters = $this->taskMeters($meetstaat['areas']);

        $this->assertSame(471, $parsedTasks);
        $this->assertEqualsWithDelta(12562.28, $parsedMeters, 0.01);

        $materials = null;
        if (($bundle['materials'] ?? null) !== null) {
            $materials = (new MaterialenstaatParser($extractor))
                ->parseFile($bundle['materials'], 'materialenstaat.pdf');
        }
        $drawing = null;
        if (($bundle['drawing'] ?? null) !== null) {
            $drawing = (new FloorPlanParser($extractor))
                ->parseFile($bundle['drawing'], 'plattegrond.pdf');
        }

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing, $materials);
        $names = collect($preview['works'])->pluck('name');
        $closure = $preview['import_closure'];
        $report = $preview['import_report'];

        $this->assertSame(471, $this->taskCount($preview['areas']));
        $this->assertEqualsWithDelta(12562.28, (float) ($report['task_meters'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($report['task_source_meters_lost'] ?? 99), 0.01);
        $this->assertFalse($names->contains('Entreemat'));
        $this->assertFalse($names->contains('Coating'));
        $this->assertFalse($names->contains('Tapijttegels'));
        $this->assertFalse($names->contains('Linoleum'));
        $this->assertFalse($names->contains('Coral Brush'));
        $this->assertFalse($names->contains('Desso Airmaster'));
        $this->assertFalse($names->contains('Marmoleum Concrete'));
        $this->assertFalse($names->contains('Vloercoating Ral'));
        $this->assertGreaterThanOrEqual(1, $names->filter(fn ($name) => str_contains((string) $name, '43.20.03a') && str_contains((string) $name, '(sp)'))->count());
        $this->assertGreaterThanOrEqual(1, $names->filter(fn ($name) => str_contains((string) $name, '43.20.03a') && str_contains((string) $name, '(hp)'))->count());
        $this->assertTrue((bool) $closure['ready']);
        $this->assertContains($closure['decision'], ['READY_AUTOMATIC', 'READY_WITH_WARNINGS']);
        $this->assertSame(0, (int) $closure['hard_conflict_count']);
        $this->assertEqualsWithDelta(12562.28, (float) ($closure['totals']['expected'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(12562.28, (float) ($closure['totals']['processed'] ?? 0), 0.01);
    }

    public function test_work_codes_with_letter_suffix_are_distinct_identities(): void
    {
        $identity = new MaterialIdentity;

        $this->assertSame(['43.20.03a'], $identity->workCodes('43.20.03a Epoxy gietvloer, donkergrijs, Coating'));
        $this->assertTrue($identity->sharesWorkCode(
            '43.20.03a Epoxy gietvloer, donkergrijs, Coating',
            '43.20.03a Epoxy gietvloer (sp) S 3500-N'
        ));
        $this->assertFalse($identity->sharesWorkCode(
            '43.20.03a Epoxy gietvloer, donkergrijs, Coating',
            '43.20.03b Epoxy gietvloer antislip, lichtgrijs, Coating'
        ));
        $this->assertFalse($identity->sharesIdentity(
            '43.20.03a Epoxy gietvloer, donkergrijs, Coating',
            '43.20.03b Epoxy gietvloer antislip, lichtgrijs, Coating'
        ));
    }

    private function meetstaatText(): string
    {
        $text = file_get_contents(base_path('tests/fixtures/politie-drachten-meetstaat.txt'));
        $this->assertNotFalse($text);

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function taskMeters(array $areas): float
    {
        $total = 0.0;
        foreach ($areas as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                $total += (float) ($task['quantity'] ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function taskCount(array $areas): int
    {
        $count = 0;
        foreach ($areas as $area) {
            $count += count($area['tasks'] ?? []);
        }

        return $count;
    }
}
