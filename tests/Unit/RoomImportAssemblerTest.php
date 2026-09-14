<?php

namespace Tests\Unit;

use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

class RoomImportAssemblerTest extends TestCase
{
    public function test_does_not_sum_two_floor_coverings_into_the_physical_room_area(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse($this->mixedRoomText());
        $room = collect($meetstaat['areas'])->first(fn (array $area) => $area['room_number'] === '0.35');
        $this->assertNotNull($room);
        $this->assertCount(2, $room['tasks']);

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null);
        $merged = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.35');

        $this->assertNotNull($merged);
        $this->assertNull($merged['square_meters']);
        $this->assertFalse($merged['needs_review']);
        $this->assertNotSame('controleren', $merged['confidence']);
        $this->assertSame('Meetstaat', $merged['source_label']);
        $quantities = collect($merged['tasks'])->pluck('quantity')->map(fn ($value) => round((float) $value, 2))->all();
        $this->assertContains(51.94, $quantities);
        $this->assertContains(10.54, $quantities);
        $this->assertNotContains(62.48, $quantities);
    }

    public function test_uses_plattegrond_square_meters_when_the_meetstaat_has_two_floor_coverings(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse($this->mixedRoomText());
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'Egels',
                'square_meters' => 55.0,
                'tasks' => [],
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $merged = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.35');

        $this->assertSame(55.0, $merged['square_meters']);
        $this->assertSame('beide', $merged['source']);
        $this->assertCount(1, collect($preview['areas'])->where('room_number', '0.35'));
        $this->assertCount(2, $merged['tasks']);
    }

    public function test_import_report_keeps_physical_and_task_meters_separate(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse($this->mixedRoomText());
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'Egels',
                'square_meters' => 51.94,
                'tasks' => [],
            ]],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $report = $preview['import_report'];
        $room = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.35');

        $this->assertEqualsWithDelta(51.94, (float) $room['square_meters'], 0.01);
        $taskQty = collect($room['tasks'])
            ->filter(fn (array $task) => ($task['unit'] ?? '') === 'm2')
            ->sum(fn (array $task) => (float) $task['quantity']);
        $this->assertEqualsWithDelta(62.48, $taskQty, 0.01);

        $this->assertEqualsWithDelta(51.94, (float) $report['physical_meters'], 0.01);
        $this->assertEqualsWithDelta(62.48, (float) $report['task_meters'], 0.01);
        $this->assertEqualsWithDelta(62.48, (float) $report['meetstaat_task_meters'], 0.01);

        $bg = collect($report['floors'])->first(
            fn (array $row) => str_contains(mb_strtolower((string) $row['floor']), 'begane')
        );
        $this->assertNotNull($bg);
        $this->assertEqualsWithDelta(51.94, (float) $bg['physical_meters'], 0.01);
        $this->assertEqualsWithDelta(62.48, (float) $bg['task_meters'], 0.01);
        $this->assertEqualsWithDelta(62.48, (float) $bg['meetstaat_task_meters'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $bg['task_meters_difference'], 0.01);
    }

    public function test_does_not_double_count_duplicate_pdf_material_lines(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.35',
                'room_name' => 'egels',
                'tasks' => [
                    [
                        'work_name' => 'Marmoleum Real netto 3136 concrete',
                        'unit' => 'm2',
                        'quantity' => 51.94,
                        'perimeter' => 14.07,
                        'seams' => 20.79,
                    ],
                    [
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 51.94,
                        'perimeter' => 14.07,
                        'seams' => 20.79,
                    ],
                    [
                        'work_name' => 'Walton',
                        'unit' => 'm2',
                        'quantity' => 10.54,
                        'perimeter' => 8.0,
                        'seams' => 3.1,
                    ],
                ],
            ]],
            'works' => [
                ['name' => 'Marmoleum Real netto 3136 concrete', 'unit' => 'm2', 'declared_total' => 51.94],
                ['name' => 'Walton', 'unit' => 'm2', 'declared_total' => 10.54],
            ],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null);
        $room = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.35');
        $flooring = collect($room['tasks'])->filter(fn (array $task) => ($task['unit'] ?? '') === 'm2');

        $this->assertCount(2, $flooring);
        $this->assertEqualsWithDelta(62.48, $flooring->sum(fn (array $task) => (float) $task['quantity']), 0.01);
        $this->assertEqualsWithDelta(62.48, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertNull($room['square_meters'], 'Twee verschillende vloerbedekkingen → geen fysieke m² uit meetstaat.');
    }

    public function test_removes_glued_name_ghost_room_as_duplicate(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.08',
                'room_name' => 'berging',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 24.01,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => '0.08berging',
                'square_meters' => 24.01,
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 24.01,
                    'perimeter' => 0,
                    'seams' => 0,
                    'parts' => 1,
                ]],
                'legend_material' => 'Marmoleum Real',
                'source' => 'plattegrond',
                'page' => 1,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $rooms = collect($preview['areas'])->where(fn (array $area) => mb_strtolower((string) ($area['floor'] ?? '')) === 'begane grond');

        $this->assertCount(1, $rooms);
        $this->assertSame('0.08', (string) $rooms->first()['room_number']);
        $this->assertEqualsWithDelta(24.01, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertSame(
            1,
            collect($rooms->first()['tasks'] ?? [])->filter(fn (array $task) => ($task['unit'] ?? '') === 'm2')->count()
        );
    }

    public function test_does_not_merge_conflicting_room_names_on_same_number(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.14',
                'room_name' => 'repro concierge',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 18.78,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.14',
                'room_name' => 'kleedruimte',
                'square_meters' => 33.03,
                'tasks' => [[
                    'work_name' => 'PU gietvloer kleur n.t.b., Coating',
                    'unit' => 'm2',
                    'quantity' => 33.03,
                    'perimeter' => 0,
                    'seams' => 0,
                    'parts' => 1,
                ]],
                'legend_material' => 'PU gietvloer kleur n.t.b., Coating',
                'page' => 4,
                'x' => 100,
                'y' => 200,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $repro = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.14'
            && str_contains(mb_strtolower((string) $area['room_name']), 'repro'));

        $this->assertNotNull($repro);
        $reproTasks = collect($repro['tasks'])->pluck('work_name')->map(fn ($n) => mb_strtolower((string) $n))->all();
        $this->assertFalse(
            collect($reproTasks)->contains(fn (string $name) => str_contains($name, 'pu giet') || str_contains($name, 'coating')),
            'PU van kleedruimte mag niet op repro concierge landen.'
        );
        $this->assertEqualsWithDelta(18.78, collect($repro['tasks'])->sum(fn (array $t) => (float) $t['quantity']), 0.01);
        // Meetstaat is leidend: conflicterend tekeningfragment wordt niet als aparte Controleren-ruimte gehouden.
        $kleed = collect($preview['areas'])->first(fn (array $area) => str_contains(
            mb_strtolower((string) ($area['room_name'] ?? '')),
            'kleed'
        ));
        $this->assertNull($kleed);
        $this->assertGreaterThan(0, $preview['import_report']['safe_fix_stats']['wrong_merge_prevented_meters']);
    }

    public function test_does_not_attach_hal_meters_to_magazijn_on_same_number(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.16',
                'room_name' => 'magazijn/voorraad',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 20.89,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.16',
                    'room_name' => 'hal',
                    'square_meters' => 14.29,
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 14.29,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 1,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => null,
                    'room_name' => 'magazijn/voorraad',
                    'square_meters' => 20.89,
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 20.89,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 1,
                ],
            ],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $magazijn = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.16'
            && str_contains(mb_strtolower((string) $area['room_name']), 'magazijn'));

        $this->assertNotNull($magazijn);
        $this->assertEqualsWithDelta(
            20.89,
            collect($magazijn['tasks'])->sum(fn (array $t) => (float) $t['quantity']),
            0.01,
            'Hal-m² mag niet als extra taak op magazijn landen.'
        );
        $this->assertCount(
            1,
            collect($preview['areas'])->filter(fn (array $area) => str_contains(
                mb_strtolower((string) ($area['room_name'] ?? '')),
                'magazijn'
            )),
            'Ghost magazijn zonder nummer moet als duplicate verdwijnen.'
        );
        $hal = collect($preview['areas'])->first(fn (array $area) => mb_strtolower((string) ($area['room_name'] ?? '')) === 'hal');
        $this->assertNull($hal, 'Conflicterende hal-fragment mag niet als aparte Controleren-ruimte blijven bij sterke meetstaathost.');
    }

    public function test_ocr_damaged_name_links_when_floor_number_and_meters_match(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.21',
                'room_name' => 'speellokaal',
                'tasks' => [[
                    'work_name' => 'Marmoleum Sport 83020 move, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 84.59,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.21',
                'room_name' => 'seloal',
                'square_meters' => 84.59,
                'tasks' => [[
                    'work_name' => 'Marmoleum Sport',
                    'unit' => 'm2',
                    'quantity' => 84.59,
                    'perimeter' => 0,
                    'seams' => 0,
                    'parts' => 1,
                ]],
                'page' => 1,
                'x' => 100,
                'y' => 200,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $this->assertCount(1, $preview['areas']);
        $room = $preview['areas'][0];
        $this->assertSame('0.21', (string) $room['room_number']);
        $this->assertSame('speellokaal', (string) $room['room_name']);
        $this->assertEqualsWithDelta(84.59, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertSame(1, $preview['import_report']['safe_fix_stats']['ocr_meter_matches']);
        $this->assertFalse(
            collect($preview['areas'])->contains(fn (array $a) => str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'seloal'))
        );
    }

    public function test_clears_implausible_room_number_for_floor(): void
    {
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '3.66',
                'room_name' => 'entree werkkast',
                'square_meters' => 58.0,
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 58.0,
                    'perimeter' => 0,
                    'seams' => 0,
                    'parts' => 1,
                ]],
                'page' => 4,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);
        $room = $preview['areas'][0];
        $this->assertNull($room['room_number']);
        $this->assertSame('3.66', $room['implausible_room_number'] ?? null);
        $this->assertSame('midden', $room['confidence']);
        $this->assertSame(1, $preview['import_report']['safe_fix_stats']['implausible_numbers_cleared']);
        $this->assertFalse((bool) ($room['needs_review'] ?? false));
        $this->assertEqualsWithDelta(58.0, (float) $room['square_meters'], 0.01);
    }

    public function test_reassigns_sporthal_rooms_off_plain_begane_grond(): void
    {
        $meetstaat = [
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.14',
                    'room_name' => 'repro concierge',
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 18.78,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                ],
                [
                    'floor' => 'begane grond sporthal',
                    'room_number' => '0.12',
                    'room_name' => 'scheidsrechter',
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 10.32,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                ],
                [
                    'floor' => 'begane grond sporthal',
                    'room_number' => '0.03',
                    'room_name' => 'scheidsrechter',
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 10.43,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                ],
                [
                    'floor' => 'begane grond sporthal',
                    'room_number' => '0.14',
                    'room_name' => 'kleedruimte',
                    'tasks' => [[
                        'work_name' => 'PU gietvloer kleur n.t.b., Coating',
                        'unit' => 'm2',
                        'quantity' => 64.74,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 2,
                    ]],
                ],
                [
                    'floor' => 'begane grond sporthal',
                    'room_number' => '0.01',
                    'room_name' => 'entree',
                    'tasks' => [[
                        'work_name' => 'Coral Welcome, 3202 desperado, Entreemat',
                        'unit' => 'm2',
                        'quantity' => 5.86,
                        'perimeter' => 0,
                        'seams' => 0,
                    ]],
                ],
            ],
            'works' => [],
        ];
        $drawing = [
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.12',
                    'room_name' => 'scheidsrechter',
                    'square_meters' => 10.32,
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 10.32,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 4,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '3.8',
                    'room_name' => 'scheidsrechter',
                    'square_meters' => 10.43,
                    'tasks' => [[
                        'work_name' => 'Marmoleum Real',
                        'unit' => 'm2',
                        'quantity' => 10.43,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 4,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.14',
                    'room_name' => 'kleedruimte',
                    'square_meters' => 33.03,
                    'tasks' => [[
                        'work_name' => 'PU gietvloer kleur n.t.b., Coating',
                        'unit' => 'm2',
                        'quantity' => 33.03,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 4,
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.01',
                    'room_name' => 'entree werkkast',
                    'square_meters' => 5.86,
                    'tasks' => [[
                        'work_name' => 'Coral Welcome',
                        'unit' => 'm2',
                        'quantity' => 5.86,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                    'page' => 4,
                ],
            ],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $stats = $preview['import_report']['safe_fix_stats'];

        $this->assertGreaterThanOrEqual(4, (int) $stats['floor_reassignments']);
        $this->assertStringContainsString(
            'sporthal',
            (string) collect($preview['areas'])->first(
                fn (array $a) => ($a['room_number'] ?? '') === '0.12'
                    && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'scheidsrechter')
            )['floor']
        );

        $entree = collect($preview['areas'])->first(
            fn (array $a) => ($a['room_number'] ?? '') === '0.01'
                && str_contains(mb_strtolower((string) ($a['floor'] ?? '')), 'sporthal')
        );
        $this->assertNotNull($entree);
        $this->assertSame('beide', $entree['source']);
        $this->assertNotEmpty($entree['floor_reassignment_reason'] ?? null);

        $kleed = collect($preview['areas'])->first(
            fn (array $a) => ($a['room_number'] ?? '') === '0.14'
                && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'kleed')
        );
        $this->assertNotNull($kleed);
        $this->assertStringContainsString('sporthal', mb_strtolower((string) $kleed['floor']));
        $this->assertEqualsWithDelta(
            64.74,
            collect($kleed['tasks'])->sum(fn (array $t) => (float) $t['quantity']),
            0.05,
            'Deelvlak 33,03 mag niet bovenop meetstaat 64,74 worden opgeteld.'
        );

        $repro = collect($preview['areas'])->first(
            fn (array $a) => ($a['room_number'] ?? '') === '0.14'
                && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'repro')
        );
        $this->assertNotNull($repro);
        $this->assertSame('begane grond', mb_strtolower((string) $repro['floor']));
    }

    public function test_does_not_match_1_19_to_1_19a(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_number' => '1.19',
                'room_name' => 'groepsruimte',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 40.0,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_number' => '1.19a',
                'room_name' => 'berging',
                'square_meters' => 8.0,
                'tasks' => [],
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);

        $this->assertCount(2, $preview['areas']);
        $plain = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '1.19');
        $suffix = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '1.19a');
        $this->assertNull($plain['square_meters'], 'Zonder tekeningmatch geen bewezen fysieke m² uit meetstaat-taak.');
        $this->assertEqualsWithDelta(40.0, (float) ($plain['derived_square_meters'] ?? 0), 0.01);
        $this->assertSame(8.0, $suffix['square_meters']);
        $this->assertSame('plattegrond', $suffix['source']);
    }

    public function test_combines_material_totals_snijmaten_rooms_and_drawing_meters(): void
    {
        $materials = [
            'works' => [[
                'name' => 'Marmoleum Real',
                'unit' => 'm2',
                'declared_total' => 75.0,
            ]],
            'areas' => [],
        ];
        $snijmaten = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => null,
                'source' => 'snijmaten',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 50.97,
                    'perimeter' => 0,
                    'seams' => 0,
                ]],
            ]],
            'works' => [],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => 50.97,
                'tasks' => [],
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing, $materials, $snijmaten);
        $room = collect($preview['areas'])->first(fn (array $area) => $area['room_number'] === '0.07');
        $work = collect($preview['works'])->first(fn (array $item) => $item['name'] === 'Marmoleum Real');

        $this->assertNotNull($room);
        $this->assertSame('gecombineerd', $room['source']);
        $this->assertEqualsWithDelta(50.97, (float) $room['square_meters'], 0.001);
        $this->assertSame('Marmoleum Real', $room['tasks'][0]['work_name']);
        $this->assertTrue($preview['sources']['materialenstaat']);
        $this->assertTrue($preview['sources']['snijmaten']);
        $this->assertTrue($preview['sources']['plattegrond']);
        $this->assertFalse($preview['sources']['meetstaat']);
        $this->assertEqualsWithDelta(75.0, (float) $work['declared_total'], 0.001);
        $this->assertEqualsWithDelta(50.97, (float) $work['calculated_total'], 0.001);
        $this->assertSame(1, $preview['import_report']['rooms']);
        $this->assertNotEmpty($preview['material_check']);
    }

    public function test_snijmaten_and_material_list_do_not_create_physical_rooms(): void
    {
        $materials = [
            'works' => [[
                'name' => 'Dark Sand',
                'unit' => 'm2',
                'declared_total' => 823.98,
            ]],
            'areas' => [[
                'floor' => 'verdieping 99',
                'room_number' => '8.15',
                'room_name' => 'fantasie',
                'square_meters' => 12.0,
                'source' => 'materialenstaat',
                'tasks' => [],
            ]],
        ];
        $snijmaten = [
            'areas' => [[
                'floor' => 'verdieping 10',
                'room_number' => '-99',
                'room_name' => 'tekenen',
                'square_meters' => null,
                'source' => 'snijmaten',
                'tasks' => [[
                    'work_name' => 'Dark Sand',
                    'unit' => 'm2',
                    'quantity' => 91.84,
                ]],
            ]],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, null, $materials, $snijmaten);

        $this->assertSame([], $preview['areas']);
        $this->assertSame([], $preview['floors']);
        $this->assertSame(0, $preview['import_report']['rooms']);
        $check = collect($preview['material_check'])->first(fn (array $row) => $row['material'] === 'Dark Sand');
        $this->assertNotNull($check);
        $this->assertEqualsWithDelta(823.98, (float) $check['declared_total'], 0.01);
    }

    public function test_snijmaten_enriches_existing_drawing_room_without_adding_floors(): void
    {
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => 'tekenen',
                'square_meters' => 91.84,
                'tasks' => [],
                'source' => 'plattegrond',
                'keep_separate' => true,
                'recognized_via' => ['tekening'],
                'confidence' => 'hoog',
                'page' => 1,
            ]],
            'legend' => [],
            'duplicates_removed' => 0,
        ];
        $snijmaten = [
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => null,
                    'room_name' => 'tekenen',
                    'square_meters' => null,
                    'source' => 'snijmaten',
                    'tasks' => [[
                        'work_name' => 'Dark Sand',
                        'unit' => 'm2',
                        'quantity' => 91.84,
                    ]],
                ],
                [
                    'floor' => 'verdieping 15',
                    'room_number' => null,
                    'room_name' => 'ghost',
                    'square_meters' => null,
                    'source' => 'snijmaten',
                    'tasks' => [[
                        'work_name' => 'Dark Sand',
                        'unit' => 'm2',
                        'quantity' => 10,
                    ]],
                ],
            ],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing, null, $snijmaten);

        $this->assertCount(1, $preview['areas']);
        $this->assertSame(['begane grond'], $preview['floors']);
        $this->assertSame('Dark Sand', $preview['areas'][0]['tasks'][0]['work_name']);
        $this->assertSame('gecombineerd', $preview['areas'][0]['source']);
        $this->assertSame('Snijmaten bevestigd', $preview['areas'][0]['material_source_label']);
        $this->assertContains('snijmaten', $preview['areas'][0]['recognized_via']);
    }

    public function test_snijmaten_confirms_legend_material_without_overwriting_meters(): void
    {
        $drawing = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => 'tekenen',
                'square_meters' => 91.84,
                'tasks' => [[
                    'work_name' => 'Tarkett safe.t Granit Dark Sand',
                    'unit' => 'm2',
                    'quantity' => 91.84,
                    'perimeter' => 0.0,
                    'seams' => 0.0,
                    'parts' => 1,
                ]],
                'source' => 'plattegrond',
                'keep_separate' => true,
                'fill_color' => '#8d8676',
                'legend_material' => 'Tarkett safe.t Granit Dark Sand',
                'recognized_via' => ['tekening', 'kleur', 'legenda'],
                'confidence' => 'controleren',
                'needs_review' => true,
                'page' => 1,
            ]],
            'legend' => [[
                'material' => 'Tarkett safe.t Granit Dark Sand',
                'color' => '#8d8676',
                'declared_total' => 91.84,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
            'duplicates_removed' => 0,
        ];
        $snijmaten = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_name' => 'tekenen',
                'tasks' => [[
                    'work_name' => 'Tarkett safe.t Granit Dark Sand 0508, PVC Banen / Vinyl',
                    'unit' => 'm2',
                    'quantity' => 0,
                ]],
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing, null, $snijmaten);

        $this->assertEqualsWithDelta(91.84, (float) $preview['areas'][0]['square_meters'], 0.01);
        $this->assertSame('Tarkett safe.t Granit Dark Sand', $preview['areas'][0]['legend_material']);
        $this->assertSame('Kleur + Snijmaten bevestigd', $preview['areas'][0]['material_source_label']);
        $this->assertSame('Hoog', $preview['areas'][0]['confidence_label']);
        $this->assertFalse($preview['areas'][0]['needs_review']);
    }

    public function test_review_edits_replace_cached_rooms_before_import(): void
    {
        $preview = (new RoomImportAssembler)->emptyPreview();

        $updated = (new RoomImportAssembler)->applyReview($preview, [[
            'floor' => 'begane grond',
            'room_number' => '0.07',
            'room_name' => 'Groepsruimte',
            'square_meters' => '50,97',
            'source' => 'handmatig',
            'tasks' => [[
                'work_name' => 'Marmoleum Real',
                'quantity' => '50,97',
                'unit' => 'm2',
            ]],
        ]]);

        $this->assertCount(1, $updated['areas']);
        $this->assertSame('0.07', $updated['areas'][0]['room_number']);
        $this->assertEqualsWithDelta(50.97, (float) $updated['areas'][0]['square_meters'], 0.001);
        $this->assertSame('Marmoleum Real', $updated['areas'][0]['tasks'][0]['work_name']);
    }

    public function test_keeps_two_drawing_rooms_with_the_same_name_as_separate_rows(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('kunstplein', 29.20, 40, 220),
                $this->namedDrawingRoom('kunstplein', 51.96, 185, 220),
            ],
            'legend' => [[
                'material' => 'Tarkett vinyl iQ Natural-pink clay',
                'color' => '#ba8cb8',
                'declared_total' => 81.16,
                'page' => 1,
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $rooms = collect($preview['areas'])->where('room_name', 'kunstplein')->values();
        $this->assertCount(2, $rooms);
        $this->assertNotSame($rooms[0]['key'], $rooms[1]['key']);
        $this->assertSame('Hoog', $rooms[0]['confidence_label']);
        $this->assertStringContainsString('kleur', (string) $rooms[0]['recognized_via_label']);
    }

    public function test_marks_legend_total_mismatches_for_review(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('studio', 15.96, 40, 220, 'vloercoating', '#bf4738'),
                $this->namedDrawingRoom('werkruimte', 15.96, 185, 220, 'vloercoating', '#bf4738'),
            ],
            'legend' => [[
                'material' => 'vloercoating',
                'color' => '#bf4738',
                'declared_total' => 200.0,
                'page' => 1,
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $coating = collect($preview['legend'])->first(fn (array $row) => $row['material'] === 'vloercoating');
        $this->assertNotNull($coating);
        $this->assertTrue($coating['needs_review']);
        $this->assertSame('controleren', $coating['status']);
        $this->assertEqualsWithDelta(31.92, (float) $coating['calculated_total'], 0.01);
        $this->assertEqualsWithDelta(-168.08, (float) $coating['difference'], 0.01);
        // Ruimte zelf blijft betrouwbaar gekoppeld; het gat zit in de verdiepingscontrole.
        $this->assertNotSame('Controleren', $preview['areas'][0]['confidence_label']);
        $this->assertSame('controleren', $preview['areas'][0]['legend_total_status']);
        $this->assertTrue($preview['sources']['kleur']);
    }

    public function test_compares_legend_totals_per_floor_and_does_not_add_linear_meters_to_square_meters(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('tekenen', 91.84, 40, 220, 'Dark Sand', '#8c806b'),
                $this->namedDrawingRoom('instructie', 60.08, 40, 220, 'Dark Sand', '#8c806b', 'verdieping 1', 2),
            ],
            'legend' => [
                [
                    'material' => 'Dark Sand',
                    'color' => '#8c806b',
                    'declared_total' => 91.84,
                    'unit' => 'm2',
                    'page' => 1,
                    'floor' => 'begane grond',
                ],
                [
                    'material' => 'Dark Sand',
                    'color' => '#8c806b',
                    'declared_total' => 60.08,
                    'unit' => 'm2',
                    'page' => 2,
                    'floor' => 'verdieping 1',
                ],
                [
                    'material' => 'Plinten wit',
                    'color' => '#ffffff',
                    'declared_total' => 40.0,
                    'unit' => 'm1',
                    'page' => 1,
                    'floor' => 'begane grond',
                ],
            ],
        ];
        $drawing['areas'][0]['tasks'][] = [
            'work_name' => 'Plinten wit',
            'unit' => 'm1',
            'quantity' => 40.0,
            'perimeter' => 40.0,
            'seams' => 0.0,
            'parts' => 1,
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $bg = collect($preview['legend'])->first(fn (array $row) => $row['floor'] === 'begane grond' && $row['unit'] === 'm2');
        $v1 = collect($preview['legend'])->first(fn (array $row) => $row['floor'] === 'verdieping 1' && $row['unit'] === 'm2');
        $plinth = collect($preview['legend'])->first(fn (array $row) => ($row['unit'] ?? '') === 'm1');

        $this->assertSame('ok', $bg['status']);
        $this->assertSame('ok', $v1['status']);
        $this->assertEqualsWithDelta(91.84, (float) $bg['calculated_total'], 0.01);
        $this->assertEqualsWithDelta(60.08, (float) $v1['calculated_total'], 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $plinth['calculated_total'], 0.01);
        $this->assertNotEquals(131.92, (float) $bg['calculated_total']);
        $this->assertSame('Hoog', $preview['areas'][0]['confidence_label']);
    }

    public function test_keeps_hoog_only_when_the_floor_legend_total_matches(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('tekenen', 91.84, 40, 220, 'Dark Sand', '#8c806b'),
            ],
            'legend' => [[
                'material' => 'Dark Sand',
                'color' => '#8c806b',
                'declared_total' => 91.90,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $row = $preview['legend'][0];
        $this->assertSame('ok', $row['status']);
        $this->assertSame('Hoog', $preview['areas'][0]['confidence_label']);
    }

    public function test_marks_a_small_legend_rounding_gap_as_a_warning(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('tekenen', 91.84, 40, 220, 'Dark Sand', '#8c806b'),
            ],
            'legend' => [[
                'material' => 'Dark Sand',
                'color' => '#8c806b',
                'declared_total' => 92.50,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $this->assertSame('waarschuwing', $preview['legend'][0]['status']);
        $this->assertSame('Midden', $preview['areas'][0]['confidence_label']);
        $this->assertFalse($preview['areas'][0]['needs_review']);
    }

    public function test_unknown_legend_total_keeps_room_as_midden_not_controleren(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('tekenen', 91.84, 40, 220, 'Dark Sand', '#8c806b'),
            ],
            'legend' => [[
                'material' => 'Dark Sand',
                'color' => '#8c806b',
                'declared_total' => null,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $this->assertSame('onbekend', $preview['legend'][0]['status']);
        $this->assertNull($preview['legend'][0]['declared_total']);
        $this->assertSame('Midden', $preview['areas'][0]['confidence_label']);
        $this->assertFalse($preview['areas'][0]['needs_review']);
    }

    public function test_legend_gap_suggests_candidate_rooms_without_changing_them(): void
    {
        $drawing = [
            'areas' => [
                $this->namedDrawingRoom('tekenen', 91.84, 40, 220, 'Dark Sand', '#8c806b'),
                $this->namedDrawingRoom('berging', 20.15, 80, 220, 'vloercoating', '#fbfa05'),
            ],
            'legend' => [[
                'material' => 'Dark Sand',
                'color' => '#8c806b',
                'declared_total' => 111.99,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);
        $row = $preview['legend'][0];

        $this->assertSame('controleren', $row['status']);
        $this->assertEqualsWithDelta(-20.15, (float) $row['difference'], 0.01);
        $this->assertNotEmpty($row['hint']['message']);
        $this->assertSame('berging', $row['hint']['candidates'][0]['room_name']);
        $this->assertSame('vloercoating', $preview['areas'][1]['legend_material']);
    }

    public function test_walton_legend_compares_canonical_variants_not_family_totals(): void
    {
        $drawing = [
            'areas' => [
                array_merge($this->namedDrawingRoom('a', 10.0, 10, 10, 'Marmoleum Walton', '#c5c979'), [
                    'tasks' => [[
                        'work_name' => 'Marmoleum Walton, 3355 rosemary green, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 136.72,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                ]),
                array_merge($this->namedDrawingRoom('b', 5.0, 20, 10, 'Marmoleum Walton', '#d93e55'), [
                    'tasks' => [[
                        'work_name' => 'Marmoleum Walton, 3352 berlin red, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 37.09,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                ]),
                array_merge($this->namedDrawingRoom('c', 4.0, 30, 10, 'Marmoleum Walton', '#9c802e'), [
                    'tasks' => [[
                        'work_name' => 'Marmoleum Walton, 3370 terracotta, Linoleum',
                        'unit' => 'm2',
                        'quantity' => 11.44,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ]],
                ]),
            ],
            'legend' => [
                [
                    'material' => 'Marmoleum Walton',
                    'color' => '#c5c979',
                    'declared_total' => 136.72,
                    'unit' => 'm2',
                    'page' => 1,
                    'floor' => 'begane grond',
                ],
                [
                    'material' => 'Marmoleum Walton',
                    'color' => '#d93e55',
                    'declared_total' => 37.09,
                    'unit' => 'm2',
                    'page' => 1,
                    'floor' => 'begane grond',
                ],
                [
                    'material' => 'Marmoleum Walton',
                    'color' => '#9c802e',
                    'declared_total' => 11.44,
                    'unit' => 'm2',
                    'page' => 1,
                    'floor' => 'begane grond',
                ],
            ],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);
        $rows = collect($preview['legend'])->where(fn (array $row) => ($row['material'] ?? '') === 'Marmoleum Walton');

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('ok', $row['status'], (string) ($row['canonical_material'] ?? ''));
            $this->assertStringContainsString('Walton,', (string) ($row['canonical_material'] ?? ''));
            $this->assertEqualsWithDelta(0.0, abs((float) ($row['difference'] ?? 99)), 0.10);
        }
        $this->assertFalse((bool) ($preview['import_report']['legend_hard_gaps'] ?? true));
        $this->assertSame(0, collect($preview['legend'])->where('status', 'controleren')->count());
    }

    public function test_ambiguous_walton_legend_color_is_informative_not_controleren(): void
    {
        $drawing = [
            'areas' => [
                array_merge($this->namedDrawingRoom('mix', 20.0, 10, 10, 'Marmoleum Walton', '#aaaaaa'), [
                    'tasks' => [
                        [
                            'work_name' => 'Marmoleum Walton, 3355 rosemary green, Linoleum',
                            'unit' => 'm2',
                            'quantity' => 10.0,
                            'perimeter' => 0,
                            'seams' => 0,
                            'parts' => 1,
                        ],
                        [
                            'work_name' => 'Marmoleum Walton, 3352 berlin red, Linoleum',
                            'unit' => 'm2',
                            'quantity' => 10.0,
                            'perimeter' => 0,
                            'seams' => 0,
                            'parts' => 1,
                        ],
                    ],
                ]),
            ],
            'legend' => [[
                'material' => 'Marmoleum Walton',
                'color' => '#bbbbbb',
                'declared_total' => 50.0,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble(null, $drawing);
        $row = $preview['legend'][0];

        $this->assertSame('informatief', $row['status']);
        $this->assertSame('Legenda-variant niet eenduidig gekoppeld – informatief', $row['status_label']);
        $this->assertFalse($row['needs_review']);
        $this->assertNull($row['difference']);
        $this->assertFalse((bool) ($preview['import_report']['legend_hard_gaps'] ?? true));
        $this->assertTrue((bool) ($preview['import_report']['legend_informative_gaps'] ?? false));
    }

    public function test_entreemat_tasks_upgrade_to_unique_coral_brush_without_changing_quantities(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'begane grond',
                'room_name' => 'entree',
                'tasks' => [
                    ['work_name' => 'Entreemat', 'unit' => 'm2', 'quantity' => 45.64],
                ],
            ]],
            'works' => [[
                'name' => 'Entreemat',
                'unit' => 'm2',
                'declared_total' => 45.64,
            ]],
        ];
        $materials = [
            'works' => [[
                'name' => '43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen',
                'unit' => 'm2',
                'declared_total' => 45.64,
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);
        $tasks = collect($preview['areas'][0]['tasks']);

        $this->assertTrue($tasks->every(
            fn (array $task) => str_contains((string) $task['work_name'], 'Coral Brush 5721-hurricane grey')
        ));
        $this->assertEqualsWithDelta(45.64, $tasks->sum(fn (array $task) => (float) $task['quantity']), 0.001);
        $coral = collect($preview['import_report']['materials'] ?? $preview['material_check'] ?? [])
            ->first(fn (array $row) => str_contains((string) ($row['material'] ?? $row['canonical_material'] ?? ''), 'Coral Brush'));
        $this->assertNotNull($coral);
        $this->assertNotSame('controleren', $coral['status'] ?? '');
        $this->assertEqualsWithDelta(45.64, (float) ($coral['found_task_meters'] ?? $coral['calculated_total'] ?? 0), 0.01);
    }

    public function test_truncated_english_oak_legend_links_to_canonical_variant(): void
    {
        $canonical = 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT';
        $meetstaat = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_name' => 'aula',
                'tasks' => [[
                    'work_name' => $canonical,
                    'unit' => 'm2',
                    'quantity' => 1085.78,
                ]],
            ]],
            'works' => [[
                'name' => $canonical,
                'unit' => 'm2',
                'declared_total' => 2645.86,
            ]],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_name' => 'aula',
                'square_meters' => 1085.78,
                'tasks' => [],
                'source' => 'plattegrond',
                'fill_color' => '#d8c9a8',
                'confidence' => 'hoog',
                'needs_review' => false,
                'page' => 2,
            ]],
            'legend' => [[
                'material' => 'Tarkett pvc Classics-English Oak grege PVC Tarkett PVC',
                'color' => '#d8c9a8',
                'declared_total' => 1085.78,
                'unit' => 'm2',
                'page' => 2,
                'floor' => 'verdieping 1',
            ]],
            'works' => [],
        ];
        $materials = [
            'works' => [[
                'name' => $canonical,
                'unit' => 'm2',
                'declared_total' => 2645.86,
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing, $materials);
        $row = collect($preview['legend'])->first(
            fn (array $entry) => str_contains(mb_strtolower((string) (($entry['canonical_material'] ?? '').($entry['material'] ?? ''))), 'english oak')
        );

        $this->assertNotNull($row);
        $this->assertNotSame('controleren', $row['status'] ?? '');
        $this->assertStringContainsString('English Oak', (string) ($row['canonical_material'] ?? ''));
        $this->assertEqualsWithDelta(1085.78, (float) ($row['calculated_total'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1085.78, collect($preview['areas'])->sum(
            fn (array $area) => collect($area['tasks'] ?? [])->sum(fn (array $task) => (float) ($task['quantity'] ?? 0))
        ), 0.001);
    }

    public function test_cork_lino_art_stays_apart_and_unknown_floors_recover_from_evidence(): void
    {
        $plain = 'Lino Art Urban R893-0555, flashy street grey, Linoleum';
        $cork = 'Lino Art Urban R893-0555 op kurk, flashy street grey, Linoleum';
        $meetstaat = [
            'areas' => [
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.10',
                    'room_name' => 'hal',
                    'tasks' => [['work_name' => $plain, 'unit' => 'm2', 'quantity' => 317.48]],
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.21',
                    'room_name' => 'atelier',
                    'tasks' => [['work_name' => $cork, 'unit' => 'm2', 'quantity' => 6.19]],
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.22',
                    'room_name' => 'speellokaal',
                    'tasks' => [['work_name' => $cork, 'unit' => 'm2', 'quantity' => 84.22]],
                ],
                [
                    'floor' => 'begane grond',
                    'room_number' => '0.23',
                    'room_name' => 'atelier 2',
                    'tasks' => [['work_name' => $cork, 'unit' => 'm2', 'quantity' => 29.98]],
                ],
                [
                    'floor' => 'Onbekend',
                    'room_number' => null,
                    'room_name' => 'OAT container ruimte',
                    'tasks' => [['work_name' => $plain, 'unit' => 'm2', 'quantity' => 28.96]],
                ],
                [
                    'floor' => 'Onbekend',
                    'room_number' => '03.08',
                    'room_name' => 'douches',
                    'tasks' => [['work_name' => $plain, 'unit' => 'm2', 'quantity' => 10.31]],
                ],
                [
                    'floor' => 'Onbekend',
                    'room_number' => '03.13b',
                    'room_name' => 'verkeersruimte',
                    'tasks' => [['work_name' => $plain, 'unit' => 'm2', 'quantity' => 25.31]],
                ],
            ],
            'works' => [
                ['name' => $plain, 'unit' => 'm2', 'declared_total' => 382.06],
                ['name' => $cork, 'unit' => 'm2', 'declared_total' => 120.39],
            ],
            'uncertain' => [
                ['line' => 'OAT container ruimte 28.96 m²', 'reason' => 'Geen bouwlaag boven deze regel.'],
            ],
        ];
        $drawing = [
            'areas' => [
                [
                    'floor' => 'kelder',
                    'room_number' => null,
                    'room_name' => 'OAT container ruimte',
                    'square_meters' => 28.96,
                    'tasks' => [],
                    'source' => 'plattegrond',
                    'page' => 1,
                    'confidence' => 'hoog',
                    'needs_review' => false,
                ],
                [
                    'floor' => 'verdieping 3',
                    'room_number' => '03.08',
                    'room_name' => 'douches',
                    'square_meters' => 10.31,
                    'tasks' => [],
                    'source' => 'plattegrond',
                    'page' => 4,
                    'confidence' => 'hoog',
                    'needs_review' => false,
                ],
                [
                    'floor' => 'verdieping 3',
                    'room_number' => '03.13b',
                    'room_name' => 'verkeersruimte',
                    'square_meters' => 25.31,
                    'tasks' => [],
                    'source' => 'plattegrond',
                    'page' => 4,
                    'confidence' => 'hoog',
                    'needs_review' => false,
                ],
            ],
            'legend' => [
                [
                    'material' => $plain,
                    'declared_total' => 317.48,
                    'unit' => 'm2',
                    'floor' => 'begane grond',
                    'page' => 2,
                ],
                [
                    'material' => $cork,
                    'declared_total' => 120.39,
                    'unit' => 'm2',
                    'floor' => 'begane grond',
                    'page' => 2,
                ],
            ],
            'works' => [],
        ];
        $materials = [
            'works' => [
                ['name' => $plain, 'unit' => 'm2', 'declared_total' => 382.06],
                ['name' => $cork, 'unit' => 'm2', 'declared_total' => 120.39],
            ],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing, $materials);
        $closure = $preview['import_closure'];

        $workNames = collect($preview['works'])->pluck('name');
        $this->assertTrue($workNames->contains($plain));
        $this->assertTrue($workNames->contains($cork));
        $this->assertEqualsWithDelta(502.45, (float) ($preview['expected_task_totals']['project_total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(502.45, (float) ($preview['import_report']['task_meters'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($preview['import_report']['task_source_meters_lost'] ?? 99), 0.01);

        $oat = collect($preview['areas'])->first(fn (array $area) => ($area['room_name'] ?? '') === 'OAT container ruimte');
        $douches = collect($preview['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.08');
        $verkeer = collect($preview['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.13b');
        $this->assertNotNull($oat);
        $this->assertNotNull($douches);
        $this->assertNotNull($verkeer);
        $this->assertSame('kelder', mb_strtolower((string) $oat['floor']));
        $this->assertSame('verdieping 3', mb_strtolower((string) $douches['floor']));
        $this->assertSame('verdieping 3', mb_strtolower((string) $verkeer['floor']));
        $this->assertSame(0, collect($preview['areas'])->filter(
            fn (array $area) => mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend'
        )->count());

        $legendCork = collect($preview['legend'])->first(
            fn (array $row) => str_contains(mb_strtolower((string) ($row['canonical_material'] ?? $row['material'] ?? '')), 'kurk')
        );
        $legendPlain = collect($preview['legend'])->first(function (array $row) {
            $label = mb_strtolower((string) ($row['canonical_material'] ?? $row['material'] ?? ''));

            return str_contains($label, 'lino art urban') && ! str_contains($label, 'kurk');
        });
        $this->assertNotNull($legendCork);
        $this->assertNotNull($legendPlain);
        $this->assertNotSame('controleren', $legendCork['status'] ?? '');
        $this->assertNotSame('controleren', $legendPlain['status'] ?? '');
        $this->assertEqualsWithDelta(120.39, (float) ($legendCork['calculated_total'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(317.48, (float) ($legendPlain['calculated_total'] ?? 0), 0.02);

        $corkQty = collect($preview['areas'])->sum(function (array $area) {
            return collect($area['tasks'] ?? [])
                ->filter(fn (array $task) => str_contains((string) ($task['work_name'] ?? ''), 'op kurk'))
                ->sum(fn (array $task) => (float) ($task['quantity'] ?? 0));
        });
        $this->assertEqualsWithDelta(120.39, $corkQty, 0.001);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertSame(0, (int) $closure['open_points']);
    }

    public function test_missing_drawing_color_does_not_block_when_meetstaat_task_source_is_unique(): void
    {
        $meetstaat = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_name' => 'omloop rond vides',
                'tasks' => [[
                    'work_name' => 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT',
                    'unit' => 'm2',
                    'quantity' => 332.09,
                ]],
            ]],
            'works' => [[
                'name' => 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT',
                'unit' => 'm2',
                'declared_total' => 332.09,
            ]],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_name' => 'omloop',
                'square_meters' => 15.4,
                'tasks' => [],
                'source' => 'plattegrond',
                'fill_color' => null,
                'confidence' => 'controleren',
                'needs_review' => true,
                'page' => 2,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $drawingLeftover = collect($preview['areas'])->first(
            fn (array $area) => ($area['source'] ?? '') === 'plattegrond'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'omloop')
        );

        $this->assertNotSame('controleren', $drawingLeftover['confidence'] ?? 'controleren');
        $this->assertFalse((bool) ($drawingLeftover['needs_review'] ?? true));
        $this->assertEqualsWithDelta(332.09, collect($preview['areas'])->sum(
            fn (array $area) => collect($area['tasks'] ?? [])->sum(fn (array $task) => (float) ($task['quantity'] ?? 0))
        ), 0.001);
        $this->assertSame(0, collect($preview['areas'])->where('confidence', 'controleren')->count());
    }

    public function test_two_close_meter_matches_keep_missing_color_as_conflict(): void
    {
        $meetstaat = [
            'areas' => [
                [
                    'floor' => 'verdieping 2',
                    'room_name' => 'berging',
                    'tasks' => [[
                        'work_name' => 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT',
                        'unit' => 'm2',
                        'quantity' => 13.20,
                    ]],
                ],
                [
                    'floor' => 'verdieping 2',
                    'room_name' => 'berging',
                    'tasks' => [[
                        'work_name' => 'Desso desert AC89 9523, Tapijttegels',
                        'unit' => 'm2',
                        'quantity' => 13.28,
                    ]],
                ],
            ],
            'works' => [
                ['name' => 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT', 'unit' => 'm2', 'declared_total' => 13.20],
                ['name' => 'Desso desert AC89 9523, Tapijttegels', 'unit' => 'm2', 'declared_total' => 13.28],
            ],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'verdieping 2',
                'room_name' => 'berging',
                'square_meters' => 13.25,
                'tasks' => [],
                'source' => 'plattegrond',
                'fill_color' => null,
                'confidence' => 'controleren',
                'needs_review' => true,
                'page' => 3,
            ]],
            'legend' => [],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $drawingLeftover = collect($preview['areas'])->first(
            fn (array $area) => ($area['source'] ?? '') === 'plattegrond'
        );

        $this->assertNotNull($drawingLeftover);
        $this->assertSame('controleren', $drawingLeftover['confidence'] ?? '');
    }

    public function test_reassigns_fase_drawing_page_using_meetstaat_identity(): void
    {
        $meetstaat = [
            'areas' => [
                $this->taskRoom('fase 1 verdieping 1', '1.40', 'wachten', 'IVC Trasimeno', 31.37),
                $this->taskRoom('fase 1 verdieping 1', '1.56', 'podo therapie 1', 'IVC Chapman Oak', 26.43),
                $this->taskRoom('verdieping 1', '1.62', 'oefenruimte', 'Taraflex', 83.65),
                $this->taskRoom('fase 1 souterrain', '1.91', 'teamruimte', 'IVC Chapman Oak', 24.23),
            ],
            'works' => [
                ['name' => 'IVC Trasimeno', 'unit' => 'm2', 'declared_total' => 31.37],
                ['name' => 'IVC Chapman Oak', 'unit' => 'm2', 'declared_total' => 50.66],
                ['name' => 'Taraflex', 'unit' => 'm2', 'declared_total' => 83.65],
            ],
        ];
        $drawing = [
            'areas' => [
                $this->drawingRoom('verdieping 1', '1.40', 'hal', 66.0, 3),
                $this->drawingRoom('verdieping 1', '1.62', 'oefenruimte', 83.65, 3),
                $this->drawingRoom('verdieping 1', '1.40', 'wachten', 31.37, 4, 'IVC Trasimeno'),
                $this->drawingRoom('verdieping 1', '1.56', 'podo', 26.43, 4, 'IVC Chapman Oak'),
                $this->drawingRoom('kelder', null, 'teamruimte', 24.23, 5),
                $this->drawingRoom('Onbekend', null, 'team-', 24.23, 1),
                $this->drawingRoom('begane grond', '0.15', 'assistentenruimte', 16.75, 2),
                $this->drawingRoom('begane grond', '0.15', 'opslag tandartsen', 17.14, 2),
            ],
            'legend' => [[
                'material' => 'IVC Trasimeno',
                'declared_total' => 31.37,
                'unit' => 'm2',
                'page' => 4,
                'floor' => 'verdieping 1',
                'color' => '#5f72f5',
            ]],
            'works' => [],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $wachten = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '1.40'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'wachten')
        );
        $podo = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '1.56'
        );
        $team = collect($preview['areas'])->first(
            fn (array $area) => str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'teamruimte')
                || ($area['room_number'] ?? '') === '1.91'
        );
        $unknown = collect($preview['areas'])->filter(
            fn (array $area) => mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend'
                || trim((string) ($area['floor'] ?? '')) === ''
        );
        $controleren = collect($preview['areas'])->filter(
            fn (array $area) => ($area['confidence'] ?? '') === 'controleren'
        );

        $this->assertNotNull($wachten);
        $this->assertSame('fase 1 verdieping 1', mb_strtolower((string) $wachten['floor']));
        $this->assertSame('beide', $wachten['source']);
        $this->assertNotNull($podo);
        $this->assertSame('fase 1 verdieping 1', mb_strtolower((string) $podo['floor']));
        $this->assertNotNull($team);
        $this->assertSame('fase 1 souterrain', mb_strtolower((string) $team['floor']));
        $this->assertTrue($unknown->isEmpty(), 'Onbekende bouwlagen: '.$unknown->pluck('room_name')->implode(', '));
        $this->assertTrue($controleren->isEmpty(), 'Open Controleren: '.$controleren->map(
            fn (array $area) => ($area['room_number'] ?? '').' '.($area['room_name'] ?? '')
        )->implode(', '));
        $this->assertSame('READY_AUTOMATIC', $preview['import_closure']['decision']);
        $this->assertEqualsWithDelta(165.68, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($preview['import_report']['task_source_meters_lost'] ?? 99), 0.005);
    }

    public function test_drawing_plinth_color_does_not_create_floor_square_meters(): void
    {
        $meetstaat = [
            'areas' => [
                $this->taskRoom('verdieping 1', '1.40', 'wachten', 'IVC Trasimeno', 31.37),
            ],
            'works' => [
                ['name' => 'IVC Trasimeno', 'unit' => 'm2', 'declared_total' => 31.37],
                ['name' => 'Plinten wit', 'unit' => 'm1', 'declared_total' => 40.0],
            ],
        ];
        $drawing = [
            'areas' => [[
                'floor' => 'verdieping 1',
                'room_number' => '1.40',
                'room_name' => 'wachten',
                'square_meters' => 31.37,
                'legend_material' => 'Plint, wit, Plinten',
                'tasks' => [[
                    'work_name' => 'Plint, wit, Plinten',
                    'unit' => 'm2',
                    'quantity' => 31.37,
                ]],
                'page' => 4,
                'source' => 'plattegrond',
            ]],
            'legend' => [[
                'material' => 'Plint, wit, Plinten',
                'declared_total' => null,
                'unit' => 'm2',
                'page' => 4,
                'floor' => 'verdieping 1',
            ]],
            'works' => [[
                'name' => 'Plint, wit, Plinten',
                'unit' => 'm2',
                'declared_total' => 0,
                'calculated_total' => 31.37,
            ]],
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $plintM2 = collect($preview['works'])->first(
            fn (array $work) => str_contains(mb_strtolower((string) ($work['name'] ?? '')), 'plint')
                && in_array((string) ($work['unit'] ?? ''), ['m2', 'm²'], true)
        );
        $room = collect($preview['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '1.40');
        $plintTasks = collect($room['tasks'] ?? [])->filter(
            fn (array $task) => str_contains(mb_strtolower((string) ($task['work_name'] ?? '')), 'plint')
                && in_array((string) ($task['unit'] ?? ''), ['m2', 'm²'], true)
        );

        $this->assertNull($plintM2);
        $this->assertTrue($plintTasks->isEmpty());
        $this->assertEqualsWithDelta(31.37, (float) $preview['import_report']['task_meters'], 0.01);
        $this->assertNotSame('controleren', $room['confidence'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRoom(string $floor, string $number, string $name, string $material, float $qty): array
    {
        return [
            'floor' => $floor,
            'room_number' => $number,
            'room_name' => $name,
            'tasks' => [[
                'work_name' => $material,
                'unit' => 'm2',
                'quantity' => $qty,
                'perimeter' => 0,
                'seams' => 0,
                'parts' => 1,
            ]],
            'source' => 'meetstaat',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function drawingRoom(
        string $floor,
        ?string $number,
        string $name,
        float $meters,
        int $page,
        ?string $material = null,
    ): array {
        $tasks = [];
        if ($material !== null) {
            $tasks[] = [
                'work_name' => $material,
                'unit' => 'm2',
                'quantity' => $meters,
                'perimeter' => 0,
                'seams' => 0,
                'parts' => 1,
            ];
        }

        return [
            'floor' => $floor,
            'room_number' => $number,
            'room_name' => $name,
            'square_meters' => $meters,
            'tasks' => $tasks,
            'legend_material' => $material,
            'page' => $page,
            'source' => 'plattegrond',
            'confidence' => 'hoog',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function namedDrawingRoom(
        string $name,
        float $meters,
        float $x,
        float $y,
        string $material = 'Tarkett vinyl iQ Natural-pink clay',
        string $color = '#ba8cb8',
        string $floor = 'begane grond',
        int $page = 1,
    ): array {
        return [
            'key' => $floor.'|'.$name.'|'.$page.'|'.round($x).'|'.round($y),
            'floor' => $floor,
            'room_number' => null,
            'room_name' => $name,
            'square_meters' => $meters,
            'tasks' => [[
                'work_name' => $material,
                'unit' => 'm2',
                'quantity' => $meters,
                'perimeter' => 0.0,
                'seams' => 0.0,
                'parts' => 1,
            ]],
            'source' => 'plattegrond',
            'needs_review' => false,
            'fill_color' => $color,
            'legend_material' => $material,
            'recognized_via' => ['tekening', 'kleur', 'legenda'],
            'confidence' => 'hoog',
            'page' => $page,
            'keep_separate' => true,
        ];
    }

    private function mixedRoomText(): string
    {
        return <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P260141 Laakse Tuinen Amersfoort
Werknr        : 260200090
Datum         : 02/09/2026

Marmoleum Real, 3120 rosato, Linoleum
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek - Deur Naden
0.35 egels 51.94 m² 14.07 m 20.79 m
Totaal 51.94 m² 14.07 m 20.79 m
Netto : 51.94 m²

Marmoleum Walton, 3355 rosemary green, Linoleum
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek - Deur Naden
0.35 egels 10.54 m² 8.00 m 3.10 m
Totaal 10.54 m² 8.00 m 3.10 m
Netto : 10.54 m²
TXT;
    }

    public function test_materialenstaat_header_is_primary_over_meetstaat(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse(<<<'TXT'
Meetstaat
Opdrachtgever : Andere klant
Referentie    : ANDERS Oud project
Werknr        : 999999999
Datum         : 01/01/2020
Bouwlaag: begane grond
Marmoleum Real, 3120 rosato, Linoleum
0.07 groepsruimte 50,97 m²
Totaal 50,97 m²
Netto : 50,97 m²
TXT);
        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknummer    : 250200015
Datum         : 07/09/2026
Marmoleum Real, 3120 rosato, Linoleum
Netto : 50,97 m²
TXT);

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);

        $this->assertSame('Nicon vloeren', $preview['header']['customer_name']);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $preview['header']['reference']);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $preview['header']['project_name']);
        $this->assertSame('250200015', $preview['header']['project_number']);
        $this->assertIsString($preview['header']['project_number']);
        $this->assertSame('2026-09-07', $preview['header']['date']);
        $this->assertSame('materialenstaat', $preview['header']['source']);
        $this->assertSame('Materialenstaat', $preview['header']['source_label']);
        $this->assertNotEmpty($preview['project_header_mismatches']);
        $this->assertTrue(collect($preview['project_header_mismatches'])->contains(
            fn (array $row) => $row['field'] === 'project_number'
                && $row['primary'] === '250200015'
                && $row['other'] === '999999999'
                && $row['message'] === 'Mogelijk bestand van ander project'
        ));
        $this->assertFalse($preview['import_closure']['ready']);
    }

    public function test_matching_materialenstaat_and_meetstaat_headers_do_not_block(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknr        : 250200015
Datum         : 07/09/2026
Bouwlaag: begane grond
Marmoleum Real, 3120 rosato, Linoleum
0.07 groepsruimte 50,97 m²
Totaal 50,97 m²
Netto : 50,97 m²
TXT);
        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknummer    : 250200015
Datum         : 07/09/2026
Marmoleum Real, 3120 rosato, Linoleum
Netto : 50,97 m²
TXT);

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);

        $this->assertSame('250200015', $preview['header']['project_number']);
        $this->assertSame([], $preview['project_header_mismatches']);
        $this->assertSame('Materialenstaat', $preview['header']['source_label']);
    }

    public function test_material_list_netto_does_not_replace_meetstaat_declared_total(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : test
Werknr        : 260200099
Datum         : 07/09/2026
Bouwlaag: begane grond
NovaFloor Real 1100 coral, Linoleum
0.01 hal 1596,84 m²
Totaal 1596,84 m²
Netto : 1596,84 m²
TXT);
        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
NovaFloor Real 1100 coral, Linoleum
Netto : 1677,90 m²
TXT);

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);
        $work = collect($preview['works'])->first(
            fn (array $item): bool => str_contains((string) $item['name'], 'NovaFloor Real')
        );

        $this->assertNotNull($work);
        $this->assertEqualsWithDelta(1596.84, (float) $work['declared_total'], 0.01);
        $this->assertNotEquals(1677.90, (float) $work['declared_total']);
    }
}
