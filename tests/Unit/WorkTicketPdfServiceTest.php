<?php

namespace Tests\Unit;

use App\Enums\ProjectKind;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaDrawingMarker;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Services\WorkTicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class WorkTicketPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filename_uses_kind_company_and_number(): void
    {
        $ticket = $this->makeTicket();

        $filename = app(WorkTicketPdfService::class)->filename($ticket);

        $this->assertSame('Opdrachtbon_Het-Vloerenhuis_250100010_OB-2026-0001.pdf', $filename);
    }

    public function test_build_omits_prices_when_show_prices_is_false(): void
    {
        $ticket = $this->makeTicket();

        $data = app(WorkTicketPdfService::class)->build($ticket, false);

        $this->assertSame('OPDRACHTBON', $data['documentTitle']);
        $this->assertSame('OB-2026-0001', $data['number']);
        $this->assertFalse($data['showPrices']);
        $this->assertSame('Het Vloerenhuis', $data['recipient']);
        $this->assertSame('Gezondheidscentrum Laren', $data['projectTitle']);
        $this->assertSame('Nicon Vloeren', $data['companyName']);
        $this->assertStringContainsString('nicon-vloeren.png', $data['logoUrl']);
    }

    public function test_eigen_werkbon_lists_vakman_names_and_roles_without_the_team_label(): void
    {
        $ticket = $this->makeTicket();
        $worker = $ticket->worker;
        $worker->forceFill([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'company' => null,
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => ''],
                ['name' => 'Mahmoud Ali', 'phone' => ''],
            ],
        ])->save();
        $people = $worker->fresh()->crewPeople()->orderBy('sort_order')->get();
        $assignment = $ticket->assignment;
        $assignment->syncPresentCrew([$people[0]->id, $people[1]->id]);
        $assignment->applyRoles($people[0]->id, $people[1]->id);
        $ticket->forceFill(['kind' => WorkTicketKind::Werkbon, 'number' => 'WB-2026-0001'])->save();

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(['worker', 'assignment.crewMembers', 'assignment.foreman', 'assignment.workTicketHolder']), false);

        $this->assertSame('Nick Seine, Mahmoud Ali', $data['recipient']);
        $this->assertNull($data['recipientKind']);
        $this->assertSame('Vakmannen', $data['whoHeading']);
        $this->assertSame('Nick Seine', $data['foreman']);
        $this->assertSame('Mahmoud Ali', $data['workTicketHolder']);
    }

    public function test_eigen_werkbon_lists_other_people_present_on_the_same_job(): void
    {
        $ticket = $this->makeTicket();
        $worker = $ticket->worker;
        $worker->forceFill([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'company' => null,
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => ''],
                ['name' => 'Mahmoud Ali', 'phone' => ''],
            ],
        ])->save();
        $people = $worker->fresh()->crewPeople()->orderBy('sort_order')->get();
        $assignment = $ticket->assignment;
        $assignment->syncPresentCrew([$people[0]->id, $people[1]->id]);
        $ticket->forceFill(['kind' => WorkTicketKind::Werkbon, 'number' => 'WB-2026-0001'])->save();

        $otherTeam = Worker::query()->create([
            'name' => 'Team 2',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Alexandr Popov', 'phone' => ''],
                ['name' => 'José Garcia', 'phone' => ''],
            ],
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $otherTeam->id,
            'project_id' => $ticket->project_id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $zzp = Worker::query()->create([
            'name' => 'Arek',
            'employment_type' => 'zzp',
            'company' => 'MO Vloeren',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $zzp->id,
            'project_id' => $ticket->project_id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh([
            'worker',
            'assignment.crewMembers',
            'assignment.worker.crewPeople',
        ]), false);

        $this->assertSame(['Alexandr Popov', 'José Garcia', 'MO Vloeren'], $data['colleagues']);
    }

    public function test_build_uses_kloppenburg_letterhead_for_winkel_projects(): void
    {
        $ticket = $this->makeTicket();
        $ticket->project->forceFill(['kind' => ProjectKind::Winkel])->save();

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(['project', 'worker', 'lines.workItem']), true);

        $this->assertSame('Kloppenburg Interieur', $data['companyName']);
        $this->assertStringContainsString('kloppenburg-interieur.png', $data['logoUrl']);
        $this->assertTrue($data['showPrices']);
    }

    public function test_build_keeps_prices_for_the_zzp_opdrachtbon(): void
    {
        $ticket = $this->makeTicket();

        $data = app(WorkTicketPdfService::class)->build($ticket, true);

        $this->assertTrue($data['showPrices']);
        $this->assertSame(1050.0, (float) $ticket->lines->first()->amount);
    }

    public function test_build_places_selected_rooms_on_their_drawing_pages(): void
    {
        Storage::fake('local');

        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $floorOne = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'verdieping 1',
            'sort_order' => 1,
        ]);
        $floorTwo = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'verdieping 2',
            'sort_order' => 2,
        ]);
        $roomOne = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floorOne->id,
            'area_number' => '1.10',
            'name' => 'wachtkamer',
            'square_meters' => 20,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        $roomTwo = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floorTwo->id,
            'area_number' => '2.04',
            'name' => 'kantoor',
            'square_meters' => 18,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => 'projects/'.$project->id.'/plattegrond/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        AreaDrawingMarker::query()->create([
            'project_area_id' => $roomOne->id,
            'project_document_id' => $drawing->id,
            'page' => 1,
            'x' => 0.20,
            'y' => 0.30,
            'width' => 0.10,
            'height' => 0.04,
            'source' => 'auto',
        ]);
        AreaDrawingMarker::query()->create([
            'project_area_id' => $roomTwo->id,
            'project_document_id' => $drawing->id,
            'page' => 3,
            'x' => 0.60,
            'y' => 0.70,
            'width' => 0.10,
            'height' => 0.04,
            'source' => 'auto',
        ]);
        $ticket->floors()->attach($floorOne->id, ['entire_floor' => false]);
        $ticket->floors()->attach($floorTwo->id, ['entire_floor' => false]);
        $ticket->areas()->attach([$roomOne->id, $roomTwo->id]);
        $ticket->documents()->attach($drawing->id);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(), false);

        $this->assertTrue($data['drawingIsPdf']);
        $this->assertSame('browser', $data['drawingRender']);
        $this->assertSame('plattegrond.pdf', $data['drawingName']);
        $this->assertSame(route('projects.documents.show', [$project, $drawing], false), $data['drawingUrl']);
        $this->assertCount(2, $data['floorLayers']);
        $this->assertSame(1, $data['floorLayers'][0]['page']);
        $this->assertSame('verdieping 1', $data['floorLayers'][0]['name']);
        $this->assertSame('1.10 wachtkamer', $data['floorLayers'][0]['rooms']);
        $this->assertSame([
            ['x' => 0.25, 'y' => 0.32, 'label' => '1.10'],
        ], $data['floorLayers'][0]['pins']);
        $this->assertSame(3, $data['floorLayers'][1]['page']);
        $this->assertSame('verdieping 2', $data['floorLayers'][1]['name']);
        $this->assertSame('2.04 kantoor', $data['floorLayers'][1]['rooms']);
        $this->assertSame('2.04', $data['floorLayers'][1]['pins'][0]['label']);
    }

    public function test_build_falls_back_to_the_first_drawing_page_without_markers(): void
    {
        Storage::fake('local');

        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.63',
            'name' => 'oefenruimte',
            'square_meters' => 28,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'fase-1.pdf',
            'file_path' => 'projects/'.$project->id.'/plattegrond/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        $ticket->floors()->attach($floor->id, ['entire_floor' => true]);
        $ticket->areas()->attach($area->id);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(), false);

        $this->assertSame('fase-1.pdf', $data['drawingName']);
        $this->assertSame('browser', $data['drawingRender']);
        $this->assertCount(1, $data['floorLayers']);
        $this->assertSame(1, $data['floorLayers'][0]['page']);
        $this->assertSame('1e verdieping', $data['floorLayers'][0]['name']);
        $this->assertNull($data['floorLayers'][0]['image']);
        $this->assertSame([], $data['floorLayers'][0]['pins']);
    }

    public function test_build_rasterizes_a_drawing_pdf_when_the_file_is_stored(): void
    {
        Storage::fake('local');

        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '1.63',
            'name' => 'oefenruimte',
            'square_meters' => 28,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        $relative = 'work-tickets/tests/'.uniqid('plan-', true).'/plan.pdf';
        Storage::disk('local')->put($relative, SimplePdf::bytes('Plattegrond'));
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'fase-1.pdf',
            'file_path' => $relative,
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        $ticket->floors()->attach($floor->id, ['entire_floor' => true]);
        $ticket->areas()->attach($area->id);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(), false);

        $this->assertSame('image', $data['drawingRender']);
        $this->assertCount(1, $data['floorLayers']);
        $this->assertSame(1, $data['floorLayers'][0]['page']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $data['floorLayers'][0]['image']);
    }

    public function test_build_embeds_a_stored_drawing_image_as_a_data_uri(): void
    {
        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $relative = 'projects/'.$project->id.'/plattegrond/plan.png';
        Storage::disk('local')->put(
            $relative,
            (string) file_get_contents(public_path('images/nicon-vloeren.png')),
        );
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.png',
            'file_path' => $relative,
            'mime_type' => 'image/png',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        $ticket->documents()->attach($drawing->id);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(), false);

        $this->assertSame('image', $data['drawingRender']);
        $this->assertNotEmpty($data['floorLayers']);
        $this->assertStringStartsWith('data:image/png;base64,', $data['floorLayers'][0]['image']);
    }

    public function test_html_build_skips_drawing_embeds(): void
    {
        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $relative = 'projects/'.$project->id.'/plattegrond/plan.png';
        Storage::disk('local')->put(
            $relative,
            (string) file_get_contents(public_path('images/nicon-vloeren.png')),
        );
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.png',
            'file_path' => $relative,
            'mime_type' => 'image/png',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        $ticket->documents()->attach($drawing->id);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(), false, false);

        $this->assertSame('image', $data['drawingRender']);
        $this->assertNotEmpty($data['floorLayers']);
        $this->assertNull($data['floorLayers'][0]['image']);
        $this->assertNull($data['drawingItems'][0]['path']);
        $this->assertNotNull($data['drawingUrl']);
    }

    public function test_build_puts_the_project_plattegrond_on_the_werkbon_when_none_is_attached(): void
    {
        $ticket = $this->makeTicket();
        $project = $ticket->project;
        $relative = 'projects/'.$project->id.'/plattegrond/plan.png';
        Storage::disk('local')->put(
            $relative,
            (string) file_get_contents(public_path('images/nicon-vloeren.png')),
        );
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.png',
            'file_path' => $relative,
            'mime_type' => 'image/png',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);

        $data = app(WorkTicketPdfService::class)->build($ticket->fresh(['project.documents']), false);

        $this->assertSame(['plattegrond.png'], $data['drawings']);
        $this->assertTrue($data['drawingItems'][0]['is_image']);
        $this->assertSame('plattegrond.png', $data['drawingItems'][0]['name']);
        $this->assertNotNull($data['drawingItems'][0]['url']);
        $this->assertStringStartsWith('data:image/png;base64,', $data['drawingItems'][0]['path']);
    }

    private function makeTicket(): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Het Vloerenhuis',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 84,
            'status' => 'gepland',
        ]);
        $ticket = WorkTicket::query()->create([
            'number' => 'OB-2026-0001',
            'kind' => WorkTicketKind::Opdrachtbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'billing_method' => WorkTicketBilling::Unit,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
        $ticket->lines()->create([
            'work_item_id' => $item->id,
            'quantity' => 84,
            'unit' => WorkUnit::SquareMeter,
            'unit_price' => 12.5,
            'amount' => 1050,
        ]);

        return $ticket->fresh(['worker', 'project', 'lines.workItem']);
    }
}
