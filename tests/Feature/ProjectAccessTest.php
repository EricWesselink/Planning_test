<?php

namespace Tests\Feature;

use App\Enums\SnagStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\SnagItem;
use App\Models\SnagPhoto;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_all_projects_sees_every_project(): void
    {
        $user = User::factory()->create();
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Project A')
            ->assertSee('Project B');

        $this->actingAs($user)->get(route('projects.show', $projectA))->assertOk();
        $this->actingAs($user)->get(route('projects.show', $projectB))->assertOk();
    }

    public function test_assigned_user_does_not_see_other_projects_in_overviews(): void
    {
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($projectA);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Project A')
            ->assertDontSee('Project B');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Project B');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertDontSee('Project B');

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertDontSee('Project B');
    }

    public function test_assigned_user_gets_403_for_direct_access_to_another_project(): void
    {
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');
        $user = User::factory()->limitedAccess()->uitvoerder()->create();
        $user->projects()->attach($projectA);

        $floor = ProjectFloor::query()->create([
            'project_id' => $projectB->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $projectB->id,
            'project_floor_id' => $floor->id,
            'area_number' => '01',
            'name' => 'Showroom',
            'square_meters' => 10,
            'sort_order' => 1,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $projectB->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $snag = SnagItem::query()->create([
            'project_id' => $projectB->id,
            'drawing_page' => 1,
            'x' => 0.2,
            'y' => 0.3,
            'number' => 1,
            'description' => 'Herstel nodig',
            'assigned_worker_id' => $worker->id,
            'status' => SnagStatus::Open,
        ]);
        $photo = SnagPhoto::query()->create([
            'snag_item_id' => $snag->id,
            'file_path' => 'snags/hidden.jpg',
            'original_filename' => 'hidden.jpg',
            'photo_type' => 'issue',
        ]);
        $document = ProjectDocument::query()->create([
            'project_id' => $projectB->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'tekening.pdf',
            'file_path' => 'projects/hidden.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)->get(route('projects.show', $projectB))->assertForbidden();
        $this->actingAs($user)->get(route('projects.snags.index', $projectB))->assertForbidden();
        $this->actingAs($user)->get(route('projects.snags.show', [$projectB, $snag]))->assertForbidden();
        $this->actingAs($user)->get(route('projects.snags.photo', [$projectB, $snag, $photo]))->assertForbidden();
        $this->actingAs($user)->get(route('projects.areas.show', [$projectB, $area]))->assertForbidden();
        $this->actingAs($user)->get(route('projects.documents.show', [$projectB, $document]))->assertForbidden();
        $this->actingAs($user)->get(route('production.index', ['project_id' => $projectB->id]))->assertForbidden();
        $this->actingAs($user)->get(route('planning', ['project_id' => $projectB->id]))->assertForbidden();

        $this->actingAs($user)->post(route('progress.store'), [
            'date' => '2026-09-02',
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'completed_quantity' => 1,
        ])->assertForbidden();
    }

    public function test_document_from_another_project_is_not_returned(): void
    {
        Storage::fake('local');
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');
        $user = User::factory()->limitedAccess()->uitvoerder()->create();
        $user->projects()->attach($projectA);
        Storage::disk('local')->put('projects/a.pdf', 'tekening-van-a');
        Storage::disk('local')->put('projects/b.pdf', 'tekening-van-b');
        $documentA = $this->makeDocument($projectA, 'projects/a.pdf', 'tekening-a.pdf');
        $documentB = $this->makeDocument($projectB, 'projects/b.pdf', 'tekening-b.pdf');

        $this->actingAs($user)
            ->get(route('projects.documents.show', [$projectA, $documentB]))
            ->assertNotFound();

        $opened = $this->actingAs($user)
            ->get(route('projects.documents.show', [$projectA, $documentA]));

        $opened->assertOk();
        $this->assertSame('tekening-van-a', $opened->streamedContent());
    }

    public function test_assigned_user_can_open_an_assigned_project(): void
    {
        $project = $this->makeProject('Project A');
        $user = User::factory()->limitedAccess()->uitvoerder()->create();
        $user->projects()->attach($project);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Project A');
    }

    public function test_role_still_blocks_purge_on_an_assigned_project(): void
    {
        $project = $this->makeProject('Project A');
        $user = User::factory()->limitedAccess()->uitvoerder()->create();
        $user->projects()->attach($project);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->assertModelExists($project);
    }

    public function test_uitvoerder_can_still_create_a_snag_on_an_assigned_project(): void
    {
        $project = $this->makeProject('Project A');
        $user = User::factory()->limitedAccess()->uitvoerder()->create();
        $user->projects()->attach($project);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)->postJson(route('projects.snags.store', $project), [
            'x' => 0.22,
            'y' => 0.41,
            'drawing_page' => 1,
            'description' => 'Kim niet recht',
            'assigned_worker_id' => $worker->id,
        ])->assertCreated();
    }

    private function makeDocument(Project $project, string $path, string $filename): ProjectDocument
    {
        return ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => $filename,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
        ]);
    }

    private function makeProject(string $name): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => 'gepland',
        ]);
    }
}
