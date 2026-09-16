<?php

namespace Tests\Unit;

use App\Enums\CheckStatus;
use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationBoardService;
use App\Support\MaterialColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculationBoardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_keeps_a_stable_color_per_floor_code(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $first = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg-01.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $second = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg-02.pdf',
            'file_path' => 'calculations/1/b.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $this->floor($calculation, $first, '1.41', 'leer en meer 1', 'v04', 12.7);
        $this->floor($calculation, $second, '2.10', 'kantoor', 'v04', 20.0);
        $this->floor($calculation, $first, '1.42', 'hal', 'v09', 8.5);

        $payload = app(CalculationBoardService::class)->payload($calculation->fresh(['lines.drawing', 'drawings']));
        $byNumber = collect($payload['rooms'])->keyBy('number');

        $this->assertSame(MaterialColor::fromCode('v04'), $byNumber['1.41']['material_color']);
        $this->assertSame($byNumber['1.41']['material_color'], $byNumber['2.10']['material_color']);
        $this->assertNotSame($byNumber['1.41']['material_color'], $byNumber['1.42']['material_color']);
        $this->assertCount(2, $payload['materials']);
        $v04 = collect($payload['materials'])->firstWhere('key', 'v04');
        $this->assertSame(32.7, $v04['m2']);
        $this->assertSame('bg-01', $byNumber['1.41']['group']);
        $this->assertArrayNotHasKey('marker', $byNumber['1.41']);
    }

    public function test_payload_lists_multiple_floor_finishes_on_one_room(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg-01.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $main = $this->floor($calculation, $drawing, 'A-00-01', 'RECREATIE', 'v01.d', 74.7);
        $main->update(['finish_role' => FinishRole::Main, 'room_area' => 78.9, 'product' => 'Marmoleum']);
        $local = $this->floor($calculation, $drawing, 'A-00-01', 'RECREATIE', 'v09', 4.2);
        $local->update(['finish_role' => FinishRole::Local, 'room_area' => 78.9, 'product' => 'Schoonloopmat']);

        $payload = app(CalculationBoardService::class)->payload($calculation->fresh(['lines.drawing', 'drawings']));
        $room = collect($payload['rooms'])->firstWhere('number', 'A-00-01');

        $this->assertNotNull($room);
        $this->assertCount(1, $payload['rooms']);
        $this->assertCount(2, $room['floors']);
        $this->assertEqualsWithDelta(78.9, (float) $room['m2'], 0.001);
        $this->assertContains('v09', $room['material_keys']);
        $this->assertStringContainsString('v09', $room['floor_codes_label']);
        $v09 = collect($payload['materials'])->firstWhere('key', 'v09');
        $this->assertEqualsWithDelta(4.2, (float) $v09['m2'], 0.001);
    }

    public function test_board_room_still_works_without_a_drawing_contour(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $line = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => '1.10',
            'room_name' => 'hal',
            'product_code' => 'v02',
            'product' => 'Marmoleum',
            'quantity' => 10,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        $room = app(CalculationBoardService::class)->roomByLine($calculation->fresh(['lines']), $line);

        $this->assertNotNull($room);
        $this->assertSame('1.10', $room['number']);
        $this->assertNull($room['drawing_id']);
        $this->assertSame('Zonder tekening', $room['group']);
        $this->assertSame(CheckStatus::Certain->value, $room['status']);
    }

    public function test_payload_colors_a_local_patch_separately_from_the_room_floor(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg-01.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $this->floor($calculation, $drawing, 'A-00-01', 'RECREATIE', 'v01.d', 78.9);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 2,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'v09',
            'product' => 'Schoonloopmat',
            'quantity' => null,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Local,
            'source' => QuantitySource::Review,
            'calculation_trace' => json_encode([
                'role' => 'local_floor',
                'page' => 1,
                'x' => 448.4,
                'y' => 828.9,
                'page_width' => 1684.0,
                'page_height' => 2979.0,
            ]),
        ]);

        $payload = app(CalculationBoardService::class)->payload($calculation->fresh(['lines.drawing', 'drawings']));
        $room = collect($payload['rooms'])->firstWhere('number', 'A-00-01');

        $this->assertSame('v01.d', $room['floor_code']);
        $this->assertSame(MaterialColor::fromCode('v01.d'), $room['material_color']);
        $this->assertCount(2, $room['floors']);
        $this->assertSame('local', $room['floors'][1]['role']);
        $this->assertSame('v09', $room['floors'][1]['code']);
        $this->assertSame(MaterialColor::fromCode('v09'), $room['floors'][1]['material_color']);
        $this->assertNull($room['floors'][1]['overlay']);
        $this->assertNull($room['contour']);
        $this->assertTrue(collect($payload['materials'])->contains(fn (array $material) => $material['key'] === 'v09'));
    }

    public function test_payload_fills_only_a_reliable_room_contour(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg-01.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $floor = $this->floor($calculation, $drawing, '1.10', 'hal', 'v02', 18.5);
        $floor->update([
            'finish_role' => FinishRole::Main,
            'room_area' => 18.5,
            'calculation_trace' => json_encode([
                'role' => 'room_floor',
                'reliable' => true,
                'page' => 1,
                'rects' => [
                    ['x' => 0.10, 'y' => 0.20, 'w' => 0.08, 'h' => 0.05],
                ],
            ]),
        ]);

        $payload = app(CalculationBoardService::class)->payload($calculation->fresh(['lines.drawing', 'drawings']));
        $room = collect($payload['rooms'])->firstWhere('number', '1.10');

        $this->assertNotNull($room['contour']);
        $this->assertTrue($room['contour']['reliable']);
        $this->assertSame(1, $room['contour']['page']);
        $this->assertEqualsWithDelta(0.08, (float) $room['contour']['rects'][0]['w'], 0.0001);
        $this->assertSame(MaterialColor::fromCode('v02'), $room['material_color']);
    }

    public function test_payload_does_not_invent_a_color_without_a_floor_code(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => '2.01',
            'room_name' => 'hal',
            'product_code' => null,
            'product' => null,
            'quantity' => 10,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Main,
            'source' => QuantitySource::Review,
        ]);

        $payload = app(CalculationBoardService::class)->payload($calculation->fresh(['lines.drawing', 'drawings']));
        $room = collect($payload['rooms'])->firstWhere('number', '2.01');

        $this->assertNull($room['material_color']);
        $this->assertNull($room['floors'][0]['material_color']);
    }

    private function floor(
        Calculation $calculation,
        CalculationDrawing $drawing,
        string $number,
        string $name,
        string $code,
        float $m2,
    ): CalculationLine {
        return CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => (int) $calculation->lines()->max('sort_order') + 1,
            'room_number' => $number,
            'room_name' => $name,
            'product_code' => $code,
            'product' => $code === 'v04' ? 'Gietvloer' : 'Tapijt',
            'quantity' => $m2,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
    }
}
