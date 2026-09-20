<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\AreaWithoutM2Analyzer;
use App\Services\AreaWithoutM2Trial\RasterPageReader;
use App\Services\Meetstaat\PdfPageGeometry;
use Mockery;
use Tests\Support\DimensionedRoomPdf;
use Tests\TestCase;

class AreaWithoutM2AnalyzerTest extends TestCase
{
    public function test_uses_wall_bound_length_times_width_and_keeps_printed_square_metres_as_control_only(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage([
            ['text' => '99 m2', 'x' => 275.0, 'y' => 570.0],
            ['text' => '6975', 'x' => 250.0, 'y' => 455.0],
        ], extraWalls: [
            ['x1' => 50.0, 'y1' => 450.0, 'x2' => 545.0, 'y2' => 450.0, 'axis' => 'h'],
        ])]);

        $room = $result['rooms'][0];

        $this->assertSame('Brontype: PDF met tekstlaag', $result['source_label']);
        $this->assertSame('OPSLAG', $room['room_name']);
        $this->assertSame('A-00-03', $room['room_number']);
        $this->assertSame([2000, 3500], $room['recognized_dimensions']);
        $this->assertSame(3500, $room['horizontal_mm']);
        $this->assertSame(2000, $room['vertical_mm']);
        $this->assertSame('lengte × breedte', $room['method']);
        $this->assertSame('3500 × 2000 → 7,00 m²', $room['trace']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(99.0, $room['printed_m2']);
        $this->assertSame(-92.0, $room['deviation_m2']);
        $this->assertSame(0.9, $room['confidence']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_REVIEW, $room['status']);
        $this->assertSame('Oranje – controleren', $room['status_label']);
        $this->assertStringContainsString('x=250, y=485', $room['horizontal_position']);
        $this->assertStringContainsString('onderwand', $room['horizontal_walls']);
        $this->assertStringContainsString('x=70, y=590', $room['vertical_position']);
        $this->assertStringContainsString('linkerwand', $room['vertical_walls']);
        $this->assertContains(6975, array_column($room['rejected'], 'mm'));
        $this->assertStringContainsString(
            'totale/stramienmaat',
            collect($room['rejected'])->firstWhere('mm', 6975)['reason'] ?? '',
        );
        $this->assertStringContainsString('vulcontour', (string) $room['wall_left']);
        $this->assertNotNull($room['overlay']);
        $this->assertSame(16.81, $room['overlay']['left']);
        $this->assertSame(16.86, $room['overlay']['top']);
        $this->assertSame(10.0, $room['scale_mm_per_px']);
    }

    public function test_does_not_treat_a_printed_area_as_the_calculated_area(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage([
            ['text' => '7 m2', 'x' => 275.0, 'y' => 570.0],
        ], dimensions: false)]);

        $room = $result['rooms'][0];

        $this->assertNull($room['calculated_m2']);
        $this->assertSame(7.0, $room['printed_m2']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_UNAVAILABLE, $room['status']);
        $this->assertSame('Rood – niet berekenbaar', $room['status_label']);
        $this->assertSame('Geen betrouwbare lokale maatvoering gekoppeld aan de wanden van deze ruimte.', $room['trace']);
    }

    public function test_does_not_pair_nearby_numbers_without_wall_binding(): void
    {
        $result = $this->analyzer()->analyzePages([$this->page([
            ['text' => 'OPSLAG', 'x' => 50.0, 'y' => 80.0],
            ['text' => 'A-00-03', 'x' => 50.0, 'y' => 70.0],
            ['text' => '6975', 'x' => 48.0, 'y' => 40.0],
            ['text' => '3500', 'x' => 40.0, 'y' => 20.0],
            ['text' => '2000', 'x' => 10.0, 'y' => 60.0],
            ['text' => '7 m2', 'x' => 50.0, 'y' => 60.0],
        ])]);

        $room = $result['rooms'][0];

        $this->assertNull($room['calculated_m2']);
        $this->assertNotSame('lengte × breedte', $room['method']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_UNAVAILABLE, $room['status']);
        $this->assertContains(6975, array_column($room['rejected'], 'mm'));
    }

    public function test_reads_millimetres_as_metres_for_a_matching_control_value(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage([
            ['text' => '7 m2', 'x' => 275.0, 'y' => 570.0],
        ])]);

        $room = $result['rooms'][0];

        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(7.0, $room['printed_m2']);
        $this->assertSame(0.0, $room['deviation_m2']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_RELIABLE, $room['status']);
        $this->assertSame('Groen – betrouwbaar', $room['status_label']);
        $this->assertSame('0,00 m² / 0,00%', $room['deviation_label']);
    }

    public function test_uses_contour_and_scale_when_only_one_room_side_is_labelled(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage([
            ['text' => '7 m2', 'x' => 275.0, 'y' => 570.0],
        ], vertical: false)]);

        $room = $result['rooms'][0];

        $this->assertSame('contour + schaal', $room['method']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(7.0, $room['printed_m2']);
        $this->assertSame(0.0, $room['deviation_m2']);
        $this->assertSame('contour + schaal uit lokale maatlijn 3500', $room['trace']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_REVIEW, $room['status']);
        $this->assertSame(3500, $room['horizontal_mm']);
        $this->assertNull($room['vertical_mm']);
    }

    public function test_assigns_dimensions_to_the_room_whose_walls_they_bound(): void
    {
        $result = $this->analyzer()->analyzePages([[
            'page' => 1,
            'width' => 900.0,
            'height' => 400.0,
            'texts' => [
                ['text' => 'OPSLAG', 'x' => 80.0, 'y' => 120.0, 'page' => 1],
                ['text' => 'A-00-03', 'x' => 80.0, 'y' => 100.0, 'page' => 1],
                ['text' => '3500', 'x' => 180.0, 'y' => 8.0, 'page' => 1],
                ['text' => '2000', 'x' => 0.0, 'y' => 110.0, 'page' => 1],
                ['text' => 'KANTOOR', 'x' => 620.0, 'y' => 160.0, 'page' => 1],
                ['text' => 'A-00-04', 'x' => 620.0, 'y' => 140.0, 'page' => 1],
                ['text' => '4000', 'x' => 620.0, 'y' => 5.0, 'page' => 1],
                ['text' => '2500', 'x' => 408.0, 'y' => 140.0, 'page' => 1],
            ],
            'fills' => [
                ['x' => 10.0, 'y' => 20.0, 'width' => 350.0, 'height' => 200.0, 'area' => 70000.0, 'page' => 1],
                ['x' => 420.0, 'y' => 20.0, 'width' => 400.0, 'height' => 250.0, 'area' => 100000.0, 'page' => 1],
            ],
            'walls' => [
                ['x1' => 10.0, 'y1' => 20.0, 'x2' => 360.0, 'y2' => 20.0, 'axis' => 'h'],
                ['x1' => 360.0, 'y1' => 20.0, 'x2' => 360.0, 'y2' => 220.0, 'axis' => 'v'],
                ['x1' => 10.0, 'y1' => 220.0, 'x2' => 360.0, 'y2' => 220.0, 'axis' => 'h'],
                ['x1' => 10.0, 'y1' => 20.0, 'x2' => 10.0, 'y2' => 220.0, 'axis' => 'v'],
                ['x1' => 420.0, 'y1' => 20.0, 'x2' => 820.0, 'y2' => 20.0, 'axis' => 'h'],
                ['x1' => 820.0, 'y1' => 20.0, 'x2' => 820.0, 'y2' => 270.0, 'axis' => 'v'],
                ['x1' => 420.0, 'y1' => 270.0, 'x2' => 820.0, 'y2' => 270.0, 'axis' => 'h'],
                ['x1' => 420.0, 'y1' => 20.0, 'x2' => 420.0, 'y2' => 270.0, 'axis' => 'v'],
            ],
        ]]);

        $byNumber = collect($result['rooms'])->keyBy('room_number');

        $this->assertSame(7.0, $byNumber['A-00-03']['calculated_m2']);
        $this->assertSame(10.0, $byNumber['A-00-04']['calculated_m2']);
        $this->assertSame('4000 × 2500 → 10,00 m²', $byNumber['A-00-04']['trace']);
        $this->assertSame(3500, $byNumber['A-00-03']['horizontal_mm']);
        $this->assertSame(4000, $byNumber['A-00-04']['horizontal_mm']);
    }

    public function test_reads_a_dimensioned_drawing_without_using_the_printed_area_or_overall_length(): void
    {
        $result = $this->analyzer()->analyzeFile(DimensionedRoomPdf::path(), 'opslag.pdf');

        $this->assertSame('opslag.pdf', $result['filename']);
        $this->assertSame(AreaWithoutM2Analyzer::SOURCE_TEXT, $result['source_type']);
        $this->assertNotEmpty($result['rooms']);
        $room = collect($result['rooms'])->firstWhere('room_number', 'A-00-03');
        $this->assertNotNull($room);
        $this->assertSame('OPSLAG', $room['room_name']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(99.0, $room['printed_m2']);
        $this->assertNotSame(99.0, $room['calculated_m2']);
        $this->assertSame('lengte × breedte', $room['method']);
        $this->assertSame(3500, $room['horizontal_mm']);
        $this->assertSame(2000, $room['vertical_mm']);
        $this->assertContains(6975, array_column($room['rejected'], 'mm'));
    }

    public function test_uses_raster_ocr_pages_when_the_pdf_has_no_font_and_skips_vector_extract(): void
    {
        $geometry = Mockery::mock(PdfPageGeometry::class);
        $geometry->shouldReceive('extract')->never();
        $raster = Mockery::mock(RasterPageReader::class);
        $raster->shouldReceive('read')->once()->andReturn([
            'pages' => [$this->opslagPage()],
            'engine' => 'tesseract',
            'error' => null,
            'timings' => ['render' => 0.4, 'ocr' => 1.2, 'walls' => 0.3],
            'ocr_mean_confidence' => 80.0,
            'ocr_word_count' => 5,
            'preview_path' => null,
        ]);

        $result = (new AreaWithoutM2Analyzer($geometry, $raster))->analyzeFile('proef.pdf', 'proef.pdf');

        $this->assertSame(AreaWithoutM2Analyzer::SOURCE_IMAGE, $result['source_type']);
        $this->assertSame('Brontype: afbeelding/scanned PDF', $result['source_label']);
        $this->assertSame('tesseract', $result['ocr_engine']);
        $this->assertSame(7.0, $result['rooms'][0]['calculated_m2']);
        $this->assertSame('lengte × breedte', $result['rooms'][0]['method']);
        $this->assertSame(1.2, $result['timings']['ocr']);
        $this->assertSame(0.4, $result['timings']['render']);
        $this->assertContains('OPSLAG', $result['recognized_room_names']);
    }

    public function test_recognizes_named_rooms_without_a_room_number(): void
    {
        $result = $this->analyzer()->analyzePages([$this->page([
            ['text' => 'WOONKAMER', 'x' => 40.0, 'y' => 180.0],
            ['text' => 'KEUKEN', 'x' => 40.0, 'y' => 140.0],
            ['text' => 'HAL', 'x' => 40.0, 'y' => 100.0],
            ['text' => 'SLAAPKAMER 1', 'x' => 220.0, 'y' => 180.0],
            ['text' => '10600', 'x' => 20.0, 'y' => 20.0],
            ['text' => '3600', 'x' => 40.0, 'y' => 60.0],
            ['text' => '2200', 'x' => 10.0, 'y' => 80.0],
        ], width: 400.0, height: 220.0)]);

        $names = collect($result['rooms'])->pluck('room_name')->all();

        $this->assertContains('WOONKAMER', $names);
        $this->assertContains('KEUKEN', $names);
        $this->assertContains('HAL', $names);
        $this->assertContains('SLAAPKAMER 1', $names);
        $this->assertSame([2200, 3600, 10600], $result['recognized_dimension_values']);
        $this->assertSame([], $result['calculated_room_labels']);
        foreach ($result['rooms'] as $room) {
            $this->assertSame('', $room['room_number']);
            $this->assertNull($room['calculated_m2']);
            $this->assertSame(AreaWithoutM2Analyzer::STATUS_UNAVAILABLE, $room['status']);
        }
        $this->assertContains(10600, array_column($result['rooms'][0]['rejected'], 'mm'));
    }

    public function test_reconstructs_a_named_room_across_a_door_opening(): void
    {
        $result = $this->analyzer()->analyzePages([[
            'page' => 1,
            'width' => 900.0,
            'height' => 900.0,
            'texts' => [
                ['text' => 'SLAAPKAMER 1', 'x' => 215.0, 'y' => 590.0, 'page' => 1],
                ['text' => '3500', 'x' => 275.0, 'y' => 488.0, 'page' => 1],
                ['text' => '2000', 'x' => 88.0, 'y' => 590.0, 'page' => 1],
                ['text' => '10600', 'x' => 275.0, 'y' => 455.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 100.0, 'y1' => 500.0, 'x2' => 180.0, 'y2' => 500.0, 'axis' => 'h'],
                ['x1' => 250.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 500.0, 'axis' => 'h'],
                ['x1' => 100.0, 'y1' => 700.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'h'],
                ['x1' => 100.0, 'y1' => 500.0, 'x2' => 100.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 450.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 50.0, 'y1' => 430.0, 'x2' => 850.0, 'y2' => 430.0, 'axis' => 'h'],
            ],
        ]]);

        $room = $result['rooms'][0];

        $this->assertSame('SLAAPKAMER 1', $room['room_name']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame('lengte × breedte', $room['method']);
        $this->assertSame(3500, $room['horizontal_mm']);
        $this->assertSame(2000, $room['vertical_mm']);
        $this->assertContains(10600, array_column($room['rejected'], 'mm'));
        $this->assertStringContainsString('deuropening', $room['closed_gaps_label']);
        $this->assertNotNull($room['overlay']);
        $this->assertSame(11.11, $room['overlay']['left']);
        $this->assertSame(22.22, $room['overlay']['top']);
    }

    public function test_calculates_two_bedrooms_from_dimension_chains_without_using_overall_lengths(): void
    {
        $result = $this->analyzer()->analyzePages([$this->twoBedroomPage()]);

        $byName = collect($result['rooms'])->keyBy('room_name');

        $this->assertSame(10.5, $byName['SLAAPKAMER 1']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 1']['horizontal_mm']);
        $this->assertSame(3500, $byName['SLAAPKAMER 1']['vertical_mm']);
        $this->assertSame('maatketting', $byName['SLAAPKAMER 1']['method']);
        $this->assertSame('3000 × 3500 → 10,50 m²', $byName['SLAAPKAMER 1']['trace']);
        $this->assertSame(9.9, $byName['SLAAPKAMER 2']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 2']['horizontal_mm']);
        $this->assertSame(3300, $byName['SLAAPKAMER 2']['vertical_mm']);
        $this->assertSame('maatketting', $byName['SLAAPKAMER 2']['method']);
        $this->assertSame('3000 × 3300 → 9,90 m²', $byName['SLAAPKAMER 2']['trace']);
        $this->assertNull($byName['WOONKAMER']['calculated_m2']);
        $this->assertNull($byName['HAL']['calculated_m2']);
        $this->assertContains(10600, array_column($byName['SLAAPKAMER 1']['rejected'], 'mm'));
        $this->assertContains(8000, array_column($byName['SLAAPKAMER 1']['rejected'], 'mm'));
        $this->assertSame(50, $byName['SLAAPKAMER 1']['drawing_scale']);
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['horizontal_segment']);
        $this->assertSame(320.0, $byName['SLAAPKAMER 1']['ocr_x']);
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['overlay']['horizontal'] ?? null);
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['overlay']['vertical'] ?? null);
    }

    public function test_calculates_stacked_bedrooms_when_wall_ink_is_interrupted(): void
    {
        $result = $this->analyzer()->analyzePages([$this->fragmentedStackedBedroomsPage()]);

        $byName = collect($result['rooms'])->keyBy('room_name');

        $this->assertSame(10.5, $byName['SLAAPKAMER 1']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 1']['horizontal_mm']);
        $this->assertSame(3500, $byName['SLAAPKAMER 1']['vertical_mm']);
        $this->assertSame('3000 × 3500 → 10,50 m²', $byName['SLAAPKAMER 1']['trace']);
        $this->assertSame(9.9, $byName['SLAAPKAMER 2']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 2']['horizontal_mm']);
        $this->assertSame(3300, $byName['SLAAPKAMER 2']['vertical_mm']);
        $this->assertSame('3000 × 3300 → 9,90 m²', $byName['SLAAPKAMER 2']['trace']);
        $this->assertContains(10600, array_column($byName['SLAAPKAMER 1']['rejected'], 'mm'));
        $this->assertContains(8000, array_column($byName['SLAAPKAMER 1']['rejected'], 'mm'));
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['wall_debug']['vertical']);
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['overlay']['candidates']);
    }

    public function test_does_not_duplicate_a_named_room_that_already_has_a_number(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage()]);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame('OPSLAG', $result['rooms'][0]['room_name']);
        $this->assertSame('A-00-03', $result['rooms'][0]['room_number']);
    }

    /**
     * @param  list<array{text: string, x: float, y: float}>  $extraTexts
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $extraWalls
     * @return array<string, mixed>
     */
    private function opslagPage(array $extraTexts = [], array $extraWalls = [], bool $dimensions = true, bool $vertical = true): array
    {
        $texts = [
            ['text' => 'OPSLAG', 'x' => 275.0, 'y' => 610.0],
            ['text' => 'A-00-03', 'x' => 275.0, 'y' => 590.0],
        ];
        if ($dimensions) {
            $texts[] = ['text' => '3500', 'x' => 250.0, 'y' => 485.0];
            if ($vertical) {
                $texts[] = ['text' => '2000', 'x' => 70.0, 'y' => 590.0];
            }
        }

        return [
            'page' => 1,
            'width' => 595.0,
            'height' => 842.0,
            'texts' => array_map(
                fn (array $text): array => $text + ['page' => 1],
                array_merge($texts, $extraTexts),
            ),
            'fills' => [[
                'x' => 100.0,
                'y' => 500.0,
                'width' => 350.0,
                'height' => 200.0,
                'area' => 70000.0,
                'page' => 1,
            ]],
            'walls' => array_merge([
                ['x1' => 100.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 500.0, 'axis' => 'h'],
                ['x1' => 450.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 100.0, 'y1' => 700.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'h'],
                ['x1' => 100.0, 'y1' => 500.0, 'x2' => 100.0, 'y2' => 700.0, 'axis' => 'v'],
            ], $extraWalls),
        ];
    }

    /**
     * @param  list<array{text: string, x: float, y: float}>  $texts
     * @return array<string, mixed>
     */
    private function page(array $texts, float $width = 200.0, float $height = 200.0): array
    {
        return [
            'page' => 1,
            'width' => $width,
            'height' => $height,
            'texts' => array_map(
                fn (array $text): array => $text + ['page' => 1],
                $texts,
            ),
            'fills' => [],
            'walls' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function twoBedroomPage(): array
    {
        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'texts' => [
                ['text' => 'SLAAPKAMER 1', 'x' => 320.0, 'y' => 440.0, 'page' => 1],
                ['text' => 'SLAAPKAMER 2', 'x' => 560.0, 'y' => 430.0, 'page' => 1],
                ['text' => 'WOONKAMER', 'x' => 400.0, 'y' => 120.0, 'page' => 1],
                ['text' => 'HAL', 'x' => 80.0, 'y' => 120.0, 'page' => 1],
                ['text' => 'Schaal: 1:50', 'x' => 40.0, 'y' => 40.0, 'page' => 1],
                ['text' => '3000', 'x' => 320.0, 'y' => 660.0, 'page' => 1],
                ['text' => '3000', 'x' => 560.0, 'y' => 660.0, 'page' => 1],
                ['text' => '3500', 'x' => 120.0, 'y' => 440.0, 'page' => 1],
                ['text' => '3300', 'x' => 760.0, 'y' => 430.0, 'page' => 1],
                ['text' => '10600', 'x' => 450.0, 'y' => 700.0, 'page' => 1],
                ['text' => '8000', 'x' => 30.0, 'y' => 440.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 200.0, 'y1' => 300.0, 'x2' => 270.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 370.0, 'y1' => 300.0, 'x2' => 440.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 200.0, 'y1' => 580.0, 'x2' => 440.0, 'y2' => 580.0, 'axis' => 'h'],
                ['x1' => 200.0, 'y1' => 300.0, 'x2' => 200.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 440.0, 'y1' => 300.0, 'x2' => 440.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 440.0, 'y1' => 300.0, 'x2' => 680.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 440.0, 'y1' => 564.0, 'x2' => 680.0, 'y2' => 564.0, 'axis' => 'h'],
                ['x1' => 680.0, 'y1' => 300.0, 'x2' => 680.0, 'y2' => 564.0, 'axis' => 'v'],
                ['x1' => 200.0, 'y1' => 660.0, 'x2' => 440.0, 'y2' => 660.0, 'axis' => 'h'],
                ['x1' => 440.0, 'y1' => 660.0, 'x2' => 680.0, 'y2' => 660.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 700.0, 'x2' => 888.0, 'y2' => 700.0, 'axis' => 'h'],
                ['x1' => 120.0, 'y1' => 300.0, 'x2' => 120.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 760.0, 'y1' => 300.0, 'x2' => 760.0, 'y2' => 564.0, 'axis' => 'v'],
                ['x1' => 30.0, 'y1' => 100.0, 'x2' => 30.0, 'y2' => 740.0, 'axis' => 'v'],
            ],
        ];
    }

    /**
     * Stacked bedrooms on the right, with raster-like wall gaps and local chains.
     *
     * @return array<string, mixed>
     */
    private function fragmentedStackedBedroomsPage(): array
    {
        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'texts' => [
                ['text' => 'SLAAPKAMER 1', 'x' => 850.0, 'y' => 605.0, 'page' => 1],
                ['text' => 'SLAAPKAMER 2', 'x' => 850.0, 'y' => 265.0, 'page' => 1],
                ['text' => 'WOONKAMER', 'x' => 400.0, 'y' => 200.0, 'page' => 1],
                ['text' => 'HAL', 'x' => 500.0, 'y' => 500.0, 'page' => 1],
                ['text' => 'Schaal: 1:50', 'x' => 40.0, 'y' => 40.0, 'page' => 1],
                ['text' => '3000', 'x' => 850.0, 'y' => 800.0, 'page' => 1],
                ['text' => '3000', 'x' => 850.0, 'y' => 50.0, 'page' => 1],
                ['text' => '3500', 'x' => 650.0, 'y' => 605.0, 'page' => 1],
                ['text' => '3300', 'x' => 1050.0, 'y' => 265.0, 'page' => 1],
                ['text' => '10600', 'x' => 600.0, 'y' => 860.0, 'page' => 1],
                ['text' => '8000', 'x' => 40.0, 'y' => 430.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 700.0, 'y1' => 500.0, 'x2' => 700.0, 'y2' => 780.0, 'axis' => 'v'],
                ['x1' => 1000.0, 'y1' => 688.0, 'x2' => 1000.0, 'y2' => 780.0, 'axis' => 'v'],
                ['x1' => 1000.0, 'y1' => 100.0, 'x2' => 1000.0, 'y2' => 196.0, 'axis' => 'v'],
                ['x1' => 700.0, 'y1' => 100.0, 'x2' => 820.0, 'y2' => 100.0, 'axis' => 'h'],
                ['x1' => 700.0, 'y1' => 430.0, 'x2' => 820.0, 'y2' => 430.0, 'axis' => 'h'],
                ['x1' => 700.0, 'y1' => 780.0, 'x2' => 820.0, 'y2' => 780.0, 'axis' => 'h'],
                ['x1' => 700.0, 'y1' => 800.0, 'x2' => 1000.0, 'y2' => 800.0, 'axis' => 'h'],
                ['x1' => 700.0, 'y1' => 50.0, 'x2' => 1000.0, 'y2' => 50.0, 'axis' => 'h'],
                ['x1' => 650.0, 'y1' => 430.0, 'x2' => 650.0, 'y2' => 780.0, 'axis' => 'v'],
                ['x1' => 1050.0, 'y1' => 100.0, 'x2' => 1050.0, 'y2' => 430.0, 'axis' => 'v'],
                ['x1' => 40.0, 'y1' => 860.0, 'x2' => 1100.0, 'y2' => 860.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 80.0, 'x2' => 40.0, 'y2' => 820.0, 'axis' => 'v'],
            ],
        ];
    }

    private function analyzer(): AreaWithoutM2Analyzer
    {
        return new AreaWithoutM2Analyzer;
    }
}
