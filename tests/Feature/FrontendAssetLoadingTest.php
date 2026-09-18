<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FrontendAssetLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_does_not_load_application_or_pdf_scripts(): void
    {
        $html = $this->get(route('login'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/rel="stylesheet"[^>]+href="[^"]+"/', $html);
        $this->assertStringNotContainsString('<script type="module"', $html);
        $this->assertPageScriptsDoNotContain($html, 'app.js', 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract', 'snag-pdf', 'planning');
    }

    public function test_setup_page_does_not_load_application_or_pdf_scripts(): void
    {
        $html = $this->get(route('setup.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/rel="stylesheet"[^>]+href="[^"]+"/', $html);
        $this->assertStringNotContainsString('<script type="module"', $html);
        $this->assertPageScriptsDoNotContain($html, 'app.js', 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract');
    }

    public function test_dashboard_loads_app_script_without_pdf_libraries(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($html, 'app.js');
        $this->assertPageScriptsDoNotContain($html, 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract', 'snag-pdf', 'planning.js', 'project-upload');
    }

    public function test_project_board_loads_drawing_board_script(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($html, 'drawing-board');
        $this->assertPageScriptsContain($html, 'app.js');
    }

    public function test_new_project_page_loads_upload_script_without_drawing_board(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($html, 'project-upload');
        $this->assertPageScriptsDoNotContain($html, 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract', 'snag-pdf');
    }

    public function test_planning_page_does_not_load_drawing_board_or_pdf_libraries(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($html, 'planning');
        $this->assertPageScriptsDoNotContain($html, 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract', 'snag-pdf', 'project-upload');
    }

    public function test_project_list_loads_autosave_script_without_drawing_board(): void
    {
        $user = User::factory()->create();
        $this->makeProject();

        $html = $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($html, 'project-list');
        $this->assertPageScriptsDoNotContain($html, 'drawing-board', 'pdf.worker', 'pdfjs', 'tesseract', 'snag-pdf');
    }

    public function test_snag_pdf_export_loads_pdfjs_only_for_pdf_drawings(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = $this->makeProject();
        $this->attachDrawing($project, $user, 'plattegrond.pdf', 'application/pdf');

        $pdfHtml = $this->actingAs($user)
            ->get(route('projects.snags.export', ['project' => $project, 'drawing' => 1, 'photos' => 0]))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsContain($pdfHtml, 'snag-pdf');

        $drawing = $project->plattegrond();
        $file = UploadedFile::fake()->image('plattegrond.jpg', 80, 60);
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', 'plan.jpg', 'local');
        $drawing->update([
            'original_filename' => 'plattegrond.jpg',
            'file_path' => $path,
            'mime_type' => 'image/jpeg',
        ]);

        $imageHtml = $this->actingAs($user)
            ->get(route('projects.snags.export', ['project' => $project, 'drawing' => 1, 'photos' => 0]))
            ->assertOk()
            ->getContent();

        $this->assertPageScriptsDoNotContain($imageHtml, 'snag-pdf', 'pdf.worker', 'pdfjs');
    }

    public function test_app_entry_does_not_import_feature_libraries(): void
    {
        $source = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('drawing-board', $source);
        $this->assertStringNotContainsString('project-upload', $source);
        $this->assertStringNotContainsString('pdfjs', $source);
        $this->assertStringNotContainsString('tesseract', $source);
        $this->assertStringNotContainsString('snag-pdf', $source);
        $this->assertStringNotContainsString('planning.js', $source);
        $this->assertStringNotContainsString('project-list', $source);
    }

    private function makeProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-100046',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ]);
    }

    private function attachDrawing(Project $project, User $user, string $filename, string $mime): void
    {
        $file = UploadedFile::fake()->create($filename, 20, $mime);
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', $filename, 'local');

        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => $filename,
            'file_path' => $path,
            'mime_type' => $mime,
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);
    }

    private function assertPageScriptsContain(string $html, string $entry): void
    {
        $this->assertMatchesRegularExpression(
            $this->viteScriptPattern($entry),
            $this->pageScriptUrls($html),
        );
    }

    private function assertPageScriptsDoNotContain(string $html, string ...$needles): void
    {
        $urls = $this->pageScriptUrls($html);

        foreach ($needles as $needle) {
            if (in_array($needle, ['pdf.worker', 'pdfjs', 'tesseract'], true)) {
                $this->assertStringNotContainsString($needle, $urls);

                continue;
            }

            $this->assertDoesNotMatchRegularExpression($this->viteScriptPattern($needle), $urls);
        }
    }

    private function viteScriptPattern(string $entry): string
    {
        $name = preg_replace('/\.js$/', '', $entry);

        return '/(?:resources\/js\/'.preg_quote($name, '/').'\.js|'.preg_quote($name, '/').'-[A-Za-z0-9_.-]+\.js)/';
    }

    private function pageScriptUrls(string $html): string
    {
        preg_match_all('/<(?:script|link)(?![^>]*rel="stylesheet")[^>]+(?:src|href)="([^"]+)"/i', $html, $matches);

        return implode("\n", $matches[1]);
    }
}
