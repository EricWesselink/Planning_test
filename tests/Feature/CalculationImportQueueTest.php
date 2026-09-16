<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessCalculationDrawingJob;
use App\Jobs\ProcessCalculationWorkbookJob;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class CalculationImportQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_processing_and_status(): void
    {
        $calculation = $this->makeCalculation();

        $this->get(route('calculations.processing', $calculation))
            ->assertRedirect(route('login'));
        $this->getJson(route('calculations.import-status', $calculation))
            ->assertUnauthorized();
    }

    public function test_vakman_is_forbidden_from_processing(): void
    {
        $user = User::factory()->vakman()->create();
        $calculation = $this->makeCalculation();

        $this->actingAs($user)
            ->get(route('calculations.processing', $calculation))
            ->assertForbidden();
    }

    public function test_sync_import_still_redirects_to_the_review_screen(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'drawings' => [$this->pdf('01.12 Woonkamer 24,5 m2 v01.g', 'bg.pdf')],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $response->assertRedirect(route('calculations.imported', $calculation));
        $this->assertSame(ImportStatus::Ready, $calculation->fresh()->import_status);
        $this->assertSame(1, $calculation->lines()->count());
    }

    public function test_queued_import_stores_files_and_returns_before_parsing(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessCalculationDrawingJob::class, ProcessCalculationWorkbookJob::class]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('calculations.store'), [
            'name' => 'Zeven tekeningen',
            'dated_on' => '2026-03-06',
            'drawings' => [
                $this->pdf('01.12 Woonkamer 24,5 m2 v01.g', 'bg.pdf'),
                $this->pdf('01.13 Hal 12,0 m2 v01.g', 'v1.pdf'),
            ],
            'workbooks' => [$this->csv('staat.csv', "Ruimte;Vloer\n01.12;v01.g")],
        ]);

        $calculation = Calculation::query()->first();
        $this->assertNotNull($calculation);
        $response->assertRedirect(route('calculations.processing', $calculation));
        $this->assertSame(ImportStatus::Processing, $calculation->fresh()->import_status);
        $this->assertSame(0, $calculation->lines()->count());
        Queue::assertPushed(ProcessCalculationDrawingJob::class, 2);
        Queue::assertNotPushed(ProcessCalculationWorkbookJob::class);

        $this->actingAs($user)
            ->getJson(route('calculations.import-status', $calculation))
            ->assertOk()
            ->assertJsonPath('finished', false)
            ->assertJsonPath('files.0.status', 'processing')
            ->assertJsonPath('files.1.status', 'processing')
            ->assertJsonPath('files.2.status', 'pending');

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertRedirect(route('calculations.processing', $calculation));
    }

    public function test_dispatch_import_does_not_queue_the_drawings_twice(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessCalculationDrawingJob::class, ProcessCalculationWorkbookJob::class]);
        $user = User::factory()->create();
        $store = $this->app->make(CalculationStoreService::class);

        $calculation = $store->create([
            'name' => 'Een keer',
            'dated_on' => '2026-03-06',
        ], [$this->pdf('01.12 Hal 10 m2 v01.g', 'bg.pdf')], $user);

        $store->dispatchImport($calculation->fresh());

        Queue::assertPushed(ProcessCalculationDrawingJob::class, 1);
    }

    public function test_workbooks_are_queued_after_drawings_are_settled(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessCalculationDrawingJob::class, ProcessCalculationWorkbookJob::class]);
        $user = User::factory()->create();
        $store = $this->app->make(CalculationStoreService::class);

        $calculation = $store->create([
            'name' => 'Eerst tekeningen',
            'dated_on' => '2026-03-06',
        ], [$this->pdf('01.12 Hal 10 m2 v01.g', 'bg.pdf')], $user, [$this->csv('staat.csv', "Ruimte;Vloer\n01.12;v01.g")]);

        Queue::assertPushed(ProcessCalculationDrawingJob::class, 1);
        Queue::assertNotPushed(ProcessCalculationWorkbookJob::class);

        $calculation->drawings()->update([
            'import_status' => ImportStatus::Ready->value,
        ]);
        $store->continueImport($calculation->id);

        Queue::assertPushed(ProcessCalculationWorkbookJob::class, 1);
    }

    public function test_one_failed_drawing_does_not_block_the_other(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Deels mislukt',
            'dated_on' => '2026-03-06',
            'status' => 'concept',
            'import_status' => ImportStatus::Processing,
            'created_by' => $user->id,
            'warnings' => [],
        ]);

        $okPath = 'calculations/'.$calculation->id.'/ok.pdf';
        Storage::disk('local')->put($okPath, SimplePdf::bytes("01.12 Woonkamer 24,5 m2 v01.g\nv01.g = Marmoleum"));
        $ok = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'ok.pdf',
            'file_path' => $okPath,
            'mime_type' => 'application/pdf',
            'file_size' => 120,
            'import_status' => ImportStatus::Processing,
        ]);
        $bad = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'kapot.pdf',
            'file_path' => 'calculations/'.$calculation->id.'/missing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'import_status' => ImportStatus::Processing,
        ]);

        $store = $this->app->make(CalculationStoreService::class);
        $store->processDrawing($ok);
        $store->processDrawing($bad);
        $store->finalizeImport($calculation->fresh());

        $this->assertSame(ImportStatus::Ready, $ok->fresh()->import_status);
        $this->assertSame(ImportStatus::Failed, $bad->fresh()->import_status);
        $this->assertSame(ImportStatus::Ready, $calculation->fresh()->import_status);
        $this->assertGreaterThan(0, $calculation->lines()->count());
        $this->assertNotEmpty($bad->fresh()->import_error);
        $this->assertTrue(collect($calculation->fresh()->warnings)->contains(
            fn (string $warning): bool => str_contains($warning, 'kapot.pdf'),
        ));
    }

    public function test_processing_page_redirects_when_import_is_finished(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->get(route('calculations.processing', $calculation))
            ->assertRedirect(route('calculations.imported', $calculation));
    }

    public function test_drawing_job_records_a_failure_without_throwing_into_the_batch(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Job fout',
            'dated_on' => '2026-03-06',
            'status' => 'concept',
            'import_status' => ImportStatus::Processing,
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'weg.pdf',
            'file_path' => 'calculations/missing.pdf',
            'mime_type' => 'application/pdf',
            'import_status' => ImportStatus::Processing,
        ]);

        (new ProcessCalculationDrawingJob($drawing->id))->handle($this->app->make(CalculationStoreService::class));

        $this->assertSame(ImportStatus::Failed, $drawing->fresh()->import_status);
    }

    private function makeCalculation(?User $user = null): Calculation
    {
        $user ??= User::factory()->create();

        return Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'status' => 'concept',
            'import_status' => ImportStatus::Ready,
            'created_by' => $user->id,
            'warnings' => [],
        ]);
    }

    private function pdf(string $text, string $name): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }

    private function csv(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-csv-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
