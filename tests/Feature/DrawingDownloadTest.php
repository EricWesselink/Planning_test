<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DrawingDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_saves_the_current_drawing_and_leaves_the_project_unchanged(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $project = $this->project('Laakse Tuinen');
        $current = "%PDF-1.4\noriginele-fase-1";
        Storage::disk('local')->put('projects/current.pdf', $current);
        Storage::disk('local')->put('projects/old.pdf', "%PDF-1.4\noude-revisie");
        $drawing = $this->drawing($project, 'projects/current.pdf', 'fase 1 verdieping 1.pdf', current: true, revision: 2);
        $previous = $this->drawing($project, 'projects/old.pdf', 'oude-tekening.pdf', current: false, revision: 1);

        $page = $this->actingAs($user)->get(route('projects.show', $project));

        $page->assertOk()
            ->assertSee('id="draw-download"', false)
            ->assertSee('⬇ Tekening downloaden', false)
            ->assertSee('href="'.route('projects.drawings.download', $project).'"', false);

        $download = $this->actingAs($user)->get(route('projects.drawings.download', $project));

        $download->assertDownload('fase 1 verdieping 1.pdf');
        $this->assertSame($current, $download->streamedContent());
        $drawing->refresh();
        $previous->refresh();
        $this->assertSame(2, $drawing->revision);
        $this->assertTrue($drawing->is_current);
        $this->assertSame('projects/current.pdf', $drawing->file_path);
        $this->assertFalse($previous->is_current);
        $this->assertSame(0, $project->progressEntries()->count());
        $this->assertSame($current, Storage::disk('local')->get('projects/current.pdf'));
    }

    public function test_photo_drawing_downloads_with_its_original_name(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $project = $this->project('Fotoplattegrond');
        Storage::disk('local')->put('projects/plan.jpg', 'jpeg-bytes');
        $this->drawing($project, 'projects/plan.jpg', 'plattegrond foto.jpg', current: true, revision: 1, mime: 'image/jpeg');

        $download = $this->actingAs($user)->get(route('projects.drawings.download', $project));

        $download->assertDownload('plattegrond foto.jpg');
        $this->assertSame('jpeg-bytes', $download->streamedContent());
    }

    public function test_download_name_drops_path_and_header_characters(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $project = $this->project('Naam');
        Storage::disk('local')->put('projects/plan.pdf', 'pdf-bytes');
        $this->drawing($project, 'projects/plan.pdf', "../fase\r\n1\".pdf", current: true, revision: 1);

        $download = $this->actingAs($user)->get(route('projects.drawings.download', $project));

        $download->assertDownload('fase1.pdf');
        $this->assertStringNotContainsString("\r", (string) $download->headers->get('content-disposition'));
        $this->assertStringNotContainsString("\n", (string) $download->headers->get('content-disposition'));
    }

    public function test_read_only_user_can_download_the_drawing(): void
    {
        Storage::fake('local');
        $user = User::factory()->alleenLezen()->create();
        $project = $this->project('Alleen lezen');
        Storage::disk('local')->put('projects/plan.pdf', 'pdf-bytes');
        $this->drawing($project, 'projects/plan.pdf', 'tekening.pdf', current: true, revision: 1);

        $this->actingAs($user)
            ->get(route('projects.drawings.download', $project))
            ->assertDownload('tekening.pdf');
    }

    public function test_project_without_a_drawing_hides_the_button_and_returns_not_found(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $project = $this->project('Zonder tekening');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('id="draw-download"', false)
            ->assertDontSee('Tekening downloaden');

        $this->actingAs($user)
            ->get(route('projects.drawings.download', $project))
            ->assertNotFound();
    }

    public function test_missing_drawing_file_hides_the_button_and_returns_not_found(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $project = $this->project('Bestand weg');
        $this->drawing($project, 'projects/missing.pdf', 'tekening.pdf', current: true, revision: 1);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Tekening downloaden');

        $this->actingAs($user)
            ->get(route('projects.drawings.download', $project))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $project = $this->project('Gast');

        $this->get(route('projects.drawings.download', $project))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_project_access_cannot_download(): void
    {
        Storage::fake('local');
        $project = $this->project('Ander project');
        Storage::disk('local')->put('projects/plan.pdf', 'pdf-bytes');
        $this->drawing($project, 'projects/plan.pdf', 'tekening.pdf', current: true, revision: 1);
        $user = User::factory()->limitedAccess()->create();

        $this->actingAs($user)
            ->get(route('projects.drawings.download', $project))
            ->assertForbidden();
    }

    public function test_user_without_file_permission_cannot_download(): void
    {
        Storage::fake('local');
        $user = User::factory()->aangepast([Permission::ProjectsView])->create();
        $project = $this->project('Geen bestanden');
        Storage::disk('local')->put('projects/plan.pdf', 'pdf-bytes');
        $this->drawing($project, 'projects/plan.pdf', 'tekening.pdf', current: true, revision: 1);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Tekening downloaden');

        $this->actingAs($user)
            ->get(route('projects.drawings.download', $project))
            ->assertForbidden();
    }

    private function project(string $name): Project
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => 'gepland',
        ]);
    }

    private function drawing(
        Project $project,
        string $path,
        string $filename,
        bool $current,
        int $revision,
        string $mime = 'application/pdf',
    ): ProjectDocument {
        return ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'revision' => $revision,
            'is_current' => $current,
            'original_filename' => $filename,
            'file_path' => $path,
            'mime_type' => $mime,
            'parse_status' => 'none',
        ]);
    }
}
