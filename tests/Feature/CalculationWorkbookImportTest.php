<?php

namespace Tests\Feature;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\User;
use App\Models\WorkbookColumnMemory;
use App\Services\QuoteCalculation\CalculationRoomRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RealDrawingFixtures;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class CalculationWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_and_excel_are_merged_automatically_and_show_a_summary(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Offerte Oisterwijk',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum - Forbo 3752", 'bg.pdf')],
            'workbooks' => [$this->csv('afwerkstaat.csv', implode("\n", [
                'Ruimte nr.;Vloer;Hoeveelheid;Eenheid',
                '01-12;v01.g;30,00;m2',
            ]))],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $response->assertRedirect(route('calculations.imported', $calculation));

        $workbook = $calculation->workbooks()->first();
        $this->assertNotNull($workbook);
        $this->assertSame('applied', $workbook->status);

        $line = $calculation->lines()->where('room_number', '01.12')->where('unit', 'm2')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(24.5, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $line->excel_quantity, 0.001);
        $this->assertSame(QuantitySource::FromDrawing, $line->source);
        $this->assertStringContainsString('Excel takeoff: 30,00 m²', (string) $line->note);

        $this->actingAs($user)
            ->get(route('calculations.imported', $calculation))
            ->assertOk()
            ->assertSee('1 tekening gevonden', false)
            ->assertSee('1 Excelbestand gevonden', false)
            ->assertSee('vloer automatisch gekoppeld', false)
            ->assertSee('Excel bevestigd', false)
            ->assertSee('0 controleren')
            ->assertSee('Calculatiebord openen')
            ->assertDontSee('Controleren</h2>', false)
            ->assertSee('Geavanceerd / Mapping aanpassen');

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Afwijking Excel', false)
            ->assertSee('Excel takeoff: 30,00 m²', false);
    }

    public function test_matching_excel_and_pdf_values_are_accepted_without_review(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Klopt',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum - Forbo 3752", 'bg.pdf')],
            'workbooks' => [$this->csv('staat.csv', implode("\n", [
                'Ruimte nr.;Vloer;Hoeveelheid',
                '01-12;v01.g;24,50',
            ]))],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $line = $calculation->lines()->where('room_number', '01.12')->where('unit', 'm2')->first();
        $this->assertNotNull($line);
        $this->assertSame(QuantitySource::FromDrawing, $line->source);
        $this->assertEqualsWithDelta(24.5, (float) $line->excel_quantity, 0.001);

        $this->actingAs($user)
            ->get(route('calculations.imported', $calculation))
            ->assertOk()
            ->assertSee('1 vloer automatisch gekoppeld', false)
            ->assertSee('1 Excel bevestigd', false)
            ->assertSee('0 controleren')
            ->assertDontSee('welke is juist?');
    }

    public function test_warns_when_excel_area_is_an_order_of_magnitude_off_but_keeps_the_pdf_quantity(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Ordegrootte',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("01.12 Woonkamer 250 m2 v01.g\nv01.g = Marmoleum - Forbo 3752", 'bg.pdf')],
            'workbooks' => [$this->csv('staat.csv', implode("\n", [
                'Ruimte nr.;Vloer;Hoeveelheid',
                '01-12;v01.g;25,00',
            ]))],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $line = $calculation->lines()->where('room_number', '01.12')->where('unit', 'm2')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(250.0, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(25.0, (float) $line->excel_quantity, 0.001);
        $this->assertSame(QuantitySource::FromDrawing, $line->source);
        $this->assertTrue(collect($calculation->warnings ?? [])->contains(
            fn (string $warning) => str_contains($warning, 'mogelijk verkeerde ruimte')
        ));

        $this->actingAs($user)
            ->get(route('calculations.imported', $calculation))
            ->assertOk()
            ->assertSee('0 controleren')
            ->assertSee('Afwijking Excel', false)
            ->assertSee('mogelijk verkeerde ruimte', false)
            ->assertDontSee('Controleren</h2>', false);
    }

    public function test_skips_a_wall_only_workbook_automatically(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Alleen tekening',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum", 'bg.pdf')],
            'workbooks' => [$this->csv('wanden.csv', implode("\n", [
                'Wandafwerking',
                'Ruimte nr.;Afwerking;m2',
                'A-00-01;Sauswerk;12,00',
            ]))],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $workbook = $calculation->workbooks()->first();
        $this->assertNotNull($workbook);
        $this->assertSame('skipped', $workbook->status);
        $this->assertSame(1, $calculation->lines()->count());
        $this->assertEqualsWithDelta(24.5, (float) $calculation->lines()->first()?->quantity, 0.001);
    }

    public function test_creates_a_calculation_from_excel_only_without_mapping(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Excelstaat',
            'dated_on' => '2026-03-06',
            'workbooks' => [$this->csv('ruimtestaat.csv', implode("\n", [
                'Room number,Floor finish,Quantity,Unit',
                'A-00-13,v01 Marmoleum,3.70,m2',
            ]))],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $response->assertRedirect(route('calculations.imported', $calculation));
        $this->assertSame(0, $calculation->drawings()->count());
        $this->assertSame('applied', $calculation->workbooks()->first()?->status);

        $line = $calculation->lines()->where('unit', 'm2')->first();
        $this->assertNotNull($line);
        $this->assertSame('A-00-13', $line->room_number);
        $this->assertSame(QuantitySource::FromExcel, $line->source);
        $this->assertEqualsWithDelta(3.7, (float) $line->quantity, 0.001);
    }

    public function test_vakman_is_forbidden_from_import_summary_and_mapping(): void
    {
        Storage::fake('local');
        $planner = User::factory()->create();
        $this->actingAs($planner)->post(route('calculations.store'), [
            'name' => 'Intern',
            'dated_on' => '2026-03-06',
            'workbooks' => [$this->csv('staat.csv', "Ruimte nr.;Vloer\nA-00-13;v01")],
        ]);
        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $vakman = User::factory()->vakman()->create();

        $this->actingAs($vakman)
            ->get(route('calculations.imported', $calculation))
            ->assertForbidden();
        $this->actingAs($vakman)
            ->get(route('calculations.workbooks.edit', $calculation))
            ->assertForbidden();
    }

    public function test_adds_another_excel_file_and_applies_it_automatically(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Tekening eerst',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum", 'bg.pdf')],
        ]);
        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);

        $this->actingAs($user)
            ->post(route('calculations.workbooks.store', $calculation), [
                'workbooks' => [$this->csv('tweede.csv', "Ruimtenummer;Vloerafwerking;Oppervlakte\n01.12;v01.g;24,50")],
            ])
            ->assertRedirect(route('calculations.imported', $calculation));

        $this->assertSame(1, $calculation->workbooks()->count());
        $this->assertSame('applied', $calculation->workbooks()->first()?->status);
        $this->assertSame(QuantitySource::FromDrawing, $calculation->lines()->where('unit', 'm2')->first()?->source);
    }

    public function test_remembers_a_confirmed_header_for_later_imports(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Eerste',
            'dated_on' => '2026-03-06',
            'workbooks' => [$this->csv('een.csv', "Ruimte nr.;Vloer;Hoeveelheid\nA-00-13;v01;3,70")],
        ]);

        $this->assertDatabaseHas('workbook_column_memories', [
            'normalized_header' => 'ruimte nr',
            'role' => 'room_number',
        ]);

        $memory = WorkbookColumnMemory::query()->where('normalized_header', 'ruimte nr')->first();
        $this->assertNotNull($memory);
        $this->assertSame('room_number', $memory->role);
    }

    public function test_coa_drawings_and_excel_auto_link_rooms_instead_of_sending_them_all_to_review(): void
    {
        $paths = RealDrawingFixtures::coaOisterwijkDrawingPaths();
        $excel = RealDrawingFixtures::coaOisterwijkWorkbookPath();
        if (count($paths) < 7 || $excel === null) {
            $this->markTestSkipped('De 7 COA-tekeningen of het Excelbestand ontbreken.');
        }

        Storage::fake('local');
        $user = User::factory()->create();
        $drawings = [];
        foreach ($paths as $path) {
            $drawings[] = new UploadedFile($path, basename($path), 'application/pdf', null, true);
        }

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'COA Oisterwijk 3 gebouwen',
            'dated_on' => '2026-03-06',
            'drawings' => $drawings,
            'workbooks' => [new UploadedFile($excel, basename($excel), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)],
        ])->assertRedirect();

        $calculation = Calculation::query()->with(['lines', 'workbooks'])->first();
        $this->assertNotNull($calculation);
        $table = (new CalculationRoomRows)->table($calculation->lines);

        $this->assertGreaterThanOrEqual(126, $table['room_count']);
        $this->assertGreaterThanOrEqual(100, $table['floor_linked_count']);
        $this->assertGreaterThanOrEqual(90, $table['plinth_linked_count']);
        $emptyRooms = $calculation->lines
            ->filter(fn ($line) => $line->unit === WorkUnit::LinearMeter && $line->quantity === null)
            ->map(fn ($line) => $line->room_number)
            ->unique()
            ->sort()
            ->values();
        $this->assertGreaterThanOrEqual(
            $table['plinth_linked_count'],
            $table['plinth_meters_count'],
            'plintregels '.$table['plinth_linked_count']
                .', Exact '.$table['plinth_net_count']
                .', Berekend ruim '.$table['plinth_generous_count']
                .', Geschat ruim '.$table['plinth_estimated_count']
                .', Zonder m¹ '.$table['plinth_missing_meters_count']
                .' ('.$emptyRooms->take(20)->implode(', ').')'
        );
        $this->assertSame(
            0,
            $table['plinth_missing_meters_count'],
            'Exact '.$table['plinth_net_count']
                .', Berekend ruim '.$table['plinth_generous_count']
                .', Geschat ruim '.$table['plinth_estimated_count']
                .', Zonder m¹ '.$table['plinth_missing_meters_count']
        );
        $this->assertSame($table['plinth_linked_count'], $table['plinth_meters_count']);
        $this->assertSame(
            $table['plinth_meters_count'],
            $table['plinth_net_count'] + $table['plinth_generous_count'] + $table['plinth_estimated_count']
        );
        $pl01Holplint = $calculation->lines
            ->filter(fn ($line) => $line->unit === WorkUnit::LinearMeter
                && mb_strtolower((string) $line->product_code) === 'pl01'
                && is_string($line->product)
                && str_contains(mb_strtolower($line->product), 'holplint'));
        $this->assertCount(0, $pl01Holplint);
        foreach (['A-00-14', 'A-00-15', 'A-00-16', 'A-00-17'] as $number) {
            $floor = $calculation->lines->first(
                fn ($line) => $line->room_number === $number && $line->unit === WorkUnit::SquareMeter
            );
            $plinth = $calculation->lines->first(
                fn ($line) => $line->room_number === $number && $line->unit === WorkUnit::LinearMeter
            );
            $this->assertNotNull($plinth, $number.' mist een plintregel.');
            $this->assertNotNull($plinth->quantity, $number.' heeft geen m¹.');
            $this->assertGreaterThan(4.4, (float) $plinth->quantity, $number);
            $this->assertLessThan(6.5, (float) $plinth->quantity, $number);
            if ($floor?->quantity !== null) {
                $this->assertGreaterThanOrEqual(
                    15.5,
                    ((float) $plinth->quantity ** 2) / max(0.2, (float) $floor->quantity),
                    $number.' omtrek hoort niet bij de m² van de ruimte.'
                );
            }
        }
        $this->assertLessThan(40, $table['blocking_count']);
        $this->assertLessThan(5, $table['review_count']);
        $this->assertGreaterThanOrEqual(20, $table['excel_confirmed_count']);
        $this->assertFalse(collect($calculation->warnings ?? [])->contains(
            fn (string $warning) => str_contains($warning, 'Geen betrouwbare ruimtes gekoppeld')
        ));

        $thirteen = $calculation->lines
            ->first(fn ($line) => $line->room_number === 'A-00-13' && $line->unit === WorkUnit::SquareMeter);
        $this->assertNotNull($thirteen);
        $this->assertEqualsWithDelta(3.7, (float) $thirteen->excel_quantity, 0.05);

        foreach ($calculation->lines as $line) {
            if ($line->unit !== WorkUnit::SquareMeter || $line->quantity === null || $line->excel_quantity === null) {
                continue;
            }
            if ((float) $line->quantity < 8 && (float) $line->excel_quantity > 50) {
                $this->fail($line->room_number.' vergeleek PDF '.$line->quantity.' m² met Excel '.$line->excel_quantity.' m².');
            }
        }

        $this->assertLessThanOrEqual(1, collect($calculation->warnings ?? [])
            ->filter(fn (string $warning) => str_contains($warning, 'K-00-29'))
            ->count());

        $this->actingAs($user)
            ->get(route('calculations.imported', $calculation))
            ->assertOk()
            ->assertSee('ruimtes gevonden', false)
            ->assertSee('vloer automatisch gekoppeld', false)
            ->assertSee('plint automatisch gekoppeld', false)
            ->assertSee('met m¹', false)
            ->assertSee('Excel bevestigd', false);
    }

    public function test_compares_computed_excel_area_instead_of_a_width_and_collapses_duplicate_rooms(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Takeoff',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf(implode("\n", [
                'A-00-13 MIVA T 3,70 m2 v04',
                'K-00-21 Toilet 1,70 m2 v04',
                'K-00-29 Toilet 1,80 m2 v04',
                'K-00-31 Toilet 1,80 m2 v04',
                'v04 = PU gietvloer',
            ]), 'bg.pdf')],
            'workbooks' => [$this->csv('vloer.csv', implode("\n", [
                'Gietvloer v04;;;;;;',
                'Ruimte nr.;Naam;L;B;n;;m2',
                'A-00-13;MIVA T;1.7;2.175;1;;3.6975',
                'K-00-21;Toilet;1.105;1.5;1;;1.6575',
                'K-00-29;Toilet;1.5;1.2;1;;1.8',
                'K-00-29;Toilet;1.175;1.595;1;;1.874125',
                'K-00-31;Toilet;1.1;1.595;1;;1.7545',
            ]))],
        ])->assertRedirect();

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);

        $thirteen = $calculation->lines()->where('room_number', 'A-00-13')->where('unit', 'm2')->first();
        $this->assertNotNull($thirteen);
        $this->assertEqualsWithDelta(3.6975, (float) $thirteen->excel_quantity, 0.001);
        $this->assertSame(QuantitySource::FromDrawing, $thirteen->source);

        $twentyNine = $calculation->lines()->where('room_number', 'K-00-29')->where('unit', 'm2')->first();
        $this->assertNotNull($twentyNine);
        $this->assertEqualsWithDelta(1.8, (float) $twentyNine->quantity, 0.001);
        $this->assertEqualsWithDelta(3.674125, (float) $twentyNine->excel_quantity, 0.001);
        $this->assertSame(QuantitySource::FromDrawing, $twentyNine->source);

        $warnings = collect($calculation->warnings ?? []);
        $this->assertFalse($warnings->contains(fn (string $warning) => str_contains($warning, '1.105,00')));
        $this->assertFalse($warnings->contains(fn (string $warning) => str_contains($warning, 'K-00-29')));

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Afwijking Excel', false)
            ->assertSee('Excel takeoff:', false);
    }

    public function test_confirms_floor_finish_without_comparing_area_when_excel_has_no_m2_column(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Alleen code',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf("A-00-13 MIVA T 3,70 m2 v04\nv04 = PU gietvloer", 'bg.pdf')],
            'workbooks' => [$this->csv('vloer.csv', implode("\n", [
                'Ruimte nr.;Vloer',
                'A-00-13;v04',
            ]))],
        ])->assertRedirect();

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $line = $calculation->lines()->where('room_number', 'A-00-13')->where('unit', 'm2')->first();
        $this->assertNotNull($line);
        $this->assertNull($line->excel_quantity);
        $this->assertSame('v04', $line->excel_product_code);
        $this->assertSame(QuantitySource::FromDrawing, $line->source);
        $this->assertFalse(collect($calculation->warnings ?? [])->contains(
            fn (string $warning) => str_contains($warning, 'PDF:')
        ));
    }

    private function pdf(string $text, string $name): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }

    private function csv(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
