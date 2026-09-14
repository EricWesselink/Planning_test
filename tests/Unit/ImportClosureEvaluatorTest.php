<?php

namespace Tests\Unit;

use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

class ImportClosureEvaluatorTest extends TestCase
{
    public function test_closed_preview_is_ready_with_zero_difference(): void
    {
        $preview = $this->closedPreview();
        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame(0, $closure['open_points']);
        $this->assertSame(0.0, $closure['totals']['difference']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertSame('Project definitief importeren', $closure['button_label']);
        $this->assertSame(100, $closure['percentages']['rooms']);
        $this->assertSame(100, $closure['percentages']['quantities']);
    }

    public function test_controleren_room_is_a_warning_and_does_not_block_import(): void
    {
        $preview = $this->closedPreview();
        $preview['areas'][0]['confidence'] = 'controleren';
        $preview['areas'][0]['needs_review'] = true;
        $preview['areas'][0]['review_reason_label'] = 'Gemengde vloerbedekking';

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_WITH_WARNINGS', $closure['decision']);
        $this->assertSame(0, $closure['open_points']);
        $this->assertGreaterThan(0, $closure['warning_count']);
        $this->assertSame('Importeren toegestaan', $closure['button_label']);
        $this->assertTrue(collect($closure['issues'])->contains(
            fn (array $issue) => (str_contains((string) $issue['problem'], 'Gemengde vloerbedekking')
                || str_contains((string) $issue['problem'], 'Controleren'))
                && ($issue['severity'] ?? '') === 'warning'
        ));
    }

    public function test_unknown_drawing_floor_allows_import_with_warnings(): void
    {
        $preview = $this->closedPreview();
        $preview['sources']['plattegrond'] = true;
        $preview['areas'][] = [
            'floor' => 'Onbekend',
            'room_number' => '00.99',
            'room_name' => 'tekeningrest',
            'square_meters' => 12.0,
            'tasks' => [],
            'source' => 'plattegrond',
            'source_label' => 'Plattegrond',
            'confidence' => 'hoog',
            'needs_review' => false,
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_WITH_WARNINGS', $closure['decision']);
        $this->assertSame(0, $closure['hard_conflict_count']);
        $this->assertGreaterThan(0, $closure['warning_count']);
        $this->assertSame('Importeren toegestaan', $closure['button_label']);
        $this->assertStringContainsString('tekeningswaarschuwingen', (string) $closure['summary']);
    }

    public function test_legend_mismatch_is_a_warning_when_meetstaat_is_closed(): void
    {
        $preview = $this->closedPreview();
        $preview['legend'] = [[
            'material' => 'Coral Brush',
            'canonical_material' => 'Coral Brush',
            'floor' => 'begane grond',
            'declared_total' => 100.0,
            'calculated_total' => 0.0,
            'difference' => -100.0,
            'status' => 'controleren',
        ]];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_WITH_WARNINGS', $closure['decision']);
        $this->assertSame(0, $closure['open_points']);
        $this->assertTrue(collect($closure['issues'])->contains(
            fn (array $issue) => ($issue['category'] ?? '') === 'legend'
                && ($issue['severity'] ?? '') === 'warning'
        ));
    }

    public function test_quantity_mismatch_blocks_even_without_controleren_rooms(): void
    {
        $preview = $this->closedPreview();
        $preview['import_report']['task_meters'] = 90.0;
        $preview['import_report']['meetstaat_task_meters'] = 100.0;
        $preview['import_report']['materials'][0]['found_task_meters'] = 90.0;
        $preview['import_report']['materials'][0]['difference'] = -10.0;
        $preview['import_report']['materials'][0]['status'] = 'controleren';

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertFalse($closure['ready']);
        $this->assertNotSame(0.0, $closure['totals']['difference']);
        $this->assertTrue(collect($closure['checks'])->contains(
            fn (array $check) => $check['key'] === 'project_total' && $check['ok'] === false
        ));
    }

    public function test_assembler_attaches_import_closure(): void
    {
        $preview = (new RoomImportAssembler)->assemble([
            'format' => 'test',
            'header' => [],
            'works' => [[
                'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'unit' => 'm2',
                'declared_total' => 50.97,
            ]],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => 50.97,
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 50.97,
                ]],
                'source' => 'meetstaat',
                'confidence' => 'hoog',
                'needs_review' => false,
            ]],
            'warnings' => [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ], null);

        $this->assertArrayHasKey('import_closure', $preview);
        $this->assertArrayHasKey('ready', $preview['import_closure']);
        $this->assertArrayHasKey('checks', $preview['import_closure']);
    }

    public function test_explained_rounding_within_five_cents_does_not_block(): void
    {
        $preview = $this->closedPreview();
        $preview['import_report']['task_meters'] = 50.99;
        $preview['import_report']['materials'][0]['found_task_meters'] = 50.99;
        $preview['import_report']['materials'][0]['difference'] = 0.02;
        $preview['import_report']['materials'][0]['status'] = 'ok';
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => 50.97,
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertEqualsWithDelta(0.02, (float) $closure['totals']['difference'], 0.001);
        $this->assertTrue((bool) ($closure['totals']['rounding_explained'] ?? false));
        $this->assertSame('+0,02 m² — verklaarde bronafronding ✓', $closure['totals']['difference_label']);
    }

    public function test_unexplained_small_difference_still_blocks(): void
    {
        $preview = $this->closedPreview();
        $preview['import_report']['task_meters'] = 50.99;
        // Geen materiaaldiffs: +0,02 is niet aantoonbaar als bronafronding.
        $preview['import_report']['materials'][0]['difference'] = null;
        $preview['import_report']['materials'][0]['status'] = 'ok';
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => 50.97,
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertFalse($closure['ready']);
        $this->assertSame('BLOCKED_CONFLICT', $closure['decision']);
        $this->assertFalse((bool) ($closure['totals']['rounding_explained'] ?? true));
        $this->assertSame('+0,02 m² — onverklaard verschil', $closure['totals']['difference_label']);
    }

    public function test_zero_material_residuals_do_not_explain_a_small_project_gap(): void
    {
        $preview = $this->closedPreview();
        $preview['import_report']['task_meters'] = 50.94;
        $preview['import_report']['materials'][0]['found_task_meters'] = 50.97;
        $preview['import_report']['materials'][0]['difference'] = 0.0;
        $preview['import_report']['materials'][0]['status'] = 'ok';
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => 50.97,
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertFalse($closure['ready']);
        $this->assertSame('BLOCKED_CONFLICT', $closure['decision']);
        $this->assertFalse((bool) ($closure['totals']['rounding_explained'] ?? true));
        $this->assertSame('-0,03 m² — onverklaard verschil', $closure['totals']['difference_label']);
    }

    public function test_minus_three_cents_is_explained_when_rounded_source_rules_prove_it(): void
    {
        $preview = $this->closedPreview();
        $preview['areas'][0]['tasks'][0]['quantity'] = 50.94;
        $preview['import_report']['task_meters'] = 50.94;
        $preview['import_report']['materials'][0]['found_task_meters'] = 50.94;
        $preview['import_report']['materials'][0]['difference'] = -0.03;
        $preview['import_report']['materials'][0]['status'] = 'ok';
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => 50.97,
            'by_material' => [[
                'material' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'expected_task_meters' => 50.97,
                'source' => 'materiaalblok_eindtotaal',
            ]],
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertEqualsWithDelta(-0.03, (float) $closure['totals']['difference'], 0.001);
        $this->assertTrue((bool) ($closure['totals']['rounding_explained'] ?? false));
        $this->assertSame('-0,03 m² — verklaarde bronafronding ✓', $closure['totals']['difference_label']);
    }

    public function test_legend_one_cent_rounding_does_not_block(): void
    {
        $preview = $this->closedPreview();
        $preview['legend'] = [[
            'material' => 'Desso Airmaster Atmos B747 9092, Tapijttegels',
            'canonical_material' => 'Desso Airmaster Atmos B747 9092, Tapijttegels',
            'floor' => 'verdieping 1',
            'declared_total' => 105.34,
            'calculated_total' => 105.33,
            'difference' => -0.01,
            'status' => 'controleren',
        ]];
        $preview['import_report']['task_meters'] = 105.33;
        $preview['import_report']['meetstaat_task_meters'] = 105.33;
        $preview['import_report']['task_source_meters_parsed'] = 105.33;
        $preview['import_report']['task_meters_expected'] = 105.33;
        $preview['import_report']['materials'][0]['material'] = 'Desso Airmaster Atmos B747 9092, Tapijttegels';
        $preview['import_report']['materials'][0]['found_task_meters'] = 105.33;
        $preview['import_report']['materials'][0]['expected_task_meters'] = 105.34;
        $preview['import_report']['materials'][0]['difference'] = -0.01;
        $preview['areas'][0]['tasks'][0]['work_name'] = 'Desso Airmaster Atmos B747 9092, Tapijttegels';
        $preview['areas'][0]['tasks'][0]['quantity'] = 105.33;
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => 105.34,
        ];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertTrue(collect($closure['checks'])->contains(
            fn (array $check) => $check['key'] === 'legend_totals' && $check['ok'] === true
        ));
    }

    public function test_project_header_mismatch_blocks_joint_import(): void
    {
        $preview = $this->closedPreview();
        $preview['sources']['materialenstaat'] = true;
        $preview['project_header_mismatches'] = [[
            'field' => 'project_number',
            'label' => 'Werknummer',
            'primary' => '250200015',
            'other' => '999999999',
            'source' => 'Meetstaat',
            'message' => 'Mogelijk bestand van ander project',
        ]];

        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertFalse($closure['ready']);
        $this->assertSame('BLOCKED_CONFLICT', $closure['decision']);
        $this->assertTrue(collect($closure['issues'])->contains(
            fn (array $issue) => str_contains((string) $issue['problem'], 'Mogelijk bestand van ander project')
        ));
        $this->assertTrue(collect($closure['checks'])->contains(
            fn (array $check) => $check['key'] === 'project_header' && $check['ok'] === false
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function closedPreview(): array
    {
        return [
            'sources' => [
                'meetstaat' => true,
                'plattegrond' => false,
                'materialenstaat' => false,
                'snijmaten' => false,
                'kleur' => false,
            ],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => 50.97,
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 50.97,
                ]],
                'source' => 'meetstaat',
                'source_label' => 'Meetstaat',
                'confidence' => 'hoog',
                'needs_review' => false,
                'material_source' => 'onbekend',
            ]],
            'works' => [[
                'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'unit' => 'm2',
                'declared_total' => 50.97,
            ]],
            'uncertain' => [],
            'duplicates' => [],
            'import_report' => [
                'task_meters' => 50.97,
                'meetstaat_task_meters' => 50.97,
                'task_meters_expected' => 50.97,
                'materials' => [[
                    'material' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'found_task_meters' => 50.97,
                    'expected_task_meters' => 50.97,
                    'difference' => 0.0,
                    'status' => 'ok',
                    'expected_source' => 'Meetstaat/MaterialList',
                ]],
                'floors' => [[
                    'floor' => 'begane grond',
                    'task_meters' => 50.97,
                    'meetstaat_task_meters' => 50.97,
                    'task_meters_difference' => 0.0,
                ]],
            ],
        ];
    }
}
