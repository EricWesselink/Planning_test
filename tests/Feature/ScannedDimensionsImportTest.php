<?php

namespace Tests\Feature;

use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\User;
use App\Services\ScannedDimensions\ScannedDimensionsPdfOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ImageOnlyPdf;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class ScannedDimensionsImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_afmetingen_preview(): void
    {
        $this->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ])->assertRedirect(route('login'));
    }

    public function test_uitvoerder_is_forbidden_from_afmetingen_preview(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ])->assertForbidden();

        $this->assertSame(0, Project::query()->count());
    }

    public function test_text_pdf_opens_editable_review_then_imports_165_netto(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ]);

        $preview->assertRedirect();
        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');
        $payload = Cache::get('afmetingen.'.$token);
        $this->assertIsArray($payload);
        $this->assertSame(165.0, (float) ($payload['parsed']['netto_total'] ?? 0));
        $this->assertCount(4, $payload['parsed']['rooms']);
        $this->assertFalse((bool) ($payload['parsed']['used_ocr'] ?? false));
        $this->assertFalse((bool) ($payload['parsed']['recognition_failed'] ?? false));

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('Afmetingen-PDF')
            ->assertSee('NETTO is leidend')
            ->assertSee('Netto m²')
            ->assertSee('Bruto m²')
            ->assertSee('Werksoort')
            ->assertSee('Status')
            ->assertSee('+ Ruimte toevoegen')
            ->assertSee('name="rooms[0][label]"', false)
            ->assertSee('value="Ruimte 1"', false);

        $import = $this->actingAs($user)->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Test klant',
            'name' => 'Klein werk',
            'work_type' => 'PVC',
            'rooms' => $this->exampleRooms(),
        ]);

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $import->assertRedirect(route('projects.show', $project));
        $this->assertSame(4, ProjectArea::query()->where('project_id', $project->id)->count());

        $pvc = $project->workItems()->where('name', 'PVC')->first();
        $this->assertNotNull($pvc);
        $this->assertEqualsWithDelta(
            165.0,
            (float) AreaTask::query()->where('work_item_id', $pvc->id)->where('quantity_source', 'afmetingen')->sum('ordered_quantity'),
            0.01
        );
    }

    public function test_scan_without_text_layer_opens_review_instead_of_blocking(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->imagePdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ]);

        $preview->assertRedirect();
        $this->assertStringContainsString('/projecten/afmetingen/', (string) $preview->headers->get('Location'));

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('Afmetingen-PDF')
            ->assertSee('+ Ruimte toevoegen')
            ->assertDontSee('Geen ruimte-/netto-regels herkend');
    }

    public function test_scan_without_text_layer_uses_ocr_for_165_netto(): void
    {
        if (! app(ScannedDimensionsPdfOcr::class)->isAvailable()) {
            $this->markTestSkipped('pdftoppm/tesseract niet beschikbaar voor OCR-test.');
        }

        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->imagePdf('afmetingen.pdf', $this->exampleText()),
            'plattegrond' => $this->imagePdf('plattegrond.pdf', "Tekening\n1\n2A\n2B\n3\n"),
            'work_type' => 'PVC',
        ]);

        $preview->assertRedirect();
        $location = (string) $preview->headers->get('Location');
        $this->assertStringContainsString('/projecten/afmetingen/', $location);

        $token = basename(parse_url($location, PHP_URL_PATH) ?: '');
        $payload = Cache::get('afmetingen.'.$token);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['parsed']['used_ocr'] ?? false));
        $this->assertTrue((bool) ($payload['parsed']['ocr_attempted'] ?? false));
        $this->assertSame(165.0, (float) ($payload['parsed']['netto_total'] ?? 0));
        $this->assertSame(174.0, (float) ($payload['parsed']['bruto_total'] ?? 0));
        $this->assertSame(6.0, (float) ($payload['parsed']['snijverlies_pct'] ?? 0));

        $byNumber = collect($payload['parsed']['rooms'])->keyBy('room_number');
        $this->assertSame(47.0, (float) $byNumber['1']['quantity']);
        $this->assertSame(56.0, (float) $byNumber['2A']['quantity']);
        $this->assertSame(35.0, (float) $byNumber['2B']['quantity']);
        $this->assertSame(27.0, (float) $byNumber['3']['quantity']);
    }

    public function test_partial_ocr_shows_found_rows_and_controleren_status(): void
    {
        $this->fakeOcr(<<<'TXT'
Ruimte 1
netto 47 m²
Ruimte 2A
Ruimte 2B
netto 35 m²
Ruimte 3
TXT);

        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->imagePdf('afmetingen.pdf', 'scan zonder tekst'),
            'work_type' => 'PVC',
        ]);

        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');
        $payload = Cache::get('afmetingen.'.$token);
        $this->assertIsArray($payload);
        $byNumber = collect($payload['parsed']['rooms'])->keyBy('room_number');
        $this->assertSame('ok', $byNumber['1']['status']);
        $this->assertSame('controleren', $byNumber['2A']['status']);
        $this->assertSame('controleren', $byNumber['3']['status']);

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('Controleren')
            ->assertSee('value="47"', false)
            ->assertSee('+ Ruimte toevoegen');
    }

    public function test_unreadable_ocr_opens_empty_review_for_manual_entry(): void
    {
        $this->fakeOcr('onleesbaar gekrabbel zonder maten');

        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->imagePdf('afmetingen.pdf', '@@@@'),
            'work_type' => 'Linoleum',
        ]);

        $preview->assertRedirect();
        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');
        $payload = Cache::get('afmetingen.'.$token);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['parsed']['recognition_failed'] ?? false));
        $this->assertSame([], $payload['parsed']['rooms']);

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('Afmetingen konden niet betrouwbaar automatisch worden herkend. Vul de ruimtes en netto m² hieronder handmatig in.')
            ->assertSee('+ Ruimte toevoegen');

        $import = $this->actingAs($user)->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Handmatig BV',
            'name' => 'Scanwerk',
            'work_type' => 'Linoleum',
            'rooms' => $this->exampleRooms('Linoleum'),
        ]);

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $import->assertRedirect(route('projects.show', $project));
        $this->assertSame(4, $project->areas()->count());
        $this->assertEqualsWithDelta(
            165.0,
            (float) AreaTask::query()->where('quantity_source', 'afmetingen')->sum('ordered_quantity'),
            0.01
        );
    }

    public function test_plattegrond_does_not_overwrite_netto_from_afmetingen_pdf(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'plattegrond' => $this->pdf('plattegrond.pdf', "Tekening\nRuimte 1 = 999 m2\n1\n2A\n2B\n3\n"),
            'work_type' => 'PVC',
        ]);

        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');
        $payload = Cache::get('afmetingen.'.$token);
        $this->assertIsArray($payload);
        $this->assertSame(165.0, (float) $payload['parsed']['netto_total']);
        $byNumber = collect($payload['parsed']['rooms'])->keyBy('room_number');
        $this->assertSame(47.0, (float) $byNumber['1']['quantity']);
        $this->assertNotSame(999.0, (float) $byNumber['1']['quantity']);
        $this->assertContains('1', $payload['parsed']['drawing_labels']);

        $this->actingAs($user)->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Met tekening',
            'name' => 'Met plattegrond',
            'work_type' => 'PVC',
            'rooms' => $this->exampleRooms(),
        ])->assertRedirect();

        $this->assertEqualsWithDelta(
            165.0,
            (float) AreaTask::query()->where('quantity_source', 'afmetingen')->sum('ordered_quantity'),
            0.01
        );
    }

    public function test_import_rejects_row_without_name_netto_or_work_type(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ]);
        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');

        $this->actingAs($user)->from(route('projects.afmetingen.review', $token))->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Test klant',
            'name' => 'Klein werk',
            'work_type' => 'PVC',
            'rooms' => [
                ['label' => '', 'netto' => '47', 'bruto' => '', 'work_type' => 'PVC', 'status' => 'ok'],
            ],
        ])->assertRedirect(route('projects.afmetingen.review', $token))
            ->assertSessionHasErrors('rooms.0.label');

        $this->actingAs($user)->from(route('projects.afmetingen.review', $token))->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Test klant',
            'name' => 'Klein werk',
            'work_type' => 'PVC',
            'rooms' => [
                ['label' => 'Ruimte 1', 'netto' => '0', 'bruto' => '', 'work_type' => 'PVC', 'status' => 'ok'],
            ],
        ])->assertSessionHasErrors('rooms.0.netto');

        $this->actingAs($user)->from(route('projects.afmetingen.review', $token))->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Test klant',
            'name' => 'Klein werk',
            'work_type' => '',
            'rooms' => [
                ['label' => 'Ruimte 1', 'netto' => '47', 'bruto' => '', 'work_type' => '', 'status' => 'ok'],
            ],
        ])->assertSessionHasErrors('rooms.0.work_type');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_import_escapes_dangerous_room_label_when_validation_fails(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.afmetingen.preview'), [
            'afmetingen' => $this->pdf('afmetingen.pdf', $this->exampleText()),
            'work_type' => 'PVC',
        ]);
        $token = basename(parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH) ?: '');
        $xss = '<script>alert(1)</script>';

        $failed = $this->actingAs($user)->from(route('projects.afmetingen.review', $token))->post(route('projects.afmetingen.import', $token), [
            'customer_name' => 'Test klant',
            'name' => 'Klein werk',
            'work_type' => 'PVC',
            'rooms' => [
                ['label' => $xss, 'netto' => '0', 'bruto' => '', 'work_type' => 'PVC', 'status' => 'ok'],
            ],
        ]);

        $failed->assertRedirect(route('projects.afmetingen.review', $token));

        $this->followRedirects($failed)
            ->assertOk()
            ->assertSee($xss)
            ->assertDontSee($xss, false);
    }

    public function test_dropzone_afmetingen_type_diverts_to_separate_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->pdf('afmetingen-werk.pdf', "Ruimte 1 = 10 m2\nRuimte 2 = 20 m2\n")],
            'types' => ['afmetingen'],
            'work_type' => 'Linoleum',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/projecten/afmetingen/', (string) $response->headers->get('Location'));
    }

    /**
     * @return list<array{label: string, netto: string, bruto: string, work_type: string, status: string}>
     */
    private function exampleRooms(string $workType = 'PVC'): array
    {
        return [
            ['label' => 'Ruimte 1', 'netto' => '47', 'bruto' => '', 'work_type' => $workType, 'status' => 'ok'],
            ['label' => 'Ruimte 2A', 'netto' => '56', 'bruto' => '', 'work_type' => $workType, 'status' => 'ok'],
            ['label' => 'Ruimte 2B', 'netto' => '35', 'bruto' => '', 'work_type' => $workType, 'status' => 'ok'],
            ['label' => 'Ruimte 3', 'netto' => '27', 'bruto' => '', 'work_type' => $workType, 'status' => 'ok'],
        ];
    }

    private function exampleText(): string
    {
        return <<<'TXT'
Afmetingen
Ruimte 1 = 47 m2
Ruimte 2A = 56 m2
Ruimte 2B = 35 m2
Ruimte 3 = 27 m2
Totaal netto = 165 m2
Bruto 174 m2
Snijverlies 6%
TXT;
    }

    private function fakeOcr(string $text): void
    {
        $ocr = $this->mock(ScannedDimensionsPdfOcr::class);
        $ocr->shouldReceive('isAvailable')->andReturn(true);
        $ocr->shouldReceive('extractText')->andReturn([
            'text' => $text,
            'pages' => 1,
            'engine' => 'tesseract',
            'attempted' => true,
            'available' => true,
        ]);
    }

    private function pdf(string $name, string $text): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }

    private function imagePdf(string $name, string $text): UploadedFile
    {
        return new UploadedFile(ImageOnlyPdf::path($text), $name, 'application/pdf', null, true);
    }
}
