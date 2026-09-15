<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\SnagPriority;
use App\Enums\SnagStatus;
use App\Enums\VoucherType;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Models\AreaDrawingMarker;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\SnagItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Services\SourceUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class SourceRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_source_upload(): void
    {
        $project = $this->makeProject('251000001');

        $this->post(route('projects.sources.store', $project), [
            'files' => [UploadedFile::fake()->create('meetstaat.csv', 10, 'text/csv')],
        ])->assertRedirect(route('login'));
    }

    public function test_reuploading_meetstaat_shows_diff_and_keeps_progress_after_confirm(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $site = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Griftland College',
            'customer_name' => 'Nicon vloeren',
            'city' => 'Soest',
            'meetstaat' => $this->csvMeetstaat("01,Apotheek,Begane grond,120,120\n02,Wachtruimte,Begane grond,40,40\n"),
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Griftland College')->first();
        $this->assertNotNull($project);
        $area = $project->areas()->where('name', 'Apotheek')->first();
        $this->assertNotNull($area);
        $task = $area->tasks()->first();
        $this->assertNotNull($task);

        $this->actingAs($site)->post(route('projects.areas.tick', [$project, $area]), [
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'phase' => 'vloer',
        ])->assertRedirect();
        $this->assertSame(AreaStatus::Gereed, $task->fresh()->status);

        $response = $this->actingAs($user)->post(route('projects.meetstaat.store', $project), [
            'meetstaat' => $this->csvMeetstaat("01,Apotheek,Begane grond,130,130\n03,Kantine,Begane grond,25,25\n"),
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(SourceUpdateService::CONFIRM_MESSAGE)
            ->assertSee('Gewijzigd')
            ->assertSee('Nieuw')
            ->assertSee('Vervallen')
            ->assertSee('Kantine')
            ->assertSee('Wachtruimte');

        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->actingAs($user)
            ->post(route('projects.sources.confirm', $token))
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame(1, Project::query()->count());
        $this->assertSame(2, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->where('is_current', true)->count());

        $apotheek = $project->areas()->where('name', 'Apotheek')->first();
        $this->assertNotNull($apotheek);
        $this->assertEqualsWithDelta(130, (float) $apotheek->square_meters, 0.01);
        $this->assertSame(AreaStatus::Gereed, $apotheek->tasks()->first()->status);
        $this->assertDatabaseHas('work_progress_entries', [
            'project_id' => $project->id,
            'project_area_id' => $apotheek->id,
            'worker_id' => $worker->id,
        ]);
        $this->assertNotNull($project->areas()->where('name', 'Kantine')->first());
        $this->assertNotNull($project->areas()->where('name', 'Wachtruimte')->first());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Bronbestanden')
            ->assertSee('Meetstaat')
            ->assertSee('versie 2');
    }

    public function test_upload_with_existing_work_number_opens_update_review_instead_of_a_new_project(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = $this->makeProject('251000077');
        $project->documents()->create([
            'document_type' => 'meetstaat',
            'revision' => 1,
            'is_current' => true,
            'original_filename' => 'Meetstaat-oud.pdf',
            'file_path' => 'projects/'.$project->id.'/meetstaat/oud.pdf',
            'parse_status' => 'ok',
        ]);

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->meetstaatPdf('251000077')],
            'types' => ['meetstaat'],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('bronbestanden', (string) $response->headers->get('Location'));
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(SourceUpdateService::CONFIRM_MESSAGE)
            ->assertSee('251000077');

        $this->assertSame(1, Project::query()->count());
    }

    public function test_new_plattegrond_is_stored_as_a_revision_and_marks_links_for_review(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Tekeningwerk',
            'customer_name' => 'Nicon vloeren',
            'plattegrond' => UploadedFile::fake()->image('plattegrond-v1.jpg', 80, 60),
            'meetstaat' => $this->csvMeetstaat("01,Apotheek,Begane grond,120,120\n"),
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Tekeningwerk')->first();
        $this->assertNotNull($project);
        $first = $project->plattegrond();
        $this->assertNotNull($first);
        $area = $project->areas()->first();
        $this->assertNotNull($area);
        AreaDrawingMarker::query()->create([
            'project_area_id' => $area->id,
            'project_document_id' => $first->id,
            'page' => 1,
            'x' => 0.2,
            'y' => 0.3,
            'confidence' => 1,
            'source' => 'auto',
        ]);
        SnagItem::query()->create([
            'project_id' => $project->id,
            'project_area_id' => $area->id,
            'document_id' => $first->id,
            'drawing_page' => 1,
            'x' => 0.4,
            'y' => 0.5,
            'number' => 1,
            'description' => 'Randbeschadiging',
            'priority' => SnagPriority::Normal,
            'status' => SnagStatus::Open,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('projects.plattegrond.store', $project), [
            'plattegrond' => UploadedFile::fake()->image('plattegrond-v2.jpg', 90, 70),
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(SourceUpdateService::CONFIRM_MESSAGE)
            ->assertSee('Nieuwe revisie');

        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->actingAs($user)
            ->post(route('projects.sources.confirm', $token))
            ->assertRedirect(route('projects.show', $project));

        $project->refresh()->load('documents');
        $this->assertSame(2, $project->documents()->where('document_type', 'plattegrond')->count());
        $current = $project->plattegrond();
        $this->assertNotNull($current);
        $this->assertSame(2, $current->revision);
        $this->assertTrue((bool) $current->is_current);
        $this->assertSame(1, $first->fresh()->revision);
        $this->assertFalse((bool) $first->fresh()->is_current);
        $this->assertSame('review', SnagItem::query()->where('project_id', $project->id)->value('link_status'));
        $this->assertSame(0.2, (float) $area->markers()->where('project_document_id', $first->id)->value('x'));

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('koppeling controleren')
            ->assertSee('revisie 2');
    }

    public function test_sequential_source_revisions_recalculate_totals_and_keep_operational_data(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $site = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Griftland College',
            'customer_name' => 'Nicon vloeren',
            'city' => 'Soest',
            'project_number' => '251000077',
            'meetstaat' => $this->csvMeetstaat("01,Hal,Begane grond,60,60\n02,Kantoor,Begane grond,40,40\n"),
            'plattegrond' => UploadedFile::fake()->image('plattegrond-v1.jpg', 80, 60),
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '251000077')->first();
        $this->assertNotNull($project);
        $hal = $project->areas()->where('name', 'Hal')->first();
        $this->assertNotNull($hal);
        $task = $hal->tasks()->first();
        $this->assertNotNull($task);
        $flooring = $this->flooringWorkItem($project);
        $this->assertEqualsWithDelta(100, (float) $flooring->ordered_quantity, 0.01);

        $this->actingAs($site)->post(route('projects.areas.tick', [$project, $hal]), [
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'phase' => 'vloer',
        ])->assertRedirect();
        $this->assertSame(AreaStatus::Gereed, $task->fresh()->status);

        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $flooring->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        $plannedHours = (float) $assignment->fresh()->planned_hours;
        $this->assertGreaterThan(0, $plannedHours);

        $ticket = WorkTicket::query()->create([
            'number' => 'OB-2026-0077',
            'kind' => WorkTicketKind::Opdrachtbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'created_by' => $user->id,
            'billing_method' => WorkTicketBilling::Hourly,
            'hourly_rate' => 50,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
        $voucher = Voucher::query()->create([
            'number' => 'BON-2026-0077',
            'type' => VoucherType::Facturatie,
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'issued_on' => '2026-09-03',
            'total_amount' => 165,
        ]);

        $drawing = $project->plattegrond();
        $this->assertNotNull($drawing);
        AreaDrawingMarker::query()->create([
            'project_area_id' => $hal->id,
            'project_document_id' => $drawing->id,
            'page' => 1,
            'x' => 0.2,
            'y' => 0.3,
            'confidence' => 1,
            'source' => 'auto',
        ]);
        SnagItem::query()->create([
            'project_id' => $project->id,
            'project_area_id' => $hal->id,
            'document_id' => $drawing->id,
            'drawing_page' => 1,
            'x' => 0.4,
            'y' => 0.5,
            'number' => 1,
            'description' => 'Randbeschadiging',
            'priority' => SnagPriority::Normal,
            'status' => SnagStatus::Open,
            'created_by' => $user->id,
        ]);

        $meetstaatRows = "01,Hal,Begane grond,65,65\n02,Kantoor,Begane grond,40,40\n03,Kantine,Begane grond,25,25\n";
        $response = $this->actingAs($user)->post(route('projects.sources.store', $project), [
            'files' => [$this->csvMeetstaat($meetstaatRows)],
            'types' => ['meetstaat'],
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(SourceUpdateService::CONFIRM_MESSAGE)
            ->assertSee('Gewijzigd')
            ->assertSee('Nieuw')
            ->assertSee('Kantine');
        $this->confirmToken($user, $project, $response);

        $project->refresh()->load(['areas.tasks.workItem', 'workItems.progressEntries', 'documents']);
        $hal = $project->areas()->where('name', 'Hal')->first();
        $this->assertNotNull($hal);
        $this->assertEqualsWithDelta(65, (float) $hal->square_meters, 0.01);
        $this->assertSame(AreaStatus::Gereed, $hal->tasks()->first()->status);
        $this->assertNotNull($project->areas()->where('name', 'Kantoor')->first());
        $this->assertNotNull($project->areas()->where('name', 'Kantine')->first());
        $flooring = $this->flooringWorkItem($project)->load('progressEntries');
        $this->assertEqualsWithDelta(130, (float) $flooring->ordered_quantity, 0.01);
        $this->assertEqualsWithDelta(60, $flooring->completedQuantity(), 0.01);
        $this->assertEqualsWithDelta($plannedHours, (float) $assignment->fresh()->planned_hours, 0.01);
        $this->assertModelExists($ticket);
        $this->assertModelExists($voucher);
        $this->assertSame(1, Project::query()->count());

        $this->confirmSource($user, $project, $this->materialenstaatPdf('251000077', '90,00'), 'materialenstaat');
        $this->confirmSource($user, $project, $this->materialenstaatPdf('251000077', '105,00'), 'materialenstaat');
        $project->refresh()->load('documents');
        $materials = $project->documents()->where('document_type', 'materialenstaat')->orderBy('revision')->get();
        $this->assertSame(2, $materials->count());
        $this->assertSame(1, $materials->where('is_current', true)->count());
        $currentMaterials = $materials->firstWhere('is_current', true);
        $this->assertSame(2, (int) $currentMaterials?->revision);
        $storedNetto = collect($currentMaterials?->parsed_json['works'] ?? [])
            ->map(function (mixed $work): ?float {
                if (! is_array($work)) {
                    return null;
                }
                foreach (['declared_total', 'material_list_netto', 'netto'] as $key) {
                    if (array_key_exists($key, $work) && $work[$key] !== null) {
                        return (float) $work[$key];
                    }
                }

                return null;
            })
            ->filter(fn (?float $value): bool => $value !== null)
            ->last();
        $this->assertNotNull($storedNetto);
        $this->assertEqualsWithDelta(105, $storedNetto, 0.01);
        $this->assertEqualsWithDelta(130, (float) $this->flooringWorkItem($project)->fresh()->ordered_quantity, 0.01);
        $this->assertSame(1, Project::query()->where('project_number', '251000077')->count());

        $this->confirmSource($user, $project, $this->csvCalculation(8));
        $this->assertEqualsWithDelta(8, (float) $this->flooringWorkItem($project)->fresh()->begrote_uren, 0.01);
        $excelLineCount = $project->calculationLines()->count();
        $this->assertGreaterThan(0, $excelLineCount);
        $this->confirmSource($user, $project, $this->csvCalculation(12));
        $flooring = $this->flooringWorkItem($project)->fresh(['progressEntries']);
        $this->assertEqualsWithDelta(12, (float) $flooring->begrote_uren, 0.01);
        $this->assertEqualsWithDelta(130, (float) $flooring->ordered_quantity, 0.01);
        $this->assertEqualsWithDelta(60, $flooring->completedQuantity(), 0.01);
        $this->assertEqualsWithDelta($plannedHours, (float) $assignment->fresh()->planned_hours, 0.01);
        $this->assertSame(2, $project->documents()->where('document_type', 'calculatie')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'calculatie')->where('is_current', true)->count());
        $this->assertSame($excelLineCount, $project->fresh()->calculationLines()->count());

        $response = $this->actingAs($user)->post(route('projects.sources.store', $project), [
            'files' => [UploadedFile::fake()->image('plattegrond-v2.jpg', 90, 70)],
            'types' => ['plattegrond'],
        ]);
        $response->assertRedirect();
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(SourceUpdateService::CONFIRM_MESSAGE)
            ->assertSee('Nieuwe revisie');
        $this->confirmToken($user, $project, $response);

        $project->refresh()->load('documents');
        $this->assertSame(2, $project->documents()->where('document_type', 'plattegrond')->count());
        $currentDrawing = $project->plattegrond();
        $this->assertNotNull($currentDrawing);
        $this->assertSame(2, $currentDrawing->revision);
        $this->assertTrue((bool) $currentDrawing->is_current);
        $this->assertSame(1, (int) $drawing->fresh()->revision);
        $this->assertFalse((bool) $drawing->fresh()->is_current);
        $this->assertSame('review', SnagItem::query()->where('project_id', $project->id)->value('link_status'));
        $this->assertSame(0.2, (float) $hal->markers()->where('project_document_id', $drawing->id)->value('x'));

        $areaCount = $project->areas()->count();
        $this->confirmSource($user, $project, $this->csvMeetstaat($meetstaatRows), 'meetstaat');
        $this->assertSame($areaCount, $project->areas()->count());
        $this->assertSame(1, $project->areas()->where('name', 'Hal')->count());
        $this->assertEqualsWithDelta(65, (float) $project->areas()->where('name', 'Hal')->value('square_meters'), 0.01);
        $this->assertSame(3, $project->documents()->where('document_type', 'meetstaat')->count());

        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->meetstaatPdf('251000077')],
            'types' => ['meetstaat'],
        ]);
        $preview->assertRedirect();
        $this->assertStringContainsString('bronbestanden', (string) $preview->headers->get('Location'));
        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, Project::query()->where('project_number', '251000077')->count());
        $this->assertModelExists($ticket->fresh());
        $this->assertModelExists($voucher->fresh());
        $this->assertSame(AreaStatus::Gereed, $hal->tasks()->first()->fresh()->status);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Bronbestanden')
            ->assertSee('Meetstaat')
            ->assertSee('Materialenstaat')
            ->assertSee('Excel calculatie')
            ->assertSee('Plattegrond')
            ->assertSee('koppeling controleren')
            ->assertSee('versie 3')
            ->assertSee('revisie 2');
    }

    public function test_failed_source_import_leaves_the_previous_project_state_intact(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Griftland College',
            'customer_name' => 'Nicon vloeren',
            'project_number' => '251000077',
            'meetstaat' => $this->csvMeetstaat("01,Hal,Begane grond,60,60\n"),
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '251000077')->first();
        $this->assertNotNull($project);
        $this->assertEqualsWithDelta(60, (float) $project->areas()->where('name', 'Hal')->value('square_meters'), 0.01);
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->count());

        $this->actingAs($user)
            ->post(route('projects.sources.confirm', 'missing-token'))
            ->assertNotFound();
        $this->assertEqualsWithDelta(60, (float) $project->fresh()->areas()->where('name', 'Hal')->value('square_meters'), 0.01);

        $response = $this->actingAs($user)->post(route('projects.sources.store', $project), [
            'files' => [$this->csvMeetstaat("01,Hal,Begane grond,65,65\n03,Kantine,Begane grond,25,25\n")],
            'types' => ['meetstaat'],
        ]);
        $response->assertRedirect();
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $fail = false;
        ProjectArea::creating(function (ProjectArea $area) use (&$fail): void {
            if ($fail && $area->name === 'Kantine') {
                throw new \RuntimeException('simulated import failure');
            }
        });
        $fail = true;
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post(route('projects.sources.confirm', $token));
            $this->fail('De import had moeten mislukken.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated import failure', $exception->getMessage());
        } finally {
            $fail = false;
        }

        $project->refresh();
        $this->assertEqualsWithDelta(60, (float) $project->areas()->where('name', 'Hal')->value('square_meters'), 0.01);
        $this->assertNull($project->areas()->where('name', 'Kantine')->first());
        $this->assertSame(1, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(1, Project::query()->count());
    }

    private function makeProject(string $number): Project
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => 'Griftland College',
            'status' => 'gepland',
        ]);
    }

    private function confirmSource(User $user, Project $project, UploadedFile $file, ?string $type = null): void
    {
        $payload = ['files' => [$file]];
        if ($type !== null) {
            $payload['types'] = [$type];
        }
        $response = $this->actingAs($user)->post(route('projects.sources.store', $project), $payload);
        $response->assertRedirect();
        $this->confirmToken($user, $project, $response);
    }

    private function confirmToken(User $user, Project $project, TestResponse $response): void
    {
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->actingAs($user)
            ->post(route('projects.sources.confirm', $token))
            ->assertRedirect(route('projects.show', $project));
    }

    private function flooringWorkItem(Project $project): WorkItem
    {
        $item = $project->fresh('workItems')->workItems->first(
            fn (WorkItem $item): bool => strcasecmp((string) $item->name, 'pvc') === 0
        );
        $this->assertNotNull($item);

        return $item;
    }

    private function csvMeetstaat(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('meetstaat.csv', "nummer,naam,verdieping,m2,pvc\n".$rows);
    }

    private function csvCalculation(int $hours): UploadedFile
    {
        $cost = $hours * 48;

        return UploadedFile::fake()->createWithContent('calculatie.csv', implode("\n", [
            'KM;Groep;M/U;Productie Eenheid Omschrijving;Artikel Omschrijving;Aantal;EH;Kostprijs;Kostprijs Tot.',
            'L;100;U;pvc;pvc;'.$hours.';uur;48;'.$cost,
            'M;100;M;pvc;pvc;130;m2;9;1170',
        ]));
    }

    private function materialenstaatPdf(string $workNumber, string $netto): UploadedFile
    {
        return new UploadedFile(
            SimplePdf::path(<<<TXT
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : Griftland College
Werknummer    : {$workNumber}
Datum         : 15/09/2026
PVC
Netto : {$netto} m²
TXT),
            'Materialenstaat.pdf',
            'application/pdf',
            null,
            true
        );
    }

    private function meetstaatPdf(string $workNumber): UploadedFile
    {
        return new UploadedFile(
            SimplePdf::path(<<<TXT
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : Griftland College
Werknr        : {$workNumber}
Bouwlaag: begane grond
PVC
0.01 Apotheek 120,00 m²
Totaal 120,00 m²
Netto : 120,00 m²
TXT),
            'Meetstaat.pdf',
            'application/pdf',
            null,
            true
        );
    }
}
