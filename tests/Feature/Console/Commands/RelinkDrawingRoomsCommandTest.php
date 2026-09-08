<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelinkDrawingRoomsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prints_link_counts_for_sluisbuurt_project(): void
    {
        Storage::fake('local');
        $user = User::factory()->projectleider()->create();
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260200092',
            'customer_id' => $customer->id,
            'name' => 'IKC Sluisbuurt',
            'status' => 'gepland',
        ]);
        $file = UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf');
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', 'plan.pdf', 'local');
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        $this->artisan('drawings:relink', ['--project' => 'Sluisbuurt'])
            ->assertSuccessful()
            ->expectsOutputToContain('Automatisch gekoppeld:')
            ->expectsOutputToContain('Handmatig nodig:');
    }
}
