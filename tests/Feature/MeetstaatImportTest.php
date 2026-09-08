<?php

namespace Tests\Feature;

use App\Enums\ImportDecision;
use App\Models\Project;
use App\Models\User;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\RoomImportAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ColoredFloorPlanPdf;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class MeetstaatImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_shows_a_single_project_file_dropzone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('Projectbestanden')
            ->assertSee('Sleep bestanden hierheen')
            ->assertSee('Bestanden uitlezen en combineren')
            ->assertSee('Maximaal 100 MB per bestand')
            ->assertSee('Handmatig project')
            ->assertDontSee('PDF-meetstaat (optioneel)');
    }

    public function test_pdf_meetstaat_opens_review_before_import(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('Nicon vloeren')
            ->assertSee('260200090')
            ->assertSee('0.07')
            ->assertSee('groepsruimte')
            ->assertSee('Marmoleum Real')
            ->assertSee('Plinten wit')
            ->assertSee('PU gietvloer')
            ->assertSee('2.226,73')
            ->assertSee('Plattegrond / tekening')
            ->assertSee('Werkadres')
            ->assertDontSee('Meegeleverd:');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_combined_uploads_open_review_without_saving_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf('MaterialList.pdf', "Materialenstaat\nMarmoleum Real, 3120 rosato, Linoleum\nNetto : 75,00 m²\n"),
                $this->simplePdf('Snijmaten.pdf', "Snijmaten\nMarmoleum Real, 3120 rosato, Linoleum\nBouwlaag: begane grond\n0.07 groepsruimte 50,97 m2\n"),
                $this->floorPlanFile(),
            ],
            'types' => ['materialenstaat', 'snijmaten', 'plattegrond'],
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('MaterialList.pdf')
            ->assertSee('Materialenstaat')
            ->assertSee('Snijmaten.pdf')
            ->assertSee('0.07')
            ->assertSee('groepsruimte')
            ->assertSee('Marmoleum Real')
            ->assertSee('Plattegrond');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_review_prefers_materialenstaat_project_header_and_keeps_werknummer_as_text(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf('MaterialList.pdf', <<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknummer    : 250200015
Datum         : 07/09/2026
Marmoleum Real, 3120 rosato, Linoleum
Netto : 50,97 m²
TXT),
                $this->simplePdf('Meetstaat.pdf', <<<'TXT'
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
TXT),
            ],
            'types' => ['materialenstaat', 'meetstaat'],
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Projectgegevens')
            ->assertSee('Bron: Materialenstaat')
            ->assertSee('value="Nicon vloeren"', false)
            ->assertSee('value="250200015"', false)
            ->assertSee('value="11P230988 TWC studentenhuisvesting Utrecht"', false)
            ->assertSee('value="2026-09-07"', false)
            ->assertSee('Mogelijk bestand van ander project')
            ->assertSee('999999999')
            ->assertSee('data-review-fold="projectgegevens" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="herkenning-debug" open data-review-open="1"', false)
            ->assertDontSee('value="999999999"', false)
            ->assertDontSee('value="Andere klant"', false);
    }

    public function test_imported_project_keeps_full_materialenstaat_reference(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf('MaterialList.pdf', <<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknummer    : 250200015
Datum         : 07/09/2026
Marmoleum Real, 3120 rosato, Linoleum
Netto : 50,97 m²
TXT),
                $this->simplePdf('Meetstaat.pdf', <<<'TXT'
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
TXT),
            ],
            'types' => ['materialenstaat', 'meetstaat'],
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => '11P230988 TWC studentenhuisvesting Utrecht',
            'project_number' => '250200015',
            'date' => '2026-09-07',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '250200015')->first();
        $this->assertNotNull($project);
        $this->assertSame('250200015', $project->project_number);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $project->name);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $project->reference());
        $this->assertSame('Referentie: 11P230988 TWC studentenhuisvesting Utrecht', $project->notes);
        $this->assertSame('Nicon vloeren', $project->customer?->name);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $project->name);
        $this->assertTrue(str_starts_with($project->name, '11P230988'));

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('250200015')
            ->assertSee('11P230988')
            ->assertSee('Werk 250200015')
            ->assertSee('TWC studentenhuisvesting Utrecht');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Projectnr.')
            ->assertSee('11P230988')
            ->assertSee('Werk')
            ->assertSee('250200015')
            ->assertSee('TWC studentenhuisvesting Utrecht')
            ->assertSee('Opdrachtgever: Nicon vloeren');

        $this->actingAs($user)
            ->get(route('planning', ['project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Projectnr.')
            ->assertSee('11P230988')
            ->assertSee('Werk')
            ->assertSee('250200015')
            ->assertSee('TWC studentenhuisvesting Utrecht');
    }

    public function test_csv_upload_via_project_files_opens_review(): void
    {
        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent('meetstaat.csv', implode("\n", [
            'nummer,naam,verdieping,m2,pvc',
            '0.07,Groepsruimte,Begane grond,50.97,50.97',
        ])."\n");

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$csv],
            'types' => ['meetstaat'],
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('0.07')
            ->assertSee('Groepsruimte');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_combined_import_stores_each_source_document(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf('MaterialList.pdf', "Materialenstaat\nMarmoleum Real, 3120 rosato, Linoleum\nNetto : 75,00 m²\n"),
                $this->floorPlanFile('Plattegrond_BG.pdf'),
            ],
            'types' => ['materialenstaat', 'plattegrond'],
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Gecombineerd',
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Gecombineerd')->first();
        $this->assertNotNull($project);
        $this->assertSame(1, $project->documents()->where('document_type', 'materialenstaat')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'plattegrond')->count());
        $this->assertSame(0, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame('Plattegrond_BG.pdf', $project->plattegrond()?->original_filename);
        $room = $project->areas()->where('area_number', '0.07')->first();
        $this->assertNotNull($room);
        $this->assertEqualsWithDelta(50.97, (float) $room->square_meters, 0.02);
    }

    public function test_rejects_an_unsupported_project_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.create'))
            ->post(route('projects.preview'), [
                'files' => [UploadedFile::fake()->create('notities.exe', 20)],
            ])
            ->assertRedirect(route('projects.create'))
            ->assertSessionHasErrors('files.0');
    }

    public function test_rejects_an_oversized_project_file_with_a_dutch_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.create'))
            ->post(route('projects.preview'), [
                'files' => [UploadedFile::fake()->create('plattegrond.pdf', 102401)],
            ])
            ->assertRedirect(route('projects.create'))
            ->assertSessionHasErrors([
                'files.0' => 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.',
            ]);
    }

    public function test_confirmed_review_creates_the_project_without_a_drawing(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Laakse Tuinen Amersfoort',
            'project_number' => '260200090',
            'date' => '2026-09-02',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200090')->first();
        $this->assertNotNull($project);
        $this->assertGreaterThan(50, $project->areas()->count());
        $this->assertGreaterThanOrEqual(6, $project->workItems()->count());
        $this->assertTrue($project->workItems->contains(fn ($item) => str_contains($item->name, 'Marmoleum Real')));
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(0, $project->documents()->where('document_type', 'plattegrond')->count());
        $this->assertNull($project->plattegrond());
        $this->assertNull($project->address);
        $this->assertNull($project->nawLine());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Nog geen tekening. Upload een plattegrond (PDF).');
    }

    public function test_meetstaat_and_drawing_are_stored_as_separate_documents(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
            'plattegrond' => $this->drawingFile('Plattegrond_BG.pdf'),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('Meegeleverd:')
            ->assertSee('Plattegrond_BG.pdf');

        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)
            ->post(route('projects.import', $token), [
                'customer_name' => 'Nicon vloeren',
                'project_name' => 'Laakse Tuinen Amersfoort',
                'project_number' => '260200090',
                'date' => '2026-09-02',
            ])
            ->assertRedirect();

        $project = Project::query()->where('project_number', '260200090')->first();
        $this->assertNotNull($project);
        $project->load('documents');

        $meetstaat = $project->documents->firstWhere('document_type', 'meetstaat');
        $drawing = $project->plattegrond();

        $this->assertNotNull($meetstaat);
        $this->assertNotNull($drawing);
        $this->assertNotSame($meetstaat->id, $drawing->id);
        $this->assertSame('Meetbon_Laakse_Tuinen.pdf', $meetstaat->original_filename);
        $this->assertSame('Plattegrond_BG.pdf', $drawing->original_filename);
        $this->assertStringContainsString('/meetstaat/', $meetstaat->file_path);
        $this->assertStringContainsString('/plattegrond/', $drawing->file_path);
        $this->assertGreaterThan(50, $project->areas()->count());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Nog geen tekening. Upload een plattegrond (PDF).')
            ->assertSee('Plattegrond_BG.pdf')
            ->assertSee(route('projects.drawings.detect', [$project, $drawing], false));
    }

    public function test_review_can_attach_a_drawing_that_was_skipped_on_upload(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)
            ->post(route('projects.import', $token), [
                'customer_name' => 'Nicon vloeren',
                'project_name' => 'Laakse Tuinen Amersfoort',
                'project_number' => '260200090',
                'plattegrond' => $this->drawingFile('Tekening_later.pdf'),
            ])
            ->assertRedirect();

        $project = Project::query()->where('project_number', '260200090')->first();
        $this->assertNotNull($project);
        $drawing = $project->fresh('documents')->plattegrond();

        $this->assertNotNull($drawing);
        $this->assertSame('Tekening_later.pdf', $drawing->original_filename);
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->count());
    }

    public function test_review_can_store_a_work_address_for_google_maps(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Laakse Tuinen Amersfoort',
            'project_number' => '260200090',
            'address' => 'Schoolstraat 1',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200090')->first();
        $this->assertNotNull($project);
        $this->assertSame('Schoolstraat 1', $project->address);
        $this->assertSame('3811 AA', $project->postal_code);
        $this->assertSame('Amersfoort', $project->city);
        $this->assertSame('Schoolstraat 1, 3811 AA Amersfoort', $project->nawLine());
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode('Schoolstraat 1, 3811 AA Amersfoort').'&travelmode=driving',
            $project->googleMapsUrl()
        );

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Schoolstraat 1, 3811 AA Amersfoort')
            ->assertSee('Navigeren')
            ->assertSee($project->googleMapsUrl());
    }

    public function test_review_marks_mixed_floor_coverings_for_control_and_does_not_sum_them(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get(route('projects.review', $token))
            ->assertOk()
            ->assertSee('Verdieping')
            ->assertSee('Herkend via')
            ->assertSee('Controleren')
            ->assertSee('0.35')
            ->assertSee('egels');

        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Laakse Tuinen Amersfoort',
            'project_number' => '260200090',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200090')->first();
        $this->assertNotNull($project);
        $area = $project->areas()->where('area_number', '0.35')->first();
        $this->assertNotNull($area);
        $this->assertNull($area->square_meters);
        $this->assertSame(1, $project->areas()->where('area_number', '0.35')->count());

        $vloer = $area->tasks()->with('workItem')->get()->filter(
            fn ($task) => $task->workItem && $task->unit->value === 'm2' && ! str_contains(mb_strtolower($task->workItem->name), 'plint')
        );
        $this->assertGreaterThanOrEqual(2, $vloer->count());
        $this->assertEqualsWithDelta(51.94, (float) $vloer->first(fn ($task) => str_contains($task->workItem->name, 'Marmoleum Real'))->ordered_quantity, 0.02);
        $this->assertEqualsWithDelta(10.54, (float) $vloer->first(fn ($task) => str_contains($task->workItem->name, 'Walton'))->ordered_quantity, 0.02);

        $single = $project->areas()->where('area_number', '0.07')->first();
        $this->assertNotNull($single);
        $this->assertEqualsWithDelta(50.97, (float) $single->square_meters, 0.02);
    }

    public function test_plattegrond_fills_physical_area_when_meetstaat_has_two_coverings(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
            'plattegrond' => $this->floorPlanFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Laakse Tuinen Amersfoort',
            'project_number' => '260200091',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260200091')->first();
        $this->assertNotNull($project);
        $area = $project->areas()->where('area_number', '0.35')->first();
        $this->assertNotNull($area);
        $this->assertEqualsWithDelta(55.0, (float) $area->square_meters, 0.02);
        $this->assertGreaterThanOrEqual(2, $area->tasks()->count());
        $this->assertSame('Plattegrond_BG.pdf', $project->plattegrond()?->original_filename);
    }

    public function test_plattegrond_without_meetstaat_still_opens_review(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'plattegrond' => $this->floorPlanFile(),
        ]);

        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('0.07')
            ->assertSee('groepsruimte')
            ->assertSee('Plattegrond')
            ->assertSee('Controleren')
            ->assertDontSee('Deze PDF bevat geen leesbare tekst');
    }

    public function test_colored_plattegrond_shows_legend_materials_without_merging_same_names(): void
    {
        $user = User::factory()->create();
        $file = new UploadedFile(
            ColoredFloorPlanPdf::path(),
            'Plattegrond_BG_Griftland.pdf',
            'application/pdf',
            null,
            true
        );

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'plattegrond' => $file,
        ]);

        $html = $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Herkend via')
            ->assertSee('Confidence')
            ->assertSee('Legenda versus materiaaltaken')
            ->assertSee('Som taken')
            ->assertSee('Eenheid')
            ->assertSee('tekenlokaal')
            ->assertSee('Dark Sand')
            ->assertSee('Tekening + kleur + legenda')
            ->assertSee('Herkenning (debug)')
            ->assertSee('data-review-fold="herkenning-debug"', false)
            ->assertDontSee('data-review-fold="herkenning-debug" open data-review-open="1"', false)
            ->assertSee('data-review-fold="ruimtes" open data-review-open="1"', false)
            ->assertSee('Bouwlaag')
            ->assertSee('Gekozen naam')
            ->assertSee('Kamercontour-id')
            ->assertSee('Fill-kleur')
            ->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'value="kunstplein"'));
        $this->assertGreaterThanOrEqual(20, substr_count($html, 'data-area-row'));
    }

    public function test_plattegrond_only_import_creates_rooms_and_keeps_the_drawing(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'plattegrond' => $this->floorPlanFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Alleen tekening',
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Alleen tekening')->first();
        $this->assertNotNull($project);
        $this->assertSame(0, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertNotNull($project->plattegrond());
        $this->assertSame('Plattegrond_BG.pdf', $project->plattegrond()->original_filename);
        $this->assertGreaterThanOrEqual(2, $project->areas()->count());
        $room = $project->areas()->where('area_number', '0.07')->first();
        $this->assertNotNull($room);
        $this->assertEqualsWithDelta(50.97, (float) $room->square_meters, 0.02);
    }

    public function test_review_can_add_a_manual_room_without_pdfs(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'));
        $preview->assertRedirect();
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);
        $this->allowReviewAreaPost($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Handmatig',
            'project_name' => 'Zonder PDF',
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.01',
                'room_name' => 'Entree',
                'square_meters' => '12,50',
                'source' => 'handmatig',
                'confidence' => 'hoog',
                'tasks' => [[
                    'work_name' => 'PVC',
                    'quantity' => '12,50',
                    'unit' => 'm2',
                ]],
            ]],
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Zonder PDF')->first();
        $this->assertNotNull($project);
        $area = $project->areas()->where('area_number', '0.01')->first();
        $this->assertNotNull($area);
        $this->assertSame('Entree', $area->name);
        $this->assertEqualsWithDelta(12.5, (float) $area->square_meters, 0.001);
        $this->assertEqualsWithDelta(12.5, (float) $area->tasks()->first()->ordered_quantity, 0.001);
    }

    public function test_review_can_correct_square_meters_before_import(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'plattegrond' => $this->floorPlanFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);
        $this->allowReviewAreaPost($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Correctie',
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'Groepsruimte',
                'square_meters' => '51,10',
                'source' => 'plattegrond',
                'confidence' => 'hoog',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'quantity' => '51,10',
                    'unit' => 'm2',
                ]],
            ]],
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Correctie')->first();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $this->assertNotNull($area);
        $this->assertSame('Groepsruimte', $area->name);
        $this->assertEqualsWithDelta(51.10, (float) $area->square_meters, 0.001);
        $this->assertSame(1, $project->areas()->count());
    }

    private function makeCachedPreviewReady(string $token): void
    {
        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $preview = $payload['preview'];

        // Persistence-tests forceren een sluitende snapshot zonder herkenningsbugs te maskeren in productie.
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
            $preview['sources']['meetstaat'] = false;
            $preview['sources']['plattegrond'] = false;
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
        foreach ($preview['import_report']['materials'] ?? [] as $index => $row) {
            $preview['import_report']['materials'][$index]['status'] = 'ok';
            $preview['import_report']['materials'][$index]['difference'] = 0.0;
            $preview['import_report']['materials'][$index]['expected_task_meters'] = $row['found_task_meters'] ?? 0;
        }
        foreach ($preview['import_report']['floors'] ?? [] as $index => $row) {
            $preview['import_report']['floors'][$index]['task_meters_difference'] = 0.0;
            $preview['import_report']['floors'][$index]['meetstaat_task_meters'] = $row['task_meters'] ?? 0;
        }
        $preview = (new ImportClosureEvaluator)->attach($preview);
        $this->assertTrue($preview['import_closure']['ready'] ?? false, 'Testpreview moet sluitend gemaakt kunnen worden.');
        $payload['preview'] = $preview;
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());
    }

    /**
     * READY_AUTOMATIC negeert area-POST; voor review-correctietests tijdelijk openzetten.
     */
    private function allowReviewAreaPost(string $token): void
    {
        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $payload['preview']['import_closure']['ready'] = false;
        $payload['preview']['import_closure']['decision'] = ImportDecision::BlockedConflict->value;
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());
    }

    private function meetstaatFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf'),
            'Meetbon_Laakse_Tuinen.pdf',
            'application/pdf',
            null,
            true
        );
    }

    private function drawingFile(string $name): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf'),
            $name,
            'application/pdf',
            null,
            true
        );
    }

    private function floorPlanFile(string $name = 'Plattegrond_BG.pdf'): UploadedFile
    {
        return $this->simplePdf(
            $name,
            "begane grond\n0.07 groepsruimte 50,97 m2\n0.08 berging 24,01 m2\n0.35 Egels 55,00 m2\n"
        );
    }

    private function simplePdf(string $name, string $text): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }
}
