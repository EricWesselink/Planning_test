<?php

namespace Tests\Unit;

use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

/**
 * Vaste regel: gedeeld ruimtenummer op tekening-only ruimtes is geen Controleren
 * zolang er geen ≥2 TASK_SOURCE-kandidaten zijn.
 */
class SharedRoomNumberDecisionTest extends TestCase
{
    public function test_drawing_only_shared_numbers_stay_automatic(): void
    {
        $preview = (new RoomImportAssembler)->assemble(
            [
                'areas' => [[
                    'floor' => 'begane grond',
                    'room_number' => '0.01',
                    'room_name' => 'entree',
                    'tasks' => [[
                        'work_name' => 'PVC Test, 1234, PVC',
                        'unit' => 'm2',
                        'quantity' => 12.5,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                ]],
                'works' => [[
                    'name' => 'PVC Test, 1234, PVC',
                    'unit' => 'm2',
                    'declared_total' => 12.5,
                ]],
                'warnings' => [],
                'uncertain' => [],
            ],
            [
                'areas' => [
                    [
                        'floor' => 'begane grond',
                        'room_number' => '0.15',
                        'room_name' => 'assistentenruimte',
                        'square_meters' => 16.75,
                        'tasks' => [],
                        'source' => 'plattegrond',
                        'confidence' => 'hoog',
                    ],
                    [
                        'floor' => 'begane grond',
                        'room_number' => '0.15',
                        'room_name' => 'opslag tandartsen',
                        'square_meters' => 17.14,
                        'tasks' => [],
                        'source' => 'plattegrond',
                        'confidence' => 'hoog',
                    ],
                ],
                'legend' => [],
                'works' => [],
                'warnings' => [],
                'uncertain' => [],
            ],
        );

        $shared = collect($preview['areas'])->filter(
            fn (array $area) => ($area['room_number'] ?? '') === '0.15'
        );
        $this->assertCount(2, $shared);
        foreach ($shared as $area) {
            $this->assertNotSame('controleren', $area['confidence'] ?? '');
            $this->assertFalse((bool) ($area['needs_review'] ?? false));
        }

        $this->assertSame('READY_AUTOMATIC', $preview['import_closure']['decision']);
        $this->assertSame(0, (int) $preview['import_closure']['open_points']);
        $this->assertSame(0, (int) $preview['import_report']['controleren']);
    }

    public function test_plint_legend_never_creates_square_meter_work(): void
    {
        $preview = (new RoomImportAssembler)->assemble(
            [
                'areas' => [[
                    'floor' => 'verdieping 1',
                    'room_number' => '1.40',
                    'room_name' => 'wachten',
                    'tasks' => [
                        [
                            'work_name' => 'IVC Ultimo Trasimeno, 46906, PVC - LVT',
                            'unit' => 'm2',
                            'quantity' => 31.37,
                            'perimeter' => 43.66,
                            'seams' => 0,
                        ],
                        [
                            'work_name' => 'Plinten wit',
                            'unit' => 'm1',
                            'quantity' => 0,
                            'perimeter' => 43.66,
                            'seams' => 0,
                        ],
                    ],
                ]],
                'works' => [
                    ['name' => 'IVC Ultimo Trasimeno, 46906, PVC - LVT', 'unit' => 'm2', 'declared_total' => 31.37],
                    ['name' => 'Plinten wit', 'unit' => 'm1', 'declared_total' => 43.66],
                ],
                'warnings' => [],
                'uncertain' => [],
            ],
            [
                'areas' => [[
                    'floor' => 'verdieping 1',
                    'room_number' => '1.40',
                    'room_name' => 'wachten',
                    'square_meters' => 31.37,
                    'fill_color' => '#d75fd1',
                    'legend_material' => 'Plint, wit, Plinten',
                    'tasks' => [[
                        'work_name' => 'Plint, wit, Plinten',
                        'unit' => 'm2',
                        'quantity' => 31.37,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                    'source' => 'plattegrond',
                    'confidence' => 'hoog',
                ]],
                'legend' => [[
                    'material' => 'Plint, wit, Plinten',
                    'color' => '#d75fd1',
                    'declared_total' => 100.0,
                    'page' => 1,
                    'unit' => 'm1',
                ]],
                'works' => [],
                'warnings' => [],
                'uncertain' => [],
            ],
        );

        foreach ($preview['areas'] as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                $name = mb_strtolower((string) ($task['work_name'] ?? ''));
                if (! str_contains($name, 'plint')) {
                    continue;
                }
                $this->assertSame('m1', (string) ($task['unit'] ?? ''));
            }
        }

        foreach ($preview['works'] as $work) {
            $name = mb_strtolower((string) ($work['name'] ?? ''));
            if (str_contains($name, 'plint')) {
                $this->assertSame('m1', (string) ($work['unit'] ?? ''));
            }
        }

        $this->assertSame('READY_AUTOMATIC', $preview['import_closure']['decision']);
    }
}
