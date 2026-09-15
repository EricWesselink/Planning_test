<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\RoomImportAssembler;
use App\Services\ProjectLaborCalculator;
use App\Services\RoomWorkSetup;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class CalculationExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_files_preview_keeps_pdf_import_and_shows_calculation_labor(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->meetstaatFile(), $this->calculationFile()],
            'types' => ['meetstaat', 'calculatie'],
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Begrote arbeidsuren uit calculatie')
            ->assertSee('Schuren, primeren en egaliseren')
            ->assertSee(RoomWorkSetup::PRIMEN_EGALISEREN)
            ->assertSee('Tapijt')
            ->assertSee('Automatisch bevestigd')
            ->assertSee('Toeslag leggen proefkamer')
            ->assertSee('Totaal begrote uren')
            ->assertDontSee('Opdrachtlijst raambekleding', false);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_review_marks_meetstaat_as_leading_when_excel_quantity_differs(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                new UploadedFile(
                    SimplePdf::path(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : Eric Wesselink
Werknr        : 260200099
Bouwlaag: begane grond
NovaFloor Real 1100 coral, Linoleum
0.01 hal 1596,84 m²
Totaal 1596,84 m²
Netto : 1596,84 m²
TXT),
                    'Meetstaat.pdf',
                    'application/pdf',
                    null,
                    true
                ),
                UploadedFile::fake()->createWithContent('calc.csv', implode("\n", [
                    'KM;Groep;M/U;Productie Eenheid Omschrijving;Artikel Omschrijving;Aantal;EH;Kostprijs;Kostprijs Tot.',
                    'L;100;U;Elastische vloerbedekking;Elastische vloerbedekking;20;uur;48;960',
                    'M;100;M;Elastische vloerbedekking;NovaFloor Real 1100 coral, Linoleum;1677.90;m2;9;15101.1',
                ])),
            ],
            'types' => ['meetstaat', 'calculatie'],
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('LEIDEND')
            ->assertSee('1.596,84')
            ->assertSee('1.677,90')
            ->assertSee('Meetstaat is leidend voor de netto')
            ->assertDontSee('Hoeveelheid wijkt af tussen bronnen');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_import_stores_original_lines_and_applies_budget_hours_from_excel_rates(): void
    {
        $user = User::factory()->create();
        $token = $this->previewToken($user);
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Eric Wesselink',
            'project_number' => '260200091',
            'calculation_labor' => $this->confirmedLabor(),
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200091')->first();
        $this->assertNotNull($project);
        $this->assertSame('48.00', $project->basis_uurtarief);
        $this->assertGreaterThan(0, $project->areas()->count());

        $this->assertSame(18, $project->calculationLines()->count());
        $this->assertSame(6, $project->calculationLines()->where('is_labor', true)->count());

        $prep = $project->workItems()->where('name', RoomWorkSetup::PRIMEN_EGALISEREN)->first();
        $this->assertNotNull($prep);
        $this->assertSame('48.00', $prep->uurtarief);
        $this->assertEqualsWithDelta(30.06, (float) $prep->begrote_uren, 0.02);

        $prepLine = $project->calculationLines()
            ->where('is_labor', true)
            ->where('production_description', 'like', '%egaliseren%')
            ->first();
        $this->assertNotNull($prepLine);
        $this->assertSame('m2', $prepLine->unit);
        $this->assertEqualsWithDelta(392.04, (float) $prepLine->quantity, 0.02);
        $this->assertEqualsWithDelta(30.064, (float) $prepLine->hours, 0.001);

        $pvc = $project->workItems()->where('name', 'PVC')->first();
        $this->assertNotNull($pvc);
        $this->assertSame('48.00', $pvc->uurtarief);
        $this->assertEqualsWithDelta(75.23, (float) $pvc->begrote_uren, 0.02);
        $this->assertEqualsWithDelta(392.04, (float) $pvc->begrote_hoeveelheid, 0.02);

        $tapijt = $project->workItems()->where('name', 'Tapijt')->first();
        $this->assertNotNull($tapijt);
        $this->assertEqualsWithDelta(15.0, (float) $tapijt->begrote_uren, 0.01);

        $laborLines = $project->calculationLines()->where('is_labor', true)->get();
        $expectedTotal = round((float) $laborLines->sum(fn ($line) => (float) ($line->total_cost ?? $line->labor_cost ?? 0)), 2);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Oorspronkelijke calculatieregels (6)')
            ->assertSee('392,04 m²')
            ->assertSee('Totaal bedrag')
            ->assertSee(Format::euro($expectedTotal, 2))
            ->assertDontSee('>Bron</th>', false)
            ->assertSee('11-ericwesselink.xlsx')
            ->assertSee('Excel calculatie')
            ->assertDontSee('Ingepland:')
            ->assertDontSee('Tarief: €');
    }

    public function test_reimporting_the_same_excel_file_does_not_duplicate_lines(): void
    {
        $user = User::factory()->create();
        $project = $this->importProject($user);

        $response = $this->actingAs($user)->post(route('projects.meetstaat.store', $project), [
            'meetstaat' => $this->calculationFile(),
        ]);
        $response->assertRedirect();
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->actingAs($user)
            ->post(route('projects.sources.confirm', $token))
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame(18, $project->calculationLines()->count());
        $this->assertSame(6, $project->calculationLines()->where('is_labor', true)->count());
        $this->assertSame(2, $project->documents()->where('document_type', 'calculatie')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'calculatie')->where('is_current', true)->count());
    }

    public function test_generic_covering_rows_import_without_manual_work_selection(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->meetstaatFile(), $this->genericCalculationFile()],
            'types' => ['meetstaat', 'calculatie'],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('NovaFloor Real 1100 coral')
            ->assertSee('Automatisch bevestigd')
            ->assertDontSee('Handmatige controle vereist');

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Generic Covering',
            'project_number' => '260200094',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200094')->first();
        $this->assertNotNull($project);
        $this->assertGreaterThan(0, $project->calculationLines()->where('is_labor', true)->count());
        $this->assertTrue(
            $project->workItems()->get()->contains(
                fn ($item): bool => str_contains((string) $item->name, 'NovaFloor Real')
            )
        );
    }

    public function test_uncertain_labor_rows_do_not_block_import(): void
    {
        $user = User::factory()->create();
        $token = $this->previewToken($user, $this->unmatchableCalculationFile());
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)
            ->from(route('projects.review', $token))
            ->post(route('projects.import', $token), [
                'customer_name' => 'Nicon vloeren',
                'project_name' => 'Eric Wesselink',
                'project_number' => '260200092',
            ])
            ->assertRedirect();

        $project = Project::query()->where('project_number', '260200092')->first();
        $this->assertNotNull($project);
    }

    public function test_vakman_does_not_see_calculation_rates_on_the_board_or_excel_download(): void
    {
        $user = User::factory()->create();
        $project = $this->importProject($user);
        $worker = Worker::query()->create([
            'name' => 'Jan Vakman',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $project->assignments()->create([
            'worker_id' => $worker->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
        ]);
        $vakman = User::factory()->vakman($worker->id)->create();
        $document = $project->documents()->where('document_type', 'calculatie')->first();
        $this->assertNotNull($document);

        $this->actingAs($vakman)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertDontSee('Begroot uren')
            ->assertDontSee('Ingepland uren')
            ->assertDontSee('Budget over')
            ->assertDontSee('Begroot €/m²')
            ->assertDontSee('€48', false);

        $this->actingAs($vakman)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Oorspronkelijke calculatieregels')
            ->assertDontSee('€48,00');

        $this->actingAs($vakman)
            ->get(route('projects.documents.show', [$project, $document]))
            ->assertForbidden();
    }

    public function test_square_meter_price_uses_excel_cost_and_prices_linear_meters(): void
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);
        $project = Project::query()->create([
            'project_number' => '260200093',
            'customer_id' => $customer->id,
            'name' => 'Prijscheck',
            'status' => ProjectStatus::Gepland,
            'basis_uurtarief' => 48,
        ]);
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'begrote_uren' => 40,
            'begrote_hoeveelheid' => 200,
            'uurtarief' => 48,
        ]);
        $plinth = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten',
            'unit' => 'm1',
            'ordered_quantity' => 40,
            'begrote_uren' => 8,
            'begrote_hoeveelheid' => 40,
            'uurtarief' => 48,
        ]);
        $pvc->progressEntries()->create([
            'project_id' => $project->id,
            'completed_quantity' => 100,
            'unit' => 'm2',
            'worked_hours' => 30,
            'date' => '2026-09-07',
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($project->fresh(['workItems.progressEntries', 'assignments.crewMembers', 'workOrders']));
        $byName = collect($labor['items'])->keyBy('name');

        $this->assertSame(9.6, $byName['PVC']['budget_cost_per_m2']);
        $this->assertSame(14.4, $byName['PVC']['actual_cost_per_m2']);
        $this->assertSame(9.6, $byName['Plinten']['budget_cost_per_m2']);
        $this->assertNull($byName['Plinten']['actual_cost_per_m2']);
        $this->assertSame('m¹', $byName['Plinten']['unit']);
        $this->assertNotEmpty($byName['PVC']['warnings']);
        $this->assertContains('Werkelijke arbeidsprijs per m² ligt boven begroot', $byName['PVC']['warnings']);
    }

    /**
     * @return array<int, array{work_name: string}>
     */
    private function confirmedLabor(): array
    {
        return [
            0 => ['work_name' => RoomWorkSetup::PRIMEN_EGALISEREN],
            1 => ['work_name' => 'PVC'],
            2 => ['work_name' => 'PVC'],
            3 => ['work_name' => 'Tapijt'],
            4 => ['work_name' => 'Overige'],
            5 => ['work_name' => 'Overige'],
        ];
    }

    private function previewToken(User $user, ?UploadedFile $calculation = null): string
    {
        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->meetstaatFile(), $calculation ?? $this->calculationFile()],
            'types' => ['meetstaat', 'calculatie'],
        ]);
        $response->assertRedirect();

        return basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    private function importProject(User $user): Project
    {
        $token = $this->previewToken($user);
        $this->makeCachedPreviewReady($token);
        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Eric Wesselink',
            'project_number' => '260200091',
            'calculation_labor' => $this->confirmedLabor(),
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200091')->first();
        $this->assertNotNull($project);

        return $project;
    }

    private function makeCachedPreviewReady(string $token): void
    {
        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $preview = $payload['preview'];
        $preview['sources'] = array_merge($preview['sources'] ?? [], [
            'materialenstaat' => false,
            'kleur' => false,
        ]);
        $preview['closure_baselines'] = [
            'meetstaat_areas' => [],
            'material_works' => [],
        ];
        $preview['uncertain'] = [];
        $preview['duplicates'] = [];
        $preview['legend'] = [];
        $preview['material_check'] = [];

        foreach ($preview['areas'] as &$area) {
            $area['confidence'] = 'hoog';
            $area['needs_review'] = false;
            $area['fill_color'] = null;
            $area['legend_material'] = null;
            if (mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend' || trim((string) ($area['floor'] ?? '')) === '') {
                $area['floor'] = 'begane grond';
            }
        }
        unset($area);

        if (($preview['areas'] ?? []) === []) {
            $preview['areas'] = [[
                'floor' => 'begane grond',
                'room_number' => '0.01',
                'room_name' => 'Testruimte',
                'square_meters' => 10.0,
                'tasks' => [[
                    'work_name' => 'PVC',
                    'unit' => 'm2',
                    'quantity' => 10.0,
                ]],
                'source' => 'handmatig',
                'confidence' => 'hoog',
                'needs_review' => false,
            ]];
        }

        $preview = (new RoomImportAssembler)->refreshClosure($preview);
        $taskMeters = (float) ($preview['import_report']['task_meters'] ?? 0);
        $preview['import_report']['meetstaat_task_meters'] = $taskMeters;
        $preview['import_report']['task_meters_expected'] = $taskMeters;
        $preview['expected_task_totals'] = [
            'known' => true,
            'project_total' => $taskMeters,
            'by_material' => [],
            'source_label' => 'test_force_ready',
        ];
        $preview = (new ImportClosureEvaluator)->attach($preview);
        $this->assertTrue($preview['import_closure']['ready'] ?? false, 'Testpreview moet sluitend gemaakt kunnen worden.');
        $payload['preview'] = $preview;
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());
    }

    private function meetstaatFile(): UploadedFile
    {
        return new UploadedFile(
            SimplePdf::path(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : Eric Wesselink
Werknr        : 260200091
Bouwlaag: begane grond
PVC
0.07 groepsruimte 50,97 m²
Totaal 50,97 m²
Netto : 50,97 m²
TXT),
            'Meetstaat.pdf',
            'application/pdf',
            null,
            true
        );
    }

    private function calculationFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/11-ericwesselink.xlsx'),
            '11-ericwesselink.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function genericCalculationFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('generic-calc.csv', implode("\n", [
            'KM;Groep;M/U;Productie Eenheid Omschrijving;Artikel Omschrijving;Aantal;EH;Kostprijs;Kostprijs Tot.',
            'L;100;U;Elastische vloerbedekking;Elastische vloerbedekking;10;uur;48;480',
            'M;100;M;Elastische vloerbedekking;NovaFloor Real 1100 coral, Linoleum;80;m2;9;720',
            'L;100;U;Zachte vloerbedekking;Zachte vloerbedekking;5;uur;48;240',
            'M;100;M;Zachte vloerbedekking;SoftTile 55 grey, Tapijt;40;m2;9;360',
        ]));
    }

    private function unmatchableCalculationFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('unknown-calc.csv', implode("\n", [
            'KM;Groep;M/U;Productie Eenheid Omschrijving;Artikel Omschrijving;Aantal;EH;Kostprijs;Kostprijs Tot.',
            'L;100;U;Onbekende extra werkzaamheid xyz;Onbekende extra werkzaamheid xyz;4;uur;48;192',
        ]));
    }
}
