<?php

namespace Tests\Unit;

use App\Services\Meetstaat\RoomIdentityResolver;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

class RoomIdentityResolverTest extends TestCase
{
    public function test_exact_floor_and_number_links_when_unique(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'egels',
                'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 41.4]],
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.35',
                    'room_name' => 'egels',
                    'square_meters' => 52.1,
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertSame(0, $hit['index']);
        $this->assertTrue($hit['evidence']['signals']['exact_number']);
        $this->assertSame('exact_floor_number_name', $hit['evidence']['reason']);
    }

    public function test_ocr_number_links_when_unique_on_floor(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'egels',
                'tasks' => [
                    ['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 41.4],
                    ['work_name' => 'Walton', 'unit' => 'm2', 'quantity' => 10.54],
                ],
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => '00.3.055',
                    'room_name' => 'ege1s',
                    'square_meters' => 52.1,
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertSame('0.35', $resolver->normalizeNumber('00.3.055'));
        $this->assertTrue($hit['evidence']['signals']['exact_number']);
    }

    public function test_missing_number_links_on_unique_name_and_meters(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => '0.11',
                'room_name' => 'verschoonruimte',
                'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 8.25]],
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => null,
                    'room_name' => 'verschoonruimte',
                    'square_meters' => 8.25,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.36',
                    'room_name' => 'verschoonruimte',
                    'square_meters' => 12.08,
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertNull($hit['area']['room_number']);
        $this->assertSame('name_meters', $hit['evidence']['reason']);
        $this->assertTrue($hit['evidence']['signals']['name']);
        $this->assertTrue($hit['evidence']['signals']['meters']);
    }

    public function test_trailing_index_and_unique_meters_link_without_drawing_number(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => 'KDV slaapkamer 6',
                'square_meters' => 4.06,
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => null,
                    'room_name' => 'KDV slaapkamer',
                    'square_meters' => 4.06,
                    'page' => 1,
                    'x' => 0.22,
                    'y' => 0.41,
                    'drawing_room_id' => 'kdv-6',
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => null,
                    'room_name' => 'KDV slaapkamer',
                    'square_meters' => 5.12,
                    'page' => 1,
                    'x' => 0.40,
                    'y' => 0.41,
                    'drawing_room_id' => 'kdv-5',
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertSame('kdv-6', $hit['area']['drawing_room_id']);
        $this->assertSame('name_meters', $hit['evidence']['reason']);
    }

    public function test_unique_name_and_meters_on_floor_links_large_room(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => 'multifunctionele ruimte',
                'square_meters' => 83.13,
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_name' => 'multifunctionele ruimte',
                    'square_meters' => 83.13,
                    'drawing_room_id' => 'mfr',
                ],
                [
                    'floor' => 'verdieping 1',
                    'room_name' => 'multifunctionele ruimte',
                    'square_meters' => 83.13,
                    'drawing_room_id' => 'other-floor',
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertSame('mfr', $hit['area']['drawing_room_id']);
    }

    public function test_doubled_pdf_letters_still_match_name_and_meters(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_name' => 'KDV slaapkamer',
                'square_meters' => 4.06,
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_name' => 'KKDDVV ssllaappkkaammeerr',
                    'square_meters' => 4.06,
                    'drawing_room_id' => 'ocr',
                ],
            ],
        );

        $this->assertNotNull($hit);
        $this->assertSame('ocr', $hit['area']['drawing_room_id']);
    }

    public function test_duplicate_gang_names_stay_unmatched_when_meters_tie(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_name' => 'gang',
                'square_meters' => 12.50,
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_name' => 'gang',
                    'square_meters' => 12.50,
                    'x' => 0.10,
                    'y' => 0.20,
                    'drawing_room_id' => 'a',
                ],
                [
                    'floor' => 'begane grond',
                    'room_name' => 'gang',
                    'square_meters' => 12.50,
                    'x' => 0.70,
                    'y' => 0.20,
                    'drawing_room_id' => 'b',
                ],
            ],
        );

        $this->assertNull($hit);
    }

    public function test_ambiguous_candidates_remain_unmatched(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 50.97]],
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.03',
                    'room_name' => 'groepsruimte',
                    'square_meters' => 51.16,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.04',
                    'room_name' => 'groepsruimte',
                    'square_meters' => 49.01,
                ],
            ],
        );

        $this->assertNull($hit);
    }

    public function test_hard_number_name_meters_conflict_is_rejected(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'begane grond',
                'room_number' => '0.14',
                'room_name' => 'repro concierge',
                'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 18.78]],
            ],
            [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.14',
                    'room_name' => 'kleedruimte',
                    'square_meters' => 33.03,
                ],
            ],
        );

        $this->assertNull($hit);
    }

    public function test_multi_material_room_keeps_tasks_and_uses_drawing_physical_meters(): void
    {
        $meetstaat = [
            'format' => 'test',
            'header' => [],
            'works' => [
                ['name' => 'Real', 'unit' => 'm2', 'declared_total' => 41.40],
                ['name' => 'Walton', 'unit' => 'm2', 'declared_total' => 10.54],
            ],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'egels',
                'tasks' => [
                    ['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 41.40],
                    ['work_name' => 'Walton', 'unit' => 'm2', 'quantity' => 10.54],
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
                'room_number' => '0.35',
                'room_name' => 'egels',
                'square_meters' => 52.10,
                'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 52.10]],
                'source' => 'plattegrond',
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
        $this->assertSame('beide', $room['source']);
        $this->assertEqualsWithDelta(52.10, (float) $room['square_meters'], 0.001);
        $this->assertSame('plattegrond', $room['square_meters_source']);
        $this->assertCount(2, $room['tasks']);
        $this->assertEqualsWithDelta(51.94, collect($room['tasks'])->sum(fn ($t) => (float) $t['quantity']), 0.01);
        $this->assertNotSame('controleren', $room['confidence']);
        $this->assertArrayHasKey('match_evidence', $room);
    }

    public function test_without_drawing_single_task_is_derived_physical_meters(): void
    {
        $physical = (new RoomImportAssembler)->physicalFromMeetstaat([
            'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 50.97]],
        ], false);

        $this->assertEqualsWithDelta(50.97, (float) $physical['square_meters'], 0.001);
        $this->assertSame('afgeleid_meetstaat', $physical['square_meters_source']);
    }

    public function test_with_drawing_available_task_meters_are_not_proven_physical(): void
    {
        $physical = (new RoomImportAssembler)->physicalFromMeetstaat([
            'tasks' => [['work_name' => 'Real', 'unit' => 'm2', 'quantity' => 50.97]],
        ], true);

        $this->assertNull($physical['square_meters']);
        $this->assertEqualsWithDelta(50.97, (float) $physical['derived_square_meters'], 0.001);
        $this->assertNull($physical['square_meters_source']);
    }

    public function test_truncated_drawing_label_matches_unique_host_name(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->matchHost(
            [
                'floor' => 'fase 1 souterrain',
                'room_number' => null,
                'room_name' => 'team-',
                'square_meters' => 24.23,
            ],
            [[
                'floor' => 'fase 1 souterrain',
                'room_number' => '1.91',
                'room_name' => 'teamruimte',
                'tasks' => [['work_name' => 'IVC', 'unit' => 'm2', 'quantity' => 24.23]],
            ]],
        );

        $this->assertNotNull($hit);
        $this->assertSame('1.91', $hit['area']['room_number']);
        $this->assertTrue($hit['evidence']['signals']['name']);
    }

    public function test_fase_floor_does_not_match_plain_storey_without_reassignment(): void
    {
        $resolver = new RoomIdentityResolver;
        $hit = $resolver->match(
            [
                'floor' => 'fase 1 verdieping 1',
                'room_number' => '1.40',
                'room_name' => 'wachten',
                'tasks' => [['work_name' => 'IVC', 'unit' => 'm2', 'quantity' => 31.37]],
            ],
            [[
                'floor' => 'verdieping 1',
                'room_number' => '1.40',
                'room_name' => 'wachten',
                'square_meters' => 31.37,
            ]],
        );

        $this->assertNull($hit);
    }
}
