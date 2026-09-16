<?php

namespace Tests\Feature;

use App\Enums\QuantitySource;
use App\Enums\UserRole;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Tests\Support\RealDrawingFixtures;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class CalculationTakeoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_calculations(): void
    {
        $this->get(route('calculations.index'))->assertRedirect(route('login'));
    }

    public function test_vakman_is_forbidden_from_calculations(): void
    {
        $user = User::factory()->vakman()->create();

        $this->actingAs($user)
            ->get(route('calculations.index'))
            ->assertForbidden();
    }

    public function test_planner_sees_calculatie_between_projecten_and_archief(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('calculations.index'))
            ->assertOk()
            ->assertSee('Nieuwe calculatie')
            ->getContent();

        $this->assertMatchesRegularExpression('/Projecten\s*<\/a>[\s\S]+Calculatie\s*<\/a>[\s\S]+Archief\s*<\/a>/', $html);
    }

    public function test_create_form_shows_a_progress_overlay_for_the_wait(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertSee('Bestanden verwerken')
            ->assertSee('Tekeningen en Excel uitlezen. Dit kan een paar minuten duren.')
            ->assertSee('data-calculation-progress', false);
    }

    public function test_create_review_correct_totals_and_excel_flow(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Offerte Oisterwijk',
            'client_name' => 'COA',
            'project_name' => 'AZC Activiteitengebouw',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf(<<<'TXT'
01.12 Woonkamer 24,5 m2 v01.g
01.13 Hal 12,0 m2 v01.g
v01.g = Marmoleum - Forbo 3752
TXT, 'bg.pdf')],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $response->assertRedirect(route('calculations.imported', $calculation));

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Woonkamer')
            ->assertSee('Marmoleum - Forbo 3752')
            ->assertSee('Zeker')
            ->assertSee('36,50')
            ->assertSee('24,50')
            ->assertSee('Vloercode')
            ->assertSee('m¹')
            ->assertSee('2 ruimtes gevonden | 2 zeker | 0 controleren')
            ->assertSee('✓ Calculatie volledig gecontroleerd');

        $woonkamer = $calculation->lines()->where('room_number', '01.12')->first();
        $this->assertNotNull($woonkamer);

        $this->actingAs($user)->patch(route('calculations.update', $calculation), [
            'name' => 'Offerte Oisterwijk',
            'client_name' => 'COA',
            'project_name' => 'AZC Activiteitengebouw',
            'dated_on' => '2026-03-06',
            'status' => 'reviewed',
            'lines' => $calculation->lines->map(fn (CalculationLine $line) => [
                'id' => $line->id,
                'room_number' => $line->room_number,
                'room_name' => $line->id === $woonkamer->id ? 'Woonkamer groot' : $line->room_name,
                'product_code' => $line->product_code,
                'product' => $line->product,
                'quantity' => $line->id === $woonkamer->id ? '30' : $line->quantity,
                'unit' => $line->unit->value,
                'source' => $line->source->value,
                'note' => $line->note,
            ])->all(),
        ])->assertRedirect(route('calculations.show', $calculation));

        $woonkamer->refresh();
        $this->assertSame('Woonkamer groot', $woonkamer->room_name);
        $this->assertEqualsWithDelta(30.0, (float) $woonkamer->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $woonkamer->source);
        $this->assertEqualsWithDelta(24.5, (float) $woonkamer->original_quantity, 0.001);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Woonkamer groot')
            ->assertSee('42,00')
            ->assertSee('Exporteren naar Excel')
            ->assertSee('nog controleren; die regels gaan ook mee')
            ->assertDontSee('Excel geblokkeerd')
            ->assertDontSee('Calculatie volledig gecontroleerd');

        $this->actingAs($user)
            ->get(route('calculations.excel', $calculation))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($user)
            ->post(route('calculations.lines.confirm', [$calculation, $woonkamer]))
            ->assertRedirect(route('calculations.show', $calculation))
            ->assertSessionHasNoErrors();

        $woonkamer->refresh();
        $this->assertNotNull($woonkamer->confirmed_at);

        $excel = $this->actingAs($user)->get(route('calculations.excel', $calculation));
        $excel->assertOk()->assertDownload();

        $spreadsheet = IOFactory::load($excel->getFile()->getPathname());
        $this->assertSame(['Details', 'Totalen'], $spreadsheet->getSheetNames());

        $details = $spreadsheet->getSheetByName('Details');
        $this->assertNotNull($details);
        $this->assertSame('Productcode', $details->getCell('A1')->getValue());
        $this->assertNotEmpty($details->getTableCollection());
        $this->assertNotNull($details->getTableByName('Details'));
        $this->assertSame(DataType::TYPE_NUMERIC, $details->getCell('E2')->getDataType());
        $this->assertIsFloat($details->getCell('E2')->getValue() + 0);
        $this->assertSame(NumberFormat::FORMAT_NUMBER_00, $details->getCell('E2')->getStyle()->getNumberFormat()->getFormatCode());

        $totals = $spreadsheet->getSheetByName('Totalen');
        $this->assertNotNull($totals);
        $this->assertNotNull($totals->getTableByName('Totalen'));
        $this->assertSame('v01.g', $totals->getCell('A2')->getValue());
        $this->assertEqualsWithDelta(42.0, (float) $totals->getCell('C2')->getValue(), 0.001);
        $this->assertSame(DataType::TYPE_NUMERIC, $totals->getCell('C2')->getDataType());
        $this->assertSame(NumberFormat::FORMAT_NUMBER_00, $totals->getCell('C2')->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_multiple_drawings_keep_rooms_from_each_pdf(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Twee tekeningen',
            'dated_on' => '2026-03-06',
            'drawings' => [
                $this->pdf("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum - Forbo 3752", 'bg.pdf'),
                $this->pdf("02.01 Kantoor 18,0 m2 v04\nv04 = Gietvloer v.z.v. matte coating\npl02 = Holplint", 'verdieping.pdf'),
            ],
        ])->assertRedirect();

        $calculation = Calculation::query()->with(['lines', 'drawings'])->first();
        $this->assertNotNull($calculation);
        $this->assertCount(2, $calculation->drawings);
        $this->assertTrue($calculation->lines->contains(fn (CalculationLine $line) => $line->room_number === '01.12'));
        $this->assertTrue($calculation->lines->contains(fn (CalculationLine $line) => $line->room_number === '02.01'));
        $holplint = $calculation->lines->first(
            fn (CalculationLine $line) => $line->room_number === '02.01' && $line->unit === WorkUnit::LinearMeter
        );
        $this->assertNotNull($holplint);
        $this->assertSame('pl02', $holplint->product_code);
        $this->assertNotNull($holplint->quantity);
        $this->assertSame(QuantitySource::Calculated, $holplint->source);
        $this->assertStringContainsString('ruime calculatieschatting', (string) $holplint->note);
        $this->assertTrue($calculation->drawings->every(fn ($drawing) => Storage::disk('local')->exists($drawing->file_path)));
    }

    public function test_complete_flow_with_supplied_bouwtekening(): void
    {
        $path = RealDrawingFixtures::oisterwijkDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('De aangeleverde bouwtekening ontbreekt.');
        }

        Storage::fake('local');
        $user = User::factory()->create();
        $upload = new UploadedFile($path, 'plattegrond.pdf', 'application/pdf', null, true);

        $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'AZC Oisterwijk',
            'client_name' => 'COA',
            'project_name' => 'Activiteitengebouw',
            'dated_on' => '2026-03-06',
            'drawings' => [$upload],
        ])->assertRedirect();

        $calculation = Calculation::query()->with('lines')->first();
        $this->assertNotNull($calculation);
        $this->assertTrue($calculation->lines->contains(fn (CalculationLine $line) => $line->room_number === 'A-00-12'));

        $speel = $calculation->lines->first(
            fn (CalculationLine $line) => $line->room_number === 'A-00-12' && $line->unit === WorkUnit::SquareMeter
        );
        $this->assertNotNull($speel);
        $this->assertSame('v02', $speel->product_code);
        $this->assertNotEmpty($speel->product);

        $gietvloer = $calculation->lines->first(
            fn (CalculationLine $line) => $line->product_code === 'v04' && $line->unit === WorkUnit::SquareMeter
        );
        $this->assertNotNull($gietvloer);
        $this->assertTrue($calculation->lines->contains(
            fn (CalculationLine $line) => $line->room_number === $gietvloer->room_number
                && $line->product_code === 'pl02'
                && $line->unit === WorkUnit::LinearMeter
        ));

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('A-00-12')
            ->assertSee('v02')
            ->assertSee('v01.g')
            ->assertSee('m¹')
            ->assertSee('controleren');
    }

    public function test_review_page_shows_two_decimals_and_one_row_per_room(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'v01.d',
            'product' => 'Marmoleum - Forbo 3430',
            'quantity' => 78.9,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'pl01',
            'product' => 'Aluminium plakplint',
            'quantity' => null,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Review,
        ]);

        $html = $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('78,90')
            ->assertSee('1 ruimtes gevonden | 1 zeker | 0 controleren')
            ->assertSee('Zeker')
            ->assertSee('Vloercode')
            ->assertSee('Plintcode')
            ->assertSee('m¹')
            ->getContent();

        $this->assertStringNotContainsString('78.900', $html);
        $this->assertSame(1, substr_count($html, 'data-calc-room="1"'));
        $this->assertStringContainsString('value="78,90"', $html);
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $html);
        $this->assertStringContainsString('data-filter="review"', $html);
    }

    public function test_review_page_shows_a_generous_plinth_as_ready(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-13',
            'room_name' => 'MIVA T',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'A-00-13',
            'room_name' => 'MIVA T',
            'product_code' => 'pl02',
            'product' => 'Holplint',
            'quantity' => 12.84,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Calculated,
            'note' => '12,84 m¹ – volledige omtrek; deuropening niet afgetrokken.',
            'calculation_trace' => json_encode([
                'meters' => 12.84,
                'source' => QuantitySource::Calculated->value,
                'trace' => '12,84 m¹ – volledige omtrek; deuropening niet afgetrokken.',
                'status' => 'generous',
                'gross' => 12.84,
                'doors' => [],
                'net' => 12.84,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Berekend ruim')
            ->assertSee('volledige omtrek; deuropening niet afgetrokken')
            ->assertSee('1 ruimtes gevonden | 0 zeker | 1 berekend ruim | 0 controleren')
            ->assertSee('✓ Calculatie volledig gecontroleerd')
            ->assertDontSee('Ontbreekt')
            ->assertDontSee('Excel geblokkeerd');
    }

    public function test_excel_downloads_while_a_plinth_quantity_is_still_missing(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $floor = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 78.9,
            'original_quantity' => 78.9,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
            'found_source' => QuantitySource::FromDrawing,
        ]);
        $plinth = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'pl02',
            'product' => 'Holplint',
            'quantity' => null,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Review,
            'found_source' => QuantitySource::Review,
        ]);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Exporteren naar Excel')
            ->assertSee('nog controleren; die regels gaan ook mee')
            ->assertDontSee('Excel geblokkeerd');

        $this->actingAs($user)
            ->get(route('calculations.excel', $calculation))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($user)
            ->post(route('calculations.lines.confirm', [$calculation, $floor]))
            ->assertRedirect(route('calculations.show', $calculation))
            ->assertSessionHasErrors('confirm');

        $this->actingAs($user)->patch(route('calculations.update', $calculation), [
            'name' => 'Wepro',
            'dated_on' => '2026-03-06',
            'status' => 'concept',
            'lines' => [
                [
                    'id' => $floor->id,
                    'room_number' => 'A-00-01',
                    'room_name' => 'RECREATIE',
                    'product_code' => 'v04',
                    'product' => 'Gietvloer',
                    'quantity' => '78,90',
                    'unit' => WorkUnit::SquareMeter->value,
                    'source' => QuantitySource::FromDrawing->value,
                    'note' => null,
                ],
                [
                    'id' => $plinth->id,
                    'room_number' => 'A-00-01',
                    'room_name' => 'RECREATIE',
                    'product_code' => 'pl02',
                    'product' => 'Holplint',
                    'quantity' => '34,25',
                    'unit' => WorkUnit::LinearMeter->value,
                    'source' => QuantitySource::Review->value,
                    'note' => null,
                ],
            ],
        ])->assertRedirect(route('calculations.show', $calculation));

        $plinth->refresh();
        $this->assertEqualsWithDelta(34.25, (float) $plinth->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $plinth->source);

        $this->actingAs($user)
            ->post(route('calculations.lines.confirm', [$calculation, $plinth]))
            ->assertRedirect(route('calculations.show', $calculation))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('✓ Calculatie volledig gecontroleerd')
            ->assertSee('34,25');

        $excel = $this->actingAs($user)->get(route('calculations.excel', $calculation));
        $excel->assertOk()->assertDownload();

        $spreadsheet = IOFactory::load($excel->getFile()->getPathname());
        $totals = $spreadsheet->getSheetByName('Totalen');
        $this->assertNotNull($totals);
        $plinthRow = null;
        foreach (range(2, 4) as $row) {
            if ($totals->getCell('A'.$row)->getValue() === 'pl02') {
                $plinthRow = $row;
                break;
            }
        }
        $this->assertNotNull($plinthRow);
        $this->assertEqualsWithDelta(34.25, (float) $totals->getCell('C'.$plinthRow)->getValue(), 0.001);
        $this->assertSame('m¹', $totals->getCell('D'.$plinthRow)->getValue());
    }

    public function test_excel_export_is_rejected_when_there_are_no_lines(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Leeg',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('calculations.excel', $calculation))
            ->assertRedirect(route('calculations.show', $calculation))
            ->assertSessionHasErrors('excel');
    }

    public function test_uitvoerder_cannot_create_a_calculation(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => UserRole::Uitvoerder]);

        $this->actingAs($user)
            ->post(route('calculations.store'), [
                'name' => 'Mag niet',
                'dated_on' => '2026-03-06',
                'drawings' => [$this->pdf('01.12 Hal 10 m2', 'plan.pdf')],
            ])
            ->assertForbidden();

        $this->assertSame(0, Calculation::query()->count());
    }

    private function pdf(string $text, string $name): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }
}
