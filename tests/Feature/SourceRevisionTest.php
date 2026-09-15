<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\SnagPriority;
use App\Enums\SnagStatus;
use App\Models\AreaDrawingMarker;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SnagItem;
use App\Models\User;
use App\Models\Worker;
use App\Services\SourceUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->makeProject('251000077');

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

    private function csvMeetstaat(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('meetstaat.csv', "nummer,naam,verdieping,m2,pvc\n".$rows);
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
