<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class VakmanDrawingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_pdf_opens_the_viewer_and_can_be_saved(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        [$worker, $project] = $this->schedule('Feringa building Groningen');
        $drawing = $this->storePdf($project, 'Begane grond A.pdf');
        $user = User::factory()->vakman($worker->id)->create();
        $show = route('vakman.drawings.show', [
            'project' => $project,
            'document' => $drawing,
            'day' => '2026-09-10',
        ]);
        $back = route('vakman.planning', [
            'view' => 'week',
            'week' => '2026-09-07',
            'day' => '2026-09-10',
        ]);

        $planning = $this->actingAs($user)->get(route('vakman.planning'));
        $planning->assertOk()
            ->assertSee('href="'.$show.'"', false)
            ->assertSee('>Tekening</a>', false)
            ->assertDontSee('/projecten/'.$project->id, false);

        $page = $this->actingAs($user)->get($show);
        $page->assertOk()
            ->assertSee('← Terug naar Mijn planning')
            ->assertSee($back)
            ->assertSee('Feringa building Groningen')
            ->assertSee('Tekening: Begane grond A')
            ->assertSee('PDF bekijken')
            ->assertSee('PDF opslaan')
            ->assertSee(route('vakman.drawings.download', [$project, $drawing]), false)
            ->assertSee('data-vakman-drawing="'.route('vakman.drawings.file', [$project, $drawing]).'"', false)
            ->assertDontSee('id="project-board"', false)
            ->assertDontSee('id="draw-page"', false)
            ->assertDontSee(route('projects.index'), false);

        $file = $this->actingAs($user)->get(route('vakman.drawings.file', [$project, $drawing]));
        $file->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $file->headers->get('content-disposition'));
        $this->assertStringContainsString('Begane grond A.pdf', (string) $file->headers->get('content-disposition'));

        $download = $this->actingAs($user)->get(route('vakman.drawings.download', [$project, $drawing]));
        $download->assertDownload('Begane grond A.pdf');

        $this->actingAs($user)
            ->get(route('vakman.drawings.index', ['project' => $project, 'day' => '2026-09-10']))
            ->assertRedirect($show);

        $returned = $this->actingAs($user)->get($back);
        $returned->assertOk();
        $this->assertMatchesRegularExpression(
            '/class="vakman-week-day is-today is-active"\s+id="vakman-day-2026-09-10"/',
            $returned->getContent(),
        );
    }

    public function test_multiple_pdfs_open_a_list_before_the_viewer(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        [$worker, $project] = $this->schedule('Feringa building Groningen');
        $ground = $this->storePdf($project, 'Begane grond A.pdf');
        $first = $this->storePdf($project, 'Eerste verdieping.pdf');
        $second = $this->storePdf($project, 'Tweede verdieping.pdf');
        $user = User::factory()->vakman($worker->id)->create();
        $index = route('vakman.drawings.index', ['project' => $project, 'day' => '2026-09-10']);

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('href="'.$index.'"', false)
            ->assertDontSee(route('vakman.drawings.show', [$project, $ground]), false)
            ->assertDontSee('/projecten/'.$project->id, false);

        $list = $this->actingAs($user)->get($index);
        $list->assertOk()
            ->assertSee('Tekeningen')
            ->assertSee('Begane grond A')
            ->assertSee('Eerste verdieping')
            ->assertSee('Tweede verdieping')
            ->assertSee('Bekijken')
            ->assertSee('PDF opslaan')
            ->assertSee(route('vakman.drawings.show', ['project' => $project, 'document' => $first, 'day' => '2026-09-10']), false)
            ->assertSee(route('vakman.drawings.download', [$project, $second]), false)
            ->assertSee(route('vakman.planning', ['view' => 'week', 'week' => '2026-09-07', 'day' => '2026-09-10']))
            ->assertDontSee('id="project-board"', false)
            ->assertDontSee('/projecten/'.$project->id, false);

        $this->actingAs($user)
            ->get(route('vakman.drawings.show', ['project' => $project, 'document' => $ground, 'day' => '2026-09-10']))
            ->assertOk()
            ->assertSee('Tekening: Begane grond A')
            ->assertSee('Alle tekeningen');
    }

    public function test_job_without_a_drawing_shows_an_unavailable_button(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$worker] = $this->schedule('Laakse Tuinen');
        $user = User::factory()->vakman($worker->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Geen tekening')
            ->assertSee('Geen tekening beschikbaar')
            ->assertDontSee('>Tekening</a>', false)
            ->assertDontSee('Tekeningen');
    }

    public function test_vakman_cannot_open_a_drawing_for_an_unassigned_project(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        [$worker, $project] = $this->schedule('Laakse Tuinen');
        $other = $this->project('Kindcentrum Veldhoeve');
        $ownDrawing = $this->storePdf($project, 'Begane grond A.pdf');
        $foreign = $this->storePdf($other, 'Verboden.pdf');
        $calculatie = $this->storePdf($project, 'calculatie.pdf', 'calculatie');
        $user = User::factory()->vakman($worker->id)->create();
        $stranger = User::factory()->vakman($this->worker('Andere ploeg')->id)->create();

        $this->actingAs($stranger)->get(route('vakman.drawings.index', $other))->assertForbidden();
        $this->actingAs($stranger)->get(route('vakman.drawings.show', [$other, $foreign]))->assertForbidden();
        $this->actingAs($stranger)->get(route('vakman.drawings.file', [$other, $foreign]))->assertForbidden();
        $this->actingAs($stranger)->get(route('vakman.drawings.download', [$other, $foreign]))->assertForbidden();

        $this->actingAs($user)->get(route('vakman.drawings.show', [$project, $foreign]))->assertNotFound();
        $this->actingAs($user)->get(route('vakman.drawings.show', [$project, $calculatie]))->assertNotFound();
        $this->actingAs($user)->get(route('vakman.drawings.file', [$project, $ownDrawing]))->assertOk();

        Storage::disk('local')->delete($ownDrawing->file_path);
        $this->actingAs($user)->get(route('vakman.drawings.file', [$project, $ownDrawing]))->assertNotFound();
    }

    public function test_guest_is_sent_to_the_vakman_login(): void
    {
        $project = $this->project('Laakse Tuinen');
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plan.pdf',
            'file_path' => 'projects/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'parse_status' => 'done',
        ]);

        $this->get(route('vakman.drawings.show', [$project, $drawing]))
            ->assertRedirect(route('vakman.login'));
    }

    public function test_admin_project_board_stays_available(): void
    {
        $project = $this->project('Laakse Tuinen');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="project-board"', false)
            ->assertSee('id="draw-page"', false);

        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plan.pdf',
            'file_path' => 'projects/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'parse_status' => 'done',
        ]);

        $this->actingAs($admin)
            ->get(route('vakman.drawings.show', [$project, $drawing]))
            ->assertForbidden();
    }

    public function test_winkel_card_opens_the_pdf_instead_of_the_project_board(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        [$worker, $project] = $this->schedule('Harm Wesselink - Zwolle', ProjectKind::Winkel);
        $drawing = $this->storePdf($project, 'plattegrond.pdf');
        $user = User::factory()->vakman($worker->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('href="'.route('vakman.drawings.show', [
                'project' => $project,
                'document' => $drawing,
                'day' => '2026-09-10',
            ]).'"', false)
            ->assertDontSee('/projecten/'.$project->id, false);
    }

    /**
     * @return array{0: Worker, 1: Project}
     */
    private function schedule(string $name, ProjectKind $kind = ProjectKind::Project): array
    {
        $worker = $this->worker('Nick Seine');
        $project = $this->project($name, $kind);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        return [$worker, $project];
    }

    private function worker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
    }

    private function project(string $name, ProjectKind $kind = ProjectKind::Project): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Groningen',
            'status' => 'gepland',
            'kind' => $kind,
        ]);
    }

    private function storePdf(Project $project, string $filename, string $type = 'plattegrond'): ProjectDocument
    {
        $path = 'projects/'.$project->id.'/'.$type.'/'.$filename;
        Storage::disk('local')->put($path, SimplePdf::bytes($filename));

        return ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => $type,
            'original_filename' => $filename,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'parse_status' => 'done',
        ]);
    }
}
