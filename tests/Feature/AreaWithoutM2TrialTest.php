<?php

namespace Tests\Feature;

use App\Models\Calculation;
use App\Models\CalculationLine;
use App\Models\User;
use App\Services\AreaWithoutM2Trial\RasterPageReader;
use App\Services\AreaWithoutM2Trial\TrialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DimensionedRoomPdf;
use Tests\Support\ImageOnlyPdf;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class AreaWithoutM2TrialTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_trial_module(): void
    {
        $this->get(route('calculations.area-without-m2.create'))->assertRedirect(route('login'));
    }

    public function test_vakman_is_forbidden_from_the_trial_module(): void
    {
        $user = User::factory()->vakman()->create();

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.create'))
            ->assertForbidden();
    }

    public function test_planner_sees_the_trial_module_on_the_calculations_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calculations.index'))
            ->assertOk()
            ->assertSee('Calculatie zonder m² (proef)')
            ->assertSee(route('calculations.area-without-m2.create'), false);
    }

    public function test_create_form_explains_that_printed_square_metres_are_control_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.create'))
            ->assertOk()
            ->assertSee('Calculatie zonder m²')
            ->assertSee('De m² op de tekening wordt niet gebruikt voor de berekening')
            ->assertSee('scans zonder tekstlaag')
            ->assertSee('Niets wordt naar een echte calculatie geschreven')
            ->assertSee('data-area-without-m2-progress', false)
            ->assertSee('De tekening wordt geüpload. Daarna volgt het herkennen van ruimtes en maten.')
            ->assertSee('Formulierdiagnose')
            ->assertSee('Input-name die PHP leest · drawing')
            ->assertSee('name="client_filename"', false)
            ->assertSee('name="drawing"', false)
            ->assertSee(route('calculations.area-without-m2.store'), false);
    }

    public function test_rejects_a_missing_drawing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('calculations.area-without-m2.create'))
            ->post(route('calculations.area-without-m2.store'))
            ->assertRedirect(route('calculations.area-without-m2.create'))
            ->assertSessionHasErrors(['drawing' => 'Upload een PDF-tekening.']);
    }

    public function test_a_second_upload_analyzes_only_the_new_file_and_shows_its_filename(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $first = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf("OPSLAG\nA-00-03\n3500\n2000", 'print.pdf'),
            'client_filename' => 'print.pdf',
            'client_input_name' => 'drawing',
            'client_file_input_count' => '1',
            'client_file_input_names' => 'drawing',
        ]);
        $second = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf("WOONKAMER\nKEUKEN\nSLAAPKAMER 1\nSLAAPKAMER 2\n3000\n3500", 'proef.pdf'),
            'client_filename' => 'proef.pdf',
            'client_input_name' => 'drawing',
            'client_file_input_count' => '1',
            'client_file_input_names' => 'drawing',
        ]);

        $first->assertRedirect();
        $second->assertRedirect();
        $this->assertSame(303, $second->getStatusCode());

        $firstId = basename((string) parse_url((string) $first->headers->get('Location'), PHP_URL_PATH));
        $secondId = basename((string) parse_url((string) $second->headers->get('Location'), PHP_URL_PATH));
        $this->assertNotSame($firstId, $secondId);

        $firstPayload = json_decode((string) Storage::disk('local')->get(TrialStore::DIRECTORY.'/'.$firstId.'/result.json'), true);
        $secondPayload = json_decode((string) Storage::disk('local')->get(TrialStore::DIRECTORY.'/'.$secondId.'/result.json'), true);

        $this->assertSame('print.pdf', $firstPayload['filename'] ?? null);
        $this->assertSame('proef.pdf', $secondPayload['filename'] ?? null);
        $this->assertSame('print.pdf', $firstPayload['original_filename'] ?? null);
        $this->assertSame('proef.pdf', $secondPayload['original_filename'] ?? null);
        $this->assertNotSame('', $firstPayload['file_hash'] ?? '');
        $this->assertNotSame('', $secondPayload['file_hash'] ?? '');
        $this->assertNotSame($firstPayload['file_hash'], $secondPayload['file_hash']);
        $this->assertStringContainsString($firstId, (string) ($firstPayload['analyzed_path'] ?? ''));
        $this->assertStringContainsString($secondId, (string) ($secondPayload['analyzed_path'] ?? ''));
        $this->assertGreaterThan(0, (int) ($secondPayload['file_size'] ?? 0));

        $this->assertSame('print.pdf', $firstPayload['received_upload']['original_name'] ?? null);
        $this->assertSame('proef.pdf', $secondPayload['received_upload']['original_name'] ?? null);
        $this->assertSame('print.pdf', $firstPayload['received_upload']['client_filename'] ?? null);
        $this->assertSame('proef.pdf', $secondPayload['received_upload']['client_filename'] ?? null);
        $this->assertSame($firstPayload['file_hash'], $firstPayload['received_upload']['tmp_sha256'] ?? null);
        $this->assertSame($secondPayload['file_hash'], $secondPayload['received_upload']['tmp_sha256'] ?? null);
        $this->assertSame($secondPayload['file_hash'], $secondPayload['received_upload']['stored_sha256'] ?? null);
        $this->assertSame('calculations.area-without-m2.store', $secondPayload['received_upload']['route'] ?? null);
        $this->assertSame('drawing', $secondPayload['received_upload']['input_name_read'] ?? null);
        $this->assertCount(1, $secondPayload['received_upload']['all_files'] ?? []);
        $this->assertSame('drawing', $secondPayload['received_upload']['all_files'][0]['field'] ?? null);
        $this->assertSame('proef.pdf', $secondPayload['received_upload']['all_files'][0]['original_name'] ?? null);
        $this->assertSame(
            'Geen wissel: browser, POST originalName en SHA-256 van het ontvangen bestand komen overeen.',
            $secondPayload['received_upload']['mismatch'] ?? null,
        );

        $firstPdf = Storage::disk('local')->path(TrialStore::DIRECTORY.'/'.$firstId.'/drawing.pdf');
        $secondPdf = Storage::disk('local')->path(TrialStore::DIRECTORY.'/'.$secondId.'/drawing.pdf');
        $this->assertSame($firstPayload['file_hash'], hash_file('sha256', $firstPdf));
        $this->assertSame($secondPayload['file_hash'], hash_file('sha256', $secondPdf));

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $firstId))
            ->assertOk()
            ->assertSee('Proefresultaat voor print.pdf')
            ->assertSee('A-00-03')
            ->assertSee((string) $firstPayload['file_hash']);

        $secondShow = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $secondId));
        $this->assertStringContainsString('no-store', (string) $secondShow->headers->get('Cache-Control'));
        $secondShow
            ->assertOk()
            ->assertSee('Proefresultaat voor proef.pdf')
            ->assertSee('WOONKAMER')
            ->assertSee('KEUKEN')
            ->assertSee('SLAAPKAMER 1')
            ->assertSee('SLAAPKAMER 2')
            ->assertSee((string) $secondPayload['file_hash'])
            ->assertSee('Ontvangen upload')
            ->assertSee('POST originalName')
            ->assertSee('Browser gekozen bestand')
            ->assertSee('Geen wissel: browser, POST originalName en SHA-256 van het ontvangen bestand komen overeen.')
            ->assertDontSee('A-00-03')
            ->assertDontSee('Proefresultaat voor print.pdf');
    }

    public function test_result_shows_when_browser_filename_and_posted_file_diverge(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf("OPSLAG\nA-00-03", 'print.pdf'),
            'client_filename' => 'proef.pdf',
        ]);

        $response->assertRedirect();
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertSee('Ontvangen upload')
            ->assertSee('Wissel in de browser/het formulier: gekozen naam is proef.pdf, maar POST originalName is print.pdf.')
            ->assertSee('Proefresultaat voor print.pdf');
    }

    public function test_received_upload_debug_escapes_the_browser_filename(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf("OPSLAG\nA-00-03", 'print.pdf'),
            'client_filename' => '<script>alert(1)</script>',
        ]);

        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $html = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertSee('Ontvangen upload')
            ->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_upload_without_wall_geometry_does_not_invent_an_area(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf(<<<'TXT'
OPSLAG
A-00-03
3500
2000
99 m2
TXT, 'opslag.pdf'),
        ]);

        $this->assertDatabaseCount('calculations', 0);
        $this->assertDatabaseCount('calculation_lines', 0);
        $this->assertSame(0, Calculation::query()->count());
        $this->assertSame(0, CalculationLine::query()->count());

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#/calculaties/zonder-m2/[0-9a-fA-F-]{36}$#', $location);

        $id = basename(parse_url($location, PHP_URL_PATH) ?: '');
        Storage::disk('local')->assertExists(TrialStore::DIRECTORY.'/'.$id.'/drawing.pdf');
        Storage::disk('local')->assertExists(TrialStore::DIRECTORY.'/'.$id.'/result.json');

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertOk()
            ->assertSee('Brontype: PDF met tekstlaag')
            ->assertSee('OPSLAG')
            ->assertSee('A-00-03')
            ->assertSee('Rood – niet berekenbaar')
            ->assertSee('Herkende ruimtes')
            ->assertSee('Rendertijd')
            ->assertSee('OCR-tijd')
            ->assertSee('Totale tijd')
            ->assertSee('Geen betrouwbare lokale maatvoering gekoppeld aan de wanden van deze ruimte.')
            ->assertDontSee('3500 × 2000 → 7,00 m²')
            ->assertDontSee('Berekend: 7,00 m²');
    }

    public function test_upload_recognizes_named_rooms_without_room_numbers(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf(<<<'TXT'
WOONKAMER
KEUKEN
HAL
3600
2200
TXT, 'proef-namen.pdf'),
        ]);

        $this->assertDatabaseCount('calculations', 0);
        $response->assertRedirect();
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertOk()
            ->assertSee('WOONKAMER')
            ->assertSee('KEUKEN')
            ->assertSee('HAL')
            ->assertSee('Herkende ruimtes')
            ->assertSee('3600')
            ->assertSee('2200')
            ->assertDontSee('Geen ruimtes herkend');
    }

    public function test_upload_of_a_dimensioned_drawing_keeps_the_printed_area_as_control(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => new UploadedFile(DimensionedRoomPdf::path(), 'opslag.pdf', 'application/pdf', null, true),
        ]);

        $this->assertDatabaseCount('calculations', 0);
        $response->assertRedirect();
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $html = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertOk()
            ->assertSee('Brontype: PDF met tekstlaag')
            ->assertSee('OPSLAG')
            ->assertSee('A-00-03')
            ->assertSee('99,00 m²')
            ->assertSee('Gekozen horizontale maat: 3500')
            ->assertSee('Gekozen verticale maat: 2000')
            ->assertSee('Confidence:')
            ->assertSee('Afgewezen maten')
            ->assertSee('Maatkoppeling')
            ->assertSee('Pagina-pipeline')
            ->assertSee('Dimension objects')
            ->assertSee('Uitgesloten legenda-getallen')
            ->assertSee('Bestaande m² per ruimte')
            ->assertSee('6975')
            ->getContent();

        $this->assertStringContainsString('7,00 m²', $html);
        $this->assertStringContainsString('3500 × 2000 → 7,00 m²', $html);
        $this->assertStringNotContainsString('Berekend: 99,00 m²', $html);
        $this->assertStringNotContainsString('24,41', $html);
        $this->assertStringNotContainsString('6975 × 3500', $html);
    }

    public function test_image_only_pdf_is_labelled_as_a_scan_and_does_not_write_a_calculation(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => new UploadedFile(ImageOnlyPdf::path("WOONKAMER\nKEUKEN\nHAL\n3600\n2200"), 'proef.pdf', 'application/pdf', null, true),
        ]);

        $this->assertDatabaseCount('calculations', 0);
        $response->assertRedirect();
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $html = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertOk()
            ->assertSee('Brontype: afbeelding/scanned PDF')
            ->assertSee('Herkende ruimtes')
            ->assertSee('OCR-tijd')
            ->assertDontSee('24,41')
            ->getContent();

        if (app(RasterPageReader::class)->isAvailable()) {
            $this->assertStringNotContainsString('Geen ruimtes herkend op de afbeelding.', $html);
            $this->assertStringContainsString('WOONKAMER', $html);
            $this->assertStringContainsString('KEUKEN', $html);
            $this->assertStringContainsString('HAL', $html);
        }
    }

    public function test_image_only_room_drawing_uses_ocr_without_inventing_an_overall_area(): void
    {
        if (! app(RasterPageReader::class)->isAvailable()) {
            $this->markTestSkipped('pdftoppm/tesseract niet beschikbaar voor OCR-test.');
        }

        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => new UploadedFile(ImageOnlyPdf::dimensionedRoomPath(), 'proef.pdf', 'application/pdf', null, true),
        ]);

        $this->assertDatabaseCount('calculations', 0);
        $response->assertRedirect();
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $html = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertOk()
            ->assertSee('Brontype: afbeelding/scanned PDF')
            ->assertSee('Tekstherkenning: tesseract')
            ->assertSee('OPSLAG')
            ->assertSee('A-00-03')
            ->assertSee('Gekozen horizontale maat: 3500')
            ->assertSee('Gekozen verticale maat: 2000')
            ->getContent();

        $this->assertStringContainsString('3500 × 2000 → 7,00 m²', $html);
        $this->assertStringNotContainsString('24,41', $html);
        $this->assertStringNotContainsString('6975 × 3500', $html);

        $payload = json_decode((string) Storage::disk('local')->get(TrialStore::DIRECTORY.'/'.$id.'/result.json'), true);
        $this->assertSame('image', $payload['source_type'] ?? null);
        $this->assertSame('tesseract', $payload['ocr_engine'] ?? null);
        $room = collect($payload['rooms'] ?? [])->firstWhere('room_number', 'A-00-03');
        $this->assertNotNull($room);
        $this->assertSame(7.0, (float) $room['calculated_m2']);
        $this->assertSame('maatketting', $room['method']);
        $this->assertNotEquals(99.0, (float) $room['calculated_m2']);
        Storage::disk('local')->assertExists(TrialStore::DIRECTORY.'/'.$id.'/preview.png');

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertSee('data-area-without-m2-overlay', false)
            ->assertSee('data-ocr-label', false)
            ->assertSee('calc-code-chip', false)
            ->assertSee('data-geometry-debug', false)
            ->assertSee('rechter buitengevel:', false)
            ->assertSee('onderste buitengevel:', false)
            ->assertSee('OCR-positie ruimtenaam:')
            ->assertSee('Linkerwand:')
            ->assertSee('Virtueel gesloten gaten:');

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.preview', $id))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_trial_preview_renders_raw_geometry_layers_and_facade_log(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $preview = sys_get_temp_dir().DIRECTORY_SEPARATOR.'area-without-m2-preview-'.$id.'.png';
        file_put_contents($preview, 'png');

        app(TrialStore::class)->saveResult([
            'id' => $id,
            'original_filename' => 'proef.pdf',
            'absolute_path' => $preview,
            'file_size' => 3,
            'file_hash' => 'abc',
        ], [
            'rooms' => [[
                'room_name' => 'SLAAPKAMER 1',
                'room_number' => '',
                'dimensions_label' => '—',
                'method' => 'niet berekenbaar',
                'calculated_label' => 'Niet berekenbaar',
                'printed_label' => '—',
                'deviation_label' => '—',
                'status' => 'unavailable',
                'status_label' => 'Rood – niet berekenbaar',
                'trace' => 'Niet berekenbaar',
                'overlay' => [
                    'anchor' => ['x' => 78.8, 'y' => 33.3, 'label' => 'SLAAPKAMER 1'],
                    'chip' => [
                        'x' => 62.4, 'y' => 48.1, 'text' => 'S1',
                        'bg' => '#be0032', 'fg' => '#fff',
                    ],
                    'walls' => [[
                        'x1' => 51.4, 'y1' => 22.2, 'x2' => 51.4, 'y2' => 66.7,
                        'side' => 'links', 'caption' => 'SLAAPKAMER 1 · links',
                        'mx' => 51.4, 'my' => 44.5,
                    ]],
                ],
            ], [
                'room_name' => 'WOONKAMER',
                'room_number' => '',
                'dimensions_label' => '—',
                'method' => 'niet berekenbaar',
                'calculated_label' => 'Niet berekenbaar',
                'printed_label' => '—',
                'deviation_label' => '—',
                'status' => 'unavailable',
                'status_label' => 'Rood – niet berekenbaar',
                'trace' => 'Niet berekenbaar',
                'overlay' => null,
            ]],
            'geometry' => [
                'raw_v' => [['x1' => 51.4, 'y1' => 22.2, 'x2' => 51.4, 'y2' => 66.7]],
                'raw_h' => [['x1' => 16.7, 'y1' => 40.7, 'x2' => 66.7, 'y2' => 40.7]],
                'bands' => [['x1' => 51.0, 'y1' => 22.2, 'x2' => 51.8, 'y2' => 66.7]],
                'axes' => [[
                    'x1' => 51.4, 'y1' => 22.2, 'x2' => 51.4, 'y2' => 66.7,
                    'role' => 'interne wand',
                    'caption' => 'interne wand · paar · x=617',
                    'mx' => 51.4, 'my' => 44.5,
                ]],
            ],
            'geometry_debug' => [
                'right' => 'rechter buitengevel: niet gevonden',
                'right_detail' => 'x = 617, y-bereik = 300–700, bron = paar (niet rechts van OCR x=945.3)',
                'bottom' => 'onderste buitengevel: niet gevonden',
                'bottom_detail' => 'y = 534, x-bereik = 200–800, bron = lijn (niet onder OCR y=400)',
                'counts' => 'ruwe V 1 · ruwe H 1 · banden 1 · assen 1',
                'log' => [
                    'Rechter zoekgebied x=945.3..1200',
                    'verticale donkere runs: 2',
                    'kandidaat x=987 y=199–210 breedte=1.5 → afgewezen omdat te kort',
                    'kandidaat x=1064 y=185–745 breedte=4.5 → geaccepteerd (cluster)',
                ],
            ],
            'preview_path' => $preview,
        ]);

        $storedTrial = app(TrialStore::class)->find($id);
        $this->assertNotNull($storedTrial);
        $this->assertSame('SLAAPKAMER 1', $storedTrial['rooms'][0]['room_name'] ?? null);
        $this->assertTrue($storedTrial['has_preview']);
        $this->assertSame(78.8, $storedTrial['rooms'][0]['overlay']['anchor']['x'] ?? null);

        $html = $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', $id))
            ->assertSee('data-geometry-debug', false)
            ->assertSee('rechter buitengevel: niet gevonden')
            ->assertSee('x = 617, y-bereik = 300–700, bron = paar', false)
            ->assertSee('onderste buitengevel: niet gevonden')
            ->assertSee('y = 534, x-bereik = 200–800, bron = lijn', false)
            ->assertSee('Rechter zoekgebied x=945.3..1200')
            ->assertSee('kandidaat x=1064 y=185–745 breedte=4.5 → geaccepteerd (cluster)')
            ->assertSee('data-ocr-label', false)
            ->assertSee('calc-code-chip', false)
            ->assertSee('>S1</span>', false)
            ->assertSee('SLAAPKAMER 1')
            ->assertSee('WOONKAMER')
            ->assertDontSee('data-raw-v', false)
            ->assertDontSee('data-chosen-wall', false)
            ->assertDontSee('interne wand · paar · x=617')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-ocr-label'));
    }

    public function test_vakman_is_forbidden_from_the_trial_preview(): void
    {
        $user = User::factory()->vakman()->create();

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.preview', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_the_trial_preview(): void
    {
        $this->get(route('calculations.area-without-m2.preview', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'))
            ->assertRedirect(route('login'));
    }

    public function test_preview_without_a_saved_image_is_not_found(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.area-without-m2.store'), [
            'drawing' => $this->pdf("OPSLAG\nA-00-03\n3500\n2000", 'opslag.pdf'),
        ]);

        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.preview', $id))
            ->assertNotFound();
    }

    public function test_unknown_trial_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calculations.area-without-m2.show', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'))
            ->assertNotFound();
    }

    private function pdf(string $text, string $name): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }
}
