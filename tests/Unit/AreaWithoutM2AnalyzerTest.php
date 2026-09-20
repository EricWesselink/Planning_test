<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\AreaWithoutM2Analyzer;
use App\Services\AreaWithoutM2Trial\RasterPageReader;
use App\Services\AreaWithoutM2Trial\WallAxisAssembler;
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
        $this->assertSame('maatketting', $room['method']);
        $this->assertSame('3500 × 2000 → 7,00 m²', $room['trace']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(99.0, $room['printed_m2']);
        $this->assertSame(-92.0, $room['deviation_m2']);
        $this->assertSame(0.9, $room['confidence']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_REVIEW, $room['status']);
        $this->assertSame('Oranje – controleren', $room['status_label']);
        $this->assertStringContainsString('x=250, y=485', $room['horizontal_position']);
        $this->assertStringContainsString('maatketting', $room['horizontal_walls']);
        $this->assertStringContainsString('x=70, y=590', $room['vertical_position']);
        $this->assertStringContainsString('maatketting', $room['vertical_walls']);
        $this->assertNotEmpty($room['dimension_debug']);
        $this->assertSame('x=100–450', $room['horizontal_endpoints']);
        $this->assertStringContainsString('x=', $room['horizontal_expected']);
        $this->assertContains(6975, array_column($room['rejected'], 'mm'));
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
        $this->assertSame([], $result['recognized_dimension_values']);
        $this->assertContains(6975, array_column($result['page_pipeline']['excluded_numbers'], 'mm'));
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
        $path = DimensionedRoomPdf::path();
        $raster = Mockery::mock(RasterPageReader::class);
        $raster->shouldReceive('readGeometry')->once()->andReturn($this->emptyRaster());
        $raster->shouldReceive('read')->never();

        try {
            $result = (new AreaWithoutM2Analyzer(new PdfPageGeometry, $raster))->analyzeFile($path, 'opslag.pdf');
        } finally {
            @unlink($path);
        }

        $this->assertSame('opslag.pdf', $result['filename']);
        $this->assertSame(AreaWithoutM2Analyzer::SOURCE_TEXT, $result['source_type']);
        $this->assertNotEmpty($result['rooms']);
        $room = collect($result['rooms'])->firstWhere('room_number', 'A-00-03');
        $this->assertNotNull($room);
        $this->assertSame('OPSLAG', $room['room_name']);
        $this->assertSame(7.0, $room['calculated_m2']);
        $this->assertSame(99.0, $room['printed_m2']);
        $this->assertNotSame(99.0, $room['calculated_m2']);
        $this->assertContains($room['method'], ['maatketting', 'lengte × breedte', 'contour + schaal']);
        $this->assertSame(3500, $room['horizontal_mm']);
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
        $raster->shouldReceive('readGeometry')->never();

        $result = (new AreaWithoutM2Analyzer($geometry, $raster))->analyzeFile('proef.pdf', 'proef.pdf');

        $this->assertSame(AreaWithoutM2Analyzer::SOURCE_IMAGE, $result['source_type']);
        $this->assertSame('Brontype: afbeelding/scanned PDF', $result['source_label']);
        $this->assertSame('tesseract', $result['ocr_engine']);
        $this->assertSame(7.0, $result['rooms'][0]['calculated_m2']);
        $this->assertSame('maatketting', $result['rooms'][0]['method']);
        $this->assertSame(1.2, $result['timings']['ocr']);
        $this->assertSame(0.4, $result['timings']['render']);
        $this->assertContains('OPSLAG', $result['recognized_room_names']);
    }

    public function test_text_layer_pdf_still_runs_wall_detection_without_ocr(): void
    {
        $path = DimensionedRoomPdf::path();
        $page = $this->opslagPage();
        $page['walls'] = [];
        $page['raw_walls'] = [];
        $walls = $this->opslagPage()['walls'];
        $geometry = Mockery::mock(PdfPageGeometry::class);
        $geometry->shouldReceive('extract')->once()->andReturn(['pages' => [$page]]);
        $raster = Mockery::mock(RasterPageReader::class);
        $raster->shouldReceive('read')->never();
        $raster->shouldReceive('readGeometry')->once()->andReturn([
            'pages' => [[
                'page' => 1,
                'width' => 595.0,
                'height' => 842.0,
                'texts' => [],
                'fills' => [],
                'walls' => $walls,
                'ticks' => [],
                'raw_walls' => $walls,
                'wall_extract' => [
                    'bands_h' => [],
                    'bands_v' => [],
                    'axes' => $walls,
                    'vertical_candidates' => [],
                    'horizontal_candidates' => [],
                ],
            ]],
            'engine' => null,
            'error' => null,
            'timings' => ['render' => 0.31, 'ocr' => 1.9, 'walls' => 0.44],
            'ocr_mean_confidence' => null,
            'ocr_word_count' => 0,
            'preview_path' => null,
        ]);

        try {
            $result = (new AreaWithoutM2Analyzer($geometry, $raster))->analyzeFile($path, 'print.pdf');
        } finally {
            @unlink($path);
        }

        $this->assertSame(AreaWithoutM2Analyzer::SOURCE_TEXT, $result['source_type']);
        $this->assertSame(0.0, $result['timings']['ocr']);
        $this->assertSame(0.44, $result['timings']['walls']);
        $this->assertSame(0.31, $result['timings']['render']);
        $this->assertGreaterThan(0, $result['page_pipeline']['wall_axes']['vertical']);
        $this->assertGreaterThan(0, $result['page_pipeline']['wall_axes']['horizontal']);
        $this->assertSame('OPSLAG', $result['rooms'][0]['room_name']);
        $this->assertSame('A-00-03', $result['rooms'][0]['room_number']);
        $this->assertSame(7.0, $result['rooms'][0]['calculated_m2']);
    }

    public function test_legend_product_codes_are_excluded_while_labelled_measures_stay_candidates(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage([
            ['text' => '99 m2', 'x' => 275.0, 'y' => 570.0],
            ['text' => 'Legenda', 'x' => 40.0, 'y' => 40.0],
            ['text' => 'v01 = Marmoleum - Forbo 3733', 'x' => 42.0, 'y' => 28.0],
            ['text' => '3732', 'x' => 80.0, 'y' => 16.0],
            ['text' => '3725', 'x' => 80.0, 'y' => 8.0],
            ['text' => '3430', 'x' => 80.0, 'y' => 0.0],
            ['text' => '3712', 'x' => 120.0, 'y' => 16.0],
            ['text' => '2026', 'x' => 40.0, 'y' => 52.0],
            ['text' => '1700', 'x' => 400.0, 'y' => 400.0],
        ], extraWalls: [
            ['x1' => 50.0, 'y1' => 450.0, 'x2' => 545.0, 'y2' => 450.0, 'axis' => 'h'],
            ['x1' => 300.0, 'y1' => 400.0, 'x2' => 500.0, 'y2' => 400.0, 'axis' => 'h'],
        ])]);

        $values = $result['recognized_dimension_values'];
        foreach ([2026, 3430, 3712, 3725, 3732, 3733] as $code) {
            $this->assertNotContains($code, $values);
        }
        $this->assertContains(1700, $values);
        $this->assertContains(3500, $values);
        $this->assertContains(2000, $values);
        $this->assertContains(3733, array_column($result['page_pipeline']['excluded_numbers'], 'mm'));
        $this->assertContains(3732, array_column($result['page_pipeline']['excluded_numbers'], 'mm'));
        $this->assertSame(99.0, $result['rooms'][0]['printed_m2']);
        $this->assertSame('OPSLAG A-00-03', $result['page_pipeline']['printed_m2'][0]['room'] ?? null);
        $this->assertContains(1700, array_column($result['page_pipeline']['dimension_objects'], 'value'));
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
        $this->assertSame([], $result['recognized_dimension_values']);
        $this->assertSame([], $result['calculated_room_labels']);
        foreach ($result['rooms'] as $room) {
            $this->assertSame('', $room['room_number']);
            $this->assertNull($room['calculated_m2']);
            $this->assertSame(AreaWithoutM2Analyzer::STATUS_UNAVAILABLE, $room['status']);
        }
        $this->assertContains(10600, array_column($result['page_pipeline']['excluded_numbers'], 'mm'));
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
        $this->assertSame('maatketting', $room['method']);
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
        $this->assertArrayHasKey('horizontal_chains', $result['page_pipeline']);
        $this->assertArrayHasKey('vertical_chains', $result['page_pipeline']);
        $this->assertNotEmpty($byName['SLAAPKAMER 1']['chain_bind'] ?? []);
    }

    public function test_two_bedrooms_bind_chain_objects_when_the_dimension_line_is_missing(): void
    {
        $page = $this->twoBedroomPage();
        $page['walls'] = array_values(array_filter(
            $page['walls'],
            fn (array $wall): bool => ! (
                ($wall['axis'] ?? '') === 'h'
                && abs((((float) $wall['y1']) + ((float) $wall['y2'])) / 2 - 660.0) < 2.0
            ),
        ));

        $result = $this->analyzer()->analyzePages([$page]);
        $byName = collect($result['rooms'])->keyBy('room_name');
        $values = array_column($result['page_pipeline']['dimension_objects'], 'value');

        $this->assertContains(3000, $values);
        $this->assertSame(10.5, $byName['SLAAPKAMER 1']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 1']['horizontal_mm']);
        $this->assertSame(3500, $byName['SLAAPKAMER 1']['vertical_mm']);
        $this->assertSame('3000 × 3500 → 10,50 m²', $byName['SLAAPKAMER 1']['trace']);
        $this->assertSame(9.9, $byName['SLAAPKAMER 2']['calculated_m2']);
        $this->assertSame(3000, $byName['SLAAPKAMER 2']['horizontal_mm']);
        $this->assertSame(3300, $byName['SLAAPKAMER 2']['vertical_mm']);
        $this->assertSame('3000 × 3300 → 9,90 m²', $byName['SLAAPKAMER 2']['trace']);
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
        $this->assertContains(2400, array_column($byName['SLAAPKAMER 2']['rejected'], 'mm'));
    }

    public function test_does_not_scale_a_room_from_one_unproven_local_measure(): void
    {
        $result = $this->analyzer()->analyzePages([[
            'page' => 1,
            'width' => 900.0,
            'height' => 700.0,
            'texts' => [
                ['text' => 'WOONKAMER', 'x' => 300.0, 'y' => 250.0, 'page' => 1],
                ['text' => '3600', 'x' => 300.0, 'y' => 50.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 100.0, 'y1' => 100.0, 'x2' => 500.0, 'y2' => 100.0, 'axis' => 'h'],
                ['x1' => 100.0, 'y1' => 400.0, 'x2' => 500.0, 'y2' => 400.0, 'axis' => 'h'],
                ['x1' => 100.0, 'y1' => 100.0, 'x2' => 100.0, 'y2' => 400.0, 'axis' => 'v'],
                ['x1' => 500.0, 'y1' => 100.0, 'x2' => 500.0, 'y2' => 400.0, 'axis' => 'v'],
            ],
        ]]);

        $room = $result['rooms'][0];

        $this->assertSame('WOONKAMER', $room['room_name']);
        $this->assertNull($room['calculated_m2']);
        $this->assertNull($room['horizontal_mm']);
        $this->assertSame(AreaWithoutM2Analyzer::STATUS_UNAVAILABLE, $room['status']);
        $this->assertNotContains(3600, $result['recognized_dimension_values']);
        $this->assertContains(3600, array_column($result['page_pipeline']['excluded_numbers'], 'mm'));
    }

    public function test_geometry_overlay_lists_raw_lines_bands_and_classified_axes(): void
    {
        $result = $this->analyzer()->analyzePages([$this->bedroomDiagnosisPage()]);

        $geometry = $result['geometry'];
        $roles = array_column($geometry['axes'], 'role');
        $byName = collect($result['rooms'])->keyBy('room_name');

        $this->assertCount(1, $geometry['raw_v']);
        $this->assertSame('v', $geometry['raw_v'][0]['axis']);
        $this->assertCount(1, $geometry['raw_h']);
        $this->assertSame('h', $geometry['raw_h'][0]['axis']);
        $this->assertCount(1, $geometry['bands']);
        $this->assertContains(WallAxisAssembler::ROLE_INTERNAL, $roles);
        $this->assertContains(WallAxisAssembler::ROLE_OUTER, $roles);
        $this->assertContains(WallAxisAssembler::ROLE_DIMENSION, $roles);
        $this->assertSame('SLAAPKAMER 1', $byName['SLAAPKAMER 1']['overlay']['anchor']['label']);
        $this->assertSame('S1', $byName['SLAAPKAMER 1']['overlay']['chip']['text']);
        $this->assertSame('S2', $byName['SLAAPKAMER 2']['overlay']['chip']['text']);
        $this->assertSame('SLAAPKAMER 2', $byName['SLAAPKAMER 2']['overlay']['anchor']['label']);
    }

    public function test_overlay_chip_sits_in_the_room_centre_with_a_board_material_colour(): void
    {
        $result = $this->analyzer()->analyzePages([$this->opslagPage()]);

        $chip = $result['rooms'][0]['overlay']['chip'];

        $this->assertSame('OPS', $chip['text']);
        $this->assertSame(46.22, $chip['x']);
        $this->assertSame(28.74, $chip['y']);
        $this->assertNotSame($result['rooms'][0]['overlay']['anchor']['y'], $chip['y']);
        $this->assertContains($chip['bg'], [
            '#be0032', '#f38400', '#dcd300', '#008856', '#c026d3', '#1d4ed8',
            '#7c3aed', '#e68fac', '#5eead4', '#8db600', '#604e97', '#2b3d26',
        ]);
        $this->assertContains($chip['fg'], ['#fff', '#1c1917']);
    }

    public function test_reports_right_and_bottom_facades_missing_when_detections_stay_left_and_above_bedroom_ocr(): void
    {
        $result = $this->analyzer()->analyzePages([$this->bedroomDiagnosisPage()]);

        $this->assertSame('rechter buitengevel: niet gevonden', $result['geometry_debug']['right']);
        $this->assertSame(
            'x = 617, y-bereik = 300–700, bron = paar (niet rechts van OCR x=945.3)',
            $result['geometry_debug']['right_detail'],
        );
        $this->assertSame('onderste buitengevel: niet gevonden', $result['geometry_debug']['bottom']);
        $this->assertSame(
            'y = 534, x-bereik = 200–800, bron = lijn (niet onder OCR y=400)',
            $result['geometry_debug']['bottom_detail'],
        );
    }

    public function test_reports_right_and_bottom_facades_found_when_detections_sit_beyond_bedroom_ocr(): void
    {
        $result = $this->analyzer()->analyzePages([$this->bedroomDiagnosisPage(withOuterEnvelope: true)]);

        $this->assertSame('rechter buitengevel: gevonden', $result['geometry_debug']['right']);
        $this->assertSame('x = 1100, y-bereik = 200–800, bron = lijn', $result['geometry_debug']['right_detail']);
        $this->assertSame('onderste buitengevel: gevonden', $result['geometry_debug']['bottom']);
        $this->assertSame('y = 200, x-bereik = 200–1100, bron = band', $result['geometry_debug']['bottom_detail']);
    }

    public function test_geometry_debug_lists_rejected_vertical_runs_in_the_right_search_area(): void
    {
        $page = $this->bedroomDiagnosisPage(withOuterEnvelope: true);
        $page['wall_extract']['vertical_candidates'] = [
            [
                'x' => 987.0, 'y1' => 199.0, 'y2' => 210.0, 'width' => 1.5,
                'decision' => 'rejected', 'reason' => 'te kort',
            ],
            [
                'x' => 1045.0, 'y1' => 185.0, 'y2' => 745.0, 'width' => 1.5,
                'decision' => 'rejected', 'reason' => 'niet voldoende donker',
            ],
            [
                'x' => 1064.0, 'y1' => 185.0, 'y2' => 745.0, 'width' => 4.5,
                'decision' => 'accepted', 'reason' => 'cluster',
            ],
        ];

        $result = $this->analyzer()->analyzePages([$page]);
        $log = $result['geometry_debug']['log'];

        $this->assertSame('rechter buitengevel: gevonden', $result['geometry_debug']['right']);
        $this->assertSame('Rechter zoekgebied x=945.3..1200', $log[0]);
        $this->assertSame('verticale donkere runs: 3', $log[1]);
        $this->assertSame('kandidaat x=987 y=199–210 breedte=1.5 → afgewezen omdat te kort', $log[2]);
        $this->assertSame('kandidaat x=1045 y=185–745 breedte=1.5 → afgewezen omdat niet voldoende donker', $log[3]);
        $this->assertSame('kandidaat x=1064 y=185–745 breedte=4.5 → geaccepteerd (cluster)', $log[4]);
    }

    public function test_geometry_debug_groups_consecutive_short_vertical_ticks(): void
    {
        $page = $this->bedroomDiagnosisPage();
        $page['wall_extract']['vertical_candidates'] = [
            [
                'x' => 988.5, 'y1' => 726.0, 'y2' => 746.0, 'width' => 1.5,
                'decision' => 'rejected', 'reason' => 'te kort',
            ],
            [
                'x' => 990.0, 'y1' => 726.0, 'y2' => 746.0, 'width' => 1.5,
                'decision' => 'rejected', 'reason' => 'te kort',
            ],
            [
                'x' => 991.5, 'y1' => 726.0, 'y2' => 746.0, 'width' => 1.5,
                'decision' => 'rejected', 'reason' => 'te kort',
            ],
            [
                'x' => 1064.0, 'y1' => 185.0, 'y2' => 745.0, 'width' => 4.5,
                'decision' => 'accepted', 'reason' => 'donkere kolom',
            ],
        ];

        $result = $this->analyzer()->analyzePages([$page]);
        $log = $result['geometry_debug']['log'];

        $this->assertSame('verticale donkere runs: 4', $log[1]);
        $this->assertSame('kandidaat x=988.5–991.5 y=726–746 breedte=— → afgewezen omdat te kort (3 kolommen)', $log[2]);
        $this->assertSame('kandidaat x=1064 y=185–745 breedte=4.5 → geaccepteerd (donkere kolom)', $log[3]);
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
                ['x1' => 70.0, 'y1' => 500.0, 'x2' => 70.0, 'y2' => 700.0, 'axis' => 'v'],
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
                ['text' => '3300', 'x' => 1080.0, 'y' => 265.0, 'page' => 1],
                ['text' => '2400', 'x' => 850.0, 'y' => 20.0, 'page' => 1],
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
                ['x1' => 40.0, 'y1' => 860.0, 'x2' => 1100.0, 'y2' => 860.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 80.0, 'x2' => 40.0, 'y2' => 820.0, 'axis' => 'v'],
                ['x1' => 1070.0, 'y1' => 100.0, 'x2' => 1090.0, 'y2' => 100.0, 'axis' => 'h'],
                ['x1' => 1070.0, 'y1' => 430.0, 'x2' => 1090.0, 'y2' => 430.0, 'axis' => 'h'],
                ['x1' => 800.0, 'y1' => 20.0, 'x2' => 920.0, 'y2' => 20.0, 'axis' => 'h'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bedroomDiagnosisPage(bool $withOuterEnvelope = false): array
    {
        $raw = [
            ['x1' => 610.0, 'y1' => 300.0, 'x2' => 610.0, 'y2' => 700.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_LINE],
            ['x1' => 200.0, 'y1' => 534.0, 'x2' => 800.0, 'y2' => 534.0, 'axis' => 'h', 'kind' => WallAxisAssembler::KIND_LINE],
        ];
        $bandsH = [];
        $bandsV = [
            ['x1' => 612.0, 'y1' => 300.0, 'x2' => 621.0, 'y2' => 700.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_BAND],
        ];
        $axes = [
            ['x1' => 617.0, 'y1' => 300.0, 'x2' => 617.0, 'y2' => 700.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_PAIR, 'role' => WallAxisAssembler::ROLE_INTERNAL],
            ['x1' => 200.0, 'y1' => 534.0, 'x2' => 800.0, 'y2' => 534.0, 'axis' => 'h', 'kind' => WallAxisAssembler::KIND_LINE, 'role' => WallAxisAssembler::ROLE_OUTER],
            ['x1' => 40.0, 'y1' => 100.0, 'x2' => 40.0, 'y2' => 800.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_LINE, 'role' => WallAxisAssembler::ROLE_DIMENSION],
        ];
        if ($withOuterEnvelope) {
            $raw[] = ['x1' => 1100.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 800.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_LINE];
            $bandsH[] = ['x1' => 200.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 200.0, 'axis' => 'h', 'kind' => WallAxisAssembler::KIND_BAND];
            $axes[] = ['x1' => 1100.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 800.0, 'axis' => 'v', 'kind' => WallAxisAssembler::KIND_LINE, 'role' => WallAxisAssembler::ROLE_OUTER];
            $axes[] = ['x1' => 200.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 200.0, 'axis' => 'h', 'kind' => WallAxisAssembler::KIND_BAND, 'role' => WallAxisAssembler::ROLE_OUTER];
        }

        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'texts' => [
                ['text' => 'SLAAPKAMER 1', 'x' => 945.3, 'y' => 600.0, 'page' => 1],
                ['text' => 'SLAAPKAMER 2', 'x' => 945.0, 'y' => 400.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => $axes,
            'raw_walls' => $raw,
            'wall_extract' => [
                'bands_h' => $bandsH,
                'bands_v' => $bandsV,
                'axes' => $axes,
            ],
        ];
    }

    private function analyzer(): AreaWithoutM2Analyzer
    {
        return new AreaWithoutM2Analyzer;
    }

    /**
     * @return array{pages: list<array<string, mixed>>, engine: ?string, error: ?string, timings: array{render: float, ocr: float, walls: float}, ocr_mean_confidence: ?float, ocr_word_count: int, preview_path: ?string}
     */
    private function emptyRaster(): array
    {
        return [
            'pages' => [],
            'engine' => null,
            'error' => null,
            'timings' => ['render' => 0.0, 'ocr' => 0.0, 'walls' => 0.0],
            'ocr_mean_confidence' => null,
            'ocr_word_count' => 0,
            'preview_path' => null,
        ];
    }
}
