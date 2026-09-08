<?php

namespace Tests\Unit;

use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

class RoomNumberOcrNormalizeTest extends TestCase
{
    public function test_repairs_doubled_pdf_ocr_room_numbers(): void
    {
        $assembler = new RoomImportAssembler;

        $this->assertSame('0.07', $assembler->normalizeNumber('00.0.077'));
        $this->assertSame('1.10', $assembler->normalizeNumber('11.1.100'));
        $this->assertSame('2.05', $assembler->normalizeNumber('22.0.055'));
        $this->assertSame('0.19a', $assembler->normalizeNumber('00.1.199a'));
        $this->assertSame('0.07', $assembler->normalizeNumber('0.07'));
        $this->assertSame('1.27', $assembler->normalizeNumber('1.27'));
    }

    public function test_unique_floor_number_links_despite_name_ocr_glitch(): void
    {
        $meetstaat = [
            'format' => 'test',
            'header' => [],
            'works' => [
                [
                    'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'declared_total' => 41.40,
                ],
                [
                    'name' => 'Marmoleum Walton, 3355 rosemary green, Linoleum',
                    'unit' => 'm2',
                    'declared_total' => 10.54,
                ],
            ],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'egels',
                'tasks' => [
                    [
                        'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 41.40,
                    ],
                    [
                        'work_name' => 'Marmoleum Walton, 3355 rosemary green, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 10.54,
                    ],
                ],
                'source' => 'meetstaat',
            ]],
            'warnings' => [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];

        $drawing = [
            'format' => 'test',
            'header' => [],
            'works' => [],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '00.3.055',
                'room_name' => 'ege1s',
                'square_meters' => 52.10,
                'fill_color' => '#aabbcc',
                'legend_material' => 'Marmoleum Real',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 52.10,
                ]],
                'source' => 'plattegrond',
                'recognized_via' => ['tekening', 'kleur'],
            ]],
            'legend' => [],
            'warnings' => [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);

        $this->assertCount(1, $preview['areas']);
        $room = $preview['areas'][0];
        $this->assertSame('0.35', (string) $room['room_number']);
        $this->assertSame('beide', $room['source']);
        $this->assertNotSame('controleren', $room['confidence']);
        $this->assertFalse((bool) ($room['needs_review'] ?? false));
        $this->assertEqualsWithDelta(52.10, (float) $room['square_meters'], 0.001);
        $this->assertCount(2, collect($room['tasks'])->filter(
            fn (array $task) => in_array((string) ($task['unit'] ?? ''), ['m2', 'm²'], true)
                && (float) ($task['quantity'] ?? 0) > 0
        ));
        $this->assertTrue($preview['import_closure']['ready']);
        $this->assertSame(0, (int) $preview['import_closure']['open_points']);
    }
}
