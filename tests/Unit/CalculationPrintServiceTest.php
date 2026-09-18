<?php

namespace Tests\Unit;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationBoardService;
use App\Services\QuoteCalculation\CalculationPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculationPrintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_keeps_only_selected_drawings_and_materials(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $ground = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'begane grond.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $floor = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'verdieping 1.pdf',
            'file_path' => 'calculations/1/b.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $this->floor($calculation, $ground, '1.01', 'hal', 'v01.d', 74.7, 'Marmoleum');
        $this->floor($calculation, $ground, '1.02', 'entree', 'v09', 4.2, 'Schoonloopmat');
        $this->floor($calculation, $floor, '2.01', 'kantoor', 'v01.d', 20.0, 'Marmoleum');

        $document = app(CalculationPrintService::class)->document($calculation->fresh(['lines.drawing', 'drawings']), [
            'include' => ['colored', 'legend', 'area_totals', 'room_list'],
            'drawing_ids' => [$ground->id],
            'material_keys' => ['v01.d'],
            'output' => 'pdf',
        ]);

        $this->assertSame(['begane grond'], array_column($document['drawings'], 'label'));
        $this->assertSame(['v01.d'], array_column($document['materials'], 'code'));
        $this->assertSame(['1.01'], array_column($document['rooms'], 'number'));
        $this->assertSame(74.7, $document['area_totals'][0]['quantity']);
        $this->assertSame('1.01', $document['room_lists'][0]['rooms'][0]['number']);
        $this->assertTrue($document['include']['colored']);
        $this->assertFalse($document['include']['details']);
    }

    public function test_room_matches_materials_like_the_board_filter(): void
    {
        $print = app(CalculationPrintService::class);
        $room = [
            'drawing_id' => 8,
            'material_key' => 'v01.d',
            'material_keys' => ['v01.d', 'v09'],
        ];

        $this->assertTrue($print->roomMatchesMaterials($room, []));
        $this->assertTrue($print->roomMatchesMaterials($room, ['v09']));
        $this->assertFalse($print->roomMatchesMaterials($room, ['v04']));
        $this->assertFalse($print->roomMatches($room, [3], ['v01.d']));
        $this->assertTrue($print->roomMatches($room, [8], ['v01.d']));
    }

    public function test_document_keeps_one_drawing_on_its_own_and_seven_drawings_apart(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawings = [];
        foreach (range(1, 7) as $index) {
            $drawings[] = CalculationDrawing::query()->create([
                'calculation_id' => $calculation->id,
                'original_filename' => "tekening-{$index}.pdf",
                'file_path' => "calculations/1/{$index}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 1,
            ]);
        }
        $this->floor($calculation, $drawings[0], 'A-01-01', 'hal', 'v04', 12.7, 'Gietvloer');

        $print = app(CalculationPrintService::class);
        $one = $print->document($calculation->fresh(['lines.drawing', 'drawings']), [
            'include' => ['colored', 'rooms', 'codes', 'legend'],
            'drawing_ids' => [$drawings[0]->id],
            'material_keys' => [],
            'output' => 'pdf',
        ]);
        $seven = $print->document($calculation->fresh(['lines.drawing', 'drawings']), [
            'include' => ['colored', 'rooms', 'codes', 'legend'],
            'drawing_ids' => array_map(fn (CalculationDrawing $drawing): int => $drawing->id, $drawings),
            'material_keys' => [],
            'output' => 'pdf',
        ]);

        $this->assertCount(1, $one['drawings']);
        $this->assertSame('tekening-1', $one['drawings'][0]['label']);
        $this->assertCount(7, $seven['drawings']);
        $this->assertSame(
            ['tekening-1', 'tekening-2', 'tekening-3', 'tekening-4', 'tekening-5', 'tekening-6', 'tekening-7'],
            array_column($seven['drawings'], 'label'),
        );
    }

    public function test_document_keeps_room_geometry_and_rooms_without_a_contour(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'begane grond.pdf',
            'file_path' => 'calculations/1/a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $this->floor($calculation, $drawing, 'A-01-01', 'hal', 'v04', 12.7, 'Gietvloer');
        CalculationLine::query()->where('room_number', 'A-01-01')->update([
            'calculation_trace' => json_encode([
                'role' => 'room_floor',
                'reliable' => true,
                'page' => 1,
                'rects' => [
                    ['x' => 0.41, 'y' => 0.62, 'w' => 0.10, 'h' => 0.08],
                ],
            ]),
        ]);
        $this->floor($calculation, $drawing, 'A-01-99', 'entree', 'v09', 4.2, 'Schoonloopmat');

        $document = app(CalculationPrintService::class)->document($calculation->fresh(['lines.drawing', 'drawings']), [
            'include' => ['colored', 'codes'],
            'drawing_ids' => [$drawing->id],
            'material_keys' => [],
            'output' => 'pdf',
        ]);
        $byNumber = collect($document['rooms'])->keyBy('number');

        $this->assertNotNull($byNumber['A-01-01']['contour']);
        $this->assertSame(1, $byNumber['A-01-01']['contour']['page']);
        $this->assertEqualsWithDelta(0.41, (float) $byNumber['A-01-01']['contour']['rects'][0]['x'], 0.0001);
        $this->assertNull($byNumber['A-01-99']['contour']);
        $this->assertTrue($document['include']['colored']);
        $this->assertTrue($document['include']['codes']);
        $this->assertFalse($document['include']['rooms']);
        $this->assertFalse($document['include']['legend']);
    }

    public function test_print_keeps_the_same_room_assignments_as_the_board_for_a_drawing(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'COA Oisterwijk 3 gebouwen',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $kelder = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => '2401531_TEK_BK5_1_K_01.pdf',
            'file_path' => 'calculations/1/k01.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $ground = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => '2401531_TEK_BK5_1_A_00.pdf',
            'file_path' => 'calculations/1/a00.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $this->floor($calculation, $kelder, 'K-01-01', 'berging', 'v04', 12.7, 'Gietvloer');
        $this->floor($calculation, $kelder, 'K-01-06', 'fietsen', 'v09', 4.2, 'Schoonloopmat');
        $this->floor($calculation, $kelder, 'K-01-10', 'techniek', 'v04', 8.5, 'Gietvloer');
        CalculationLine::query()->where('room_number', 'K-01-10')->update([
            'calculation_trace' => json_encode([
                'role' => 'room_floor',
                'reliable' => true,
                'page' => 1,
                'rects' => [['x' => 0.41, 'y' => 0.62, 'w' => 0.10, 'h' => 0.08]],
            ]),
        ]);
        $this->floor($calculation, $kelder, 'K-01-02', 'hal', 'v01.d', 18.0, 'Marmoleum');
        $main = $this->floor($calculation, $kelder, 'K-01-12', 'recreatie', 'v06.b', 40.0, 'PVC');
        $main->forceFill(['finish_role' => FinishRole::Main, 'room_area' => 78.9])->save();
        $this->floor($calculation, $kelder, 'K-01-12', 'recreatie', 'v01.i', 20.0, 'Marmoleum')
            ->forceFill(['finish_role' => FinishRole::Local, 'room_area' => 78.9])->save();
        $this->floor($calculation, $kelder, 'K-01-12', 'recreatie', 'v01.d', 18.9, 'Marmoleum')
            ->forceFill(['finish_role' => FinishRole::Local, 'room_area' => 78.9])->save();
        $this->floor($calculation, $ground, 'A-00-15', 'toilet', 'v01.g', 1.5, 'Marmoleum');

        $fresh = $calculation->fresh(['lines.drawing', 'drawings']);
        $board = app(CalculationBoardService::class)->payload($fresh);
        $print = app(CalculationPrintService::class)->document($fresh, [
            'include' => ['colored', 'rooms', 'codes', 'legend'],
            'drawing_ids' => [$kelder->id],
            'material_keys' => [],
            'output' => 'pdf',
        ]);

        $this->assertSame(
            $this->assignments($board['rooms'], $kelder->id),
            $this->assignments($print['rooms'], $kelder->id),
        );
        $this->assertSame(
            ['K-01-01', 'K-01-02', 'K-01-06', 'K-01-10', 'K-01-12'],
            collect($print['rooms'])->pluck('number')->sort()->values()->all(),
        );
        $this->assertSame('v06.b + v01.i + v01.d', collect($print['rooms'])->firstWhere('number', 'K-01-12')['floor_codes_label']);
        $this->assertSame(
            collect($board['rooms'])->firstWhere('number', 'K-01-12')['material_color'],
            collect($print['rooms'])->firstWhere('number', 'K-01-12')['material_color'],
        );
        $this->assertSame(['v01.d', 'v01.i', 'v04', 'v06.b', 'v09'], array_column($print['drawings'][0]['materials'], 'code'));
        $this->assertSame(['v01.d', 'v01.i', 'v04', 'v06.b', 'v09'], array_column($print['materials'], 'code'));
        $this->assertFalse(in_array('v01.g', array_column($print['materials'], 'code'), true));
        $this->assertFalse(in_array('A-00-15', array_column($print['rooms'], 'number'), true));
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array{key: string, number: string, codes: string}>
     */
    private function assignments(array $rooms, int $drawingId): array
    {
        $rows = [];
        foreach ($rooms as $room) {
            if ((int) ($room['drawing_id'] ?? 0) !== $drawingId) {
                continue;
            }
            $rows[] = [
                'key' => (string) $room['key'],
                'number' => (string) $room['number'],
                'codes' => (string) (($room['floor_codes_label'] ?: $room['floor_code']) ?? ''),
            ];
        }
        usort($rows, fn (array $left, array $right): int => [$left['key'], $left['number']] <=> [$right['key'], $right['number']]);

        return $rows;
    }

    private function floor(
        Calculation $calculation,
        CalculationDrawing $drawing,
        string $number,
        string $name,
        string $code,
        float $quantity,
        string $product,
    ): CalculationLine {
        return CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => $calculation->lines()->count() + 1,
            'room_number' => $number,
            'room_name' => $name,
            'product_code' => $code,
            'product' => $product,
            'quantity' => $quantity,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
    }
}
