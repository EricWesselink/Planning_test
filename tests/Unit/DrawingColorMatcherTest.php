<?php

namespace Tests\Unit;

use App\Services\Meetstaat\DrawingColorMatcher;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\PdfPageGeometry;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RgbColor;
use Tests\Support\ColoredFloorPlanPdf;
use Tests\TestCase;

class DrawingColorMatcherTest extends TestCase
{
    public function test_maps_fill_colors_from_the_legend_onto_named_rooms(): void
    {
        $path = ColoredFloorPlanPdf::path();

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf');

        $rows = collect($parsed['areas'])->map(fn (array $area) => [
            'page' => $area['page'] ?? null,
            'room' => $area['room_name'] ?? null,
            'm2' => $area['square_meters'] ?? null,
            'color' => $area['fill_color'] ?? null,
            'material' => $area['legend_material'] ?? ($area['tasks'][0]['work_name'] ?? null),
            'via' => $area['recognized_via'] ?? [],
            'confidence' => $area['confidence'] ?? null,
        ]);

        $this->assertGreaterThanOrEqual(20, $rows->count());
        foreach ($rows as $row) {
            $this->assertSame(1, $row['page']);
            $this->assertNotSame('', (string) $row['room']);
            $this->assertNotNull($row['m2']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', (string) $row['color']);
            $this->assertNotSame('', (string) $row['material']);
            $this->assertContains('kleur', $row['via']);
            $this->assertContains('legenda', $row['via']);
            $this->assertSame('hoog', $row['confidence']);
        }

        $tekenlokaal = $rows->first(fn (array $row) => $row['room'] === 'tekenlokaal');
        $this->assertNotNull($tekenlokaal);
        $this->assertEqualsWithDelta(90.20, (float) $tekenlokaal['m2'], 0.01);
        $this->assertStringContainsString('Dark Sand', (string) $tekenlokaal['material']);

        $kunstpleinen = $rows->where('room', 'kunstplein')->values();
        $this->assertCount(2, $kunstpleinen);
        $this->assertNotSame($kunstpleinen[0]['m2'], $kunstpleinen[1]['m2']);
        $this->assertStringContainsString('pink clay', (string) $kunstpleinen[0]['material']);

        $this->assertCount(2, $rows->where('room', 'muziek'));
        $this->assertNotEmpty($parsed['legend']);
        $this->assertTrue($parsed['sources']['kleur'] ?? false);
        $this->assertNotEmpty($parsed['debug_rooms']);
        foreach ($parsed['areas'] as $area) {
            $this->assertDoesNotMatchRegularExpression('/tarkett|vinyl iQ/i', (string) ($area['room_name'] ?? ''));
            $this->assertContains($area['floor'], ['begane grond', 'verdieping 1', 'verdieping 2', 'verdieping 3', 'kelder', 'installatie ruimten', 'Onbekend']);
        }
    }

    public function test_does_not_treat_dimension_numbers_as_rooms(): void
    {
        $result = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'Tekening : begane grond (2/5)', 'x' => 500, 'y' => 40, 'page' => 1],
                ['text' => '1183', 'x' => 80, 'y' => 400, 'page' => 1],
                ['text' => '384', 'x' => 90, 'y' => 380, 'page' => 1],
                ['text' => 'tekenlokaal', 'x' => 60, 'y' => 420, 'page' => 1],
                ['text' => '90.2 m2', 'x' => 60, 'y' => 440, 'page' => 1],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 400,
                'width' => 80,
                'height' => 60,
                'color' => new RgbColor(140, 128, 107),
                'area' => 4800,
                'page' => 1,
            ]],
        ]]);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame('tekenlokaal', $result['rooms'][0]['room_name']);
        $this->assertSame('begane grond', $result['rooms'][0]['floor']);
        $this->assertNull($result['rooms'][0]['room_number']);
        $this->assertEqualsWithDelta(90.2, (float) $result['rooms'][0]['square_meters'], 0.01);
    }

    public function test_does_not_turn_legend_totals_into_rooms(): void
    {
        $brown = new RgbColor(140, 128, 107);
        $result = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'Tarkett vinyl iQ Natural', 'x' => 40, 'y' => 40, 'page' => 1],
                ['text' => '685.47 m2', 'x' => 220, 'y' => 40, 'page' => 1],
                ['text' => 'tekenlokaal', 'x' => 60, 'y' => 420, 'page' => 1],
                ['text' => '90.2', 'x' => 60, 'y' => 438, 'page' => 1],
                ['text' => 'm²', 'x' => 86, 'y' => 438, 'page' => 1],
            ],
            'fills' => [
                [
                    'x' => 20,
                    'y' => 36,
                    'width' => 10,
                    'height' => 10,
                    'color' => $brown,
                    'area' => 100,
                    'page' => 1,
                ],
                [
                    'x' => 40,
                    'y' => 400,
                    'width' => 90,
                    'height' => 60,
                    'color' => $brown,
                    'area' => 5400,
                    'page' => 1,
                ],
            ],
        ]]);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame('tekenlokaal', $result['rooms'][0]['room_name']);
        $this->assertEqualsWithDelta(90.2, (float) $result['rooms'][0]['square_meters'], 0.01);
        $this->assertNull(collect($result['rooms'])->first(fn (array $room) => abs((float) $room['square_meters'] - 685.47) < 0.01));
    }

    public function test_keeps_a_square_meter_anchor_without_a_fill_as_debug_only(): void
    {
        $result = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'tekenlokaal', 'x' => 60, 'y' => 420, 'page' => 1],
                ['text' => '90.2 m2', 'x' => 60, 'y' => 440, 'page' => 1],
            ],
            'fills' => [],
        ]]);

        $this->assertSame([], $result['rooms']);
        $this->assertCount(1, $result['debug']);
        $this->assertSame('controleren', $result['debug'][0]['confidence']);
        $this->assertEqualsWithDelta(90.2, (float) $result['debug'][0]['square_meters'], 0.01);
    }

    public function test_pairs_names_inside_walled_cells_when_rooms_share_a_fill_color(): void
    {
        $brown = new RgbColor(140, 128, 107);
        $result = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'begane grond', 'x' => 40, 'y' => 800, 'page' => 1],
                ['text' => 'tekenen', 'x' => 70, 'y' => 430, 'page' => 1],
                ['text' => '91.84 m2', 'x' => 70, 'y' => 450, 'page' => 1],
                ['text' => 'magazijn', 'x' => 180, 'y' => 438, 'page' => 1],
                ['text' => 'tekenen', 'x' => 180, 'y' => 426, 'page' => 1],
                ['text' => '38.19 m2', 'x' => 180, 'y' => 450, 'page' => 1],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 400,
                'width' => 220,
                'height' => 80,
                'color' => $brown,
                'area' => 17600,
                'page' => 1,
            ]],
            'walls' => [
                ['x1' => 40, 'y1' => 400, 'x2' => 40, 'y2' => 480, 'axis' => 'v'],
                ['x1' => 150, 'y1' => 400, 'x2' => 150, 'y2' => 480, 'axis' => 'v'],
                ['x1' => 260, 'y1' => 400, 'x2' => 260, 'y2' => 480, 'axis' => 'v'],
                ['x1' => 40, 'y1' => 400, 'x2' => 260, 'y2' => 400, 'axis' => 'h'],
                ['x1' => 40, 'y1' => 480, 'x2' => 260, 'y2' => 480, 'axis' => 'h'],
            ],
        ]]);

        $tekenen = collect($result['rooms'])->first(fn (array $room) => abs((float) $room['square_meters'] - 91.84) < 0.02);
        $magazijn = collect($result['rooms'])->first(fn (array $room) => abs((float) $room['square_meters'] - 38.19) < 0.02);

        $this->assertNotNull($tekenen);
        $this->assertNotNull($magazijn);
        $this->assertSame('tekenen', $tekenen['room_name']);
        $this->assertSame('magazijn tekenen', $magazijn['room_name']);
        $this->assertSame('hoog', $tekenen['confidence']);
        $this->assertSame(1, collect($result['debug'])->first(fn (array $row) => abs((float) $row['square_meters'] - 38.19) < 0.02)['candidate_count']);
    }

    public function test_normalizes_doubled_cad_letters_inside_a_fill(): void
    {
        $result = (new DrawingColorMatcher(new PdfPageGeometry))->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'mmaaggaazziijjnn', 'x' => 60, 'y' => 420, 'page' => 1],
                ['text' => '38.19 m2', 'x' => 60, 'y' => 440, 'page' => 1],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 400,
                'width' => 80,
                'height' => 60,
                'color' => new RgbColor(140, 128, 107),
                'area' => 4800,
                'page' => 1,
            ]],
        ]]);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame('magazijn', $result['rooms'][0]['room_name']);
    }

    public function test_maps_small_floor_fill_fragments_to_legend_without_changing_contours(): void
    {
        $desso = new RgbColor(155, 91, 91);
        $matcher = new DrawingColorMatcher(new PdfPageGeometry);
        $page = [
            'page' => 1,
            'width' => 1200,
            'height' => 800,
            'texts' => [
                ['text' => 'begane grond', 'x' => 1000, 'y' => 760, 'page' => 1],
                ['text' => 'Desso desert AC89', 'x' => 66, 'y' => 101, 'page' => 1],
                ['text' => '375.74 m2', 'x' => 269, 'y' => 101, 'page' => 1],
            ],
            'fills' => [
                [
                    'x' => 40,
                    'y' => 96,
                    'width' => 12,
                    'height' => 12,
                    'color' => $desso,
                    'area' => 144,
                    'page' => 1,
                ],
                [
                    'x' => 650,
                    'y' => 510,
                    'width' => 27,
                    'height' => 17,
                    'color' => $desso,
                    'area' => 459,
                    'page' => 1,
                ],
            ],
        ];
        $legend = $matcher->legendFromPages([$page]);
        $this->assertTrue(collect($legend)->contains(fn (array $row) => str_contains(mb_strtolower((string) $row['material']), 'desso')));

        $method = new \ReflectionMethod(DrawingColorMatcher::class, 'applyFillColors');
        $method->setAccessible(true);
        $areas = $method->invoke($matcher, [[
            'page' => 1,
            'room_name' => 'spreekkamer',
            'square_meters' => 16.33,
            'x' => 660.0,
            'y' => 518.0,
            'meter_x' => 655.0,
            'meter_y' => 518.0,
            'tasks' => [],
            'recognized_via' => ['tekening'],
            'confidence' => 'controleren',
            'needs_review' => true,
        ]], [$page], $legend);

        $this->assertSame('#9b5b5b', $areas[0]['fill_color']);
        $this->assertStringContainsString('Desso', (string) $areas[0]['legend_material']);
        $this->assertSame('hoog', $areas[0]['confidence']);
    }

    public function test_reads_english_oak_pattern_legend_totals_without_solid_swatch(): void
    {
        $matcher = new DrawingColorMatcher(new PdfPageGeometry);
        $legend = $matcher->legendFromPages([[
            'page' => 1,
            'width' => 1200,
            'height' => 800,
            'texts' => [
                ['text' => 'begane grond', 'x' => 1000, 'y' => 760, 'page' => 1],
                ['text' => 'Classics-English', 'x' => 154.9, 'y' => 32.4, 'page' => 1],
                ['text' => '1004.09', 'x' => 265.6, 'y' => 32.4, 'page' => 1],
                ['text' => 'Desso desert AC89', 'x' => 66, 'y' => 101, 'page' => 1],
                ['text' => '375.74 m2', 'x' => 269, 'y' => 101, 'page' => 1],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 96,
                'width' => 12,
                'height' => 12,
                'color' => new RgbColor(155, 91, 91),
                'area' => 144,
                'page' => 1,
            ]],
        ]]);

        $oak = collect($legend)->first(fn (array $row) => str_contains(mb_strtolower((string) $row['material']), 'classics')
            || str_contains(mb_strtolower((string) $row['material']), 'oak'));
        $this->assertNotNull($oak);
        $this->assertNull($oak['color']);
        $this->assertSame('pattern', $oak['fill_type'] ?? null);
        $this->assertSame('P20', $oak['pattern_id'] ?? null);
        $this->assertEqualsWithDelta(1004.09, (float) $oak['declared_total'], 0.01);
    }

    public function test_page_title_recognizes_kelder_and_third_floor(): void
    {
        $matcher = new DrawingColorMatcher(new PdfPageGeometry);
        $kelder = $matcher->roomsFromPages([[
            'page' => 1,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'Tekening : kelder (1/5)', 'x' => 500, 'y' => 40, 'page' => 1],
                ['text' => 'OAT container ruimte', 'x' => 60, 'y' => 420, 'page' => 1],
                ['text' => '28.96 m2', 'x' => 60, 'y' => 440, 'page' => 1],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 400,
                'width' => 80,
                'height' => 60,
                'color' => new RgbColor(140, 128, 107),
                'area' => 4800,
                'page' => 1,
            ]],
        ]]);

        $this->assertSame('kelder', $kelder['rooms'][0]['floor']);

        $third = $matcher->roomsFromPages([[
            'page' => 4,
            'width' => 595,
            'height' => 842,
            'texts' => [
                ['text' => 'Tekening : verdieping 3 (4/5)', 'x' => 500, 'y' => 40, 'page' => 4],
                ['text' => 'douches', 'x' => 60, 'y' => 420, 'page' => 4],
                ['text' => '10.31 m2', 'x' => 60, 'y' => 440, 'page' => 4],
            ],
            'fills' => [[
                'x' => 40,
                'y' => 400,
                'width' => 80,
                'height' => 60,
                'color' => new RgbColor(140, 128, 107),
                'area' => 4800,
                'page' => 4,
            ]],
        ]]);

        $this->assertSame('verdieping 3', $third['rooms'][0]['floor']);
    }
}
