<?php

namespace Tests\Unit;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
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

    private function floor(
        Calculation $calculation,
        CalculationDrawing $drawing,
        string $number,
        string $name,
        string $code,
        float $quantity,
        string $product,
    ): void {
        CalculationLine::query()->create([
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
