<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Enums\WorkOrderType;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class VakmanPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_vakman_week_agenda_shows_compact_day_cards_without_material_lists(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own, $other] = $this->seedProjects();
        $kees = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'uurtarief' => 87.5,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'Tarkett pvc Classics-English Oak, PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 80,
            'status' => 'gepland',
        ]);
        WorkerAssignment::query()
            ->where('worker_id', $nick->id)
            ->where('project_id', $own->id)
            ->update(['work_item_id' => $item->id]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Mijn planning')
            ->assertSee('Week')
            ->assertSee('Maand')
            ->assertSee('Mijn week')
            ->assertSee('Details')
            ->assertSee('DONDERDAG')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Zwolle')
            ->assertSee('Hele dag')
            ->assertSee('PVC')
            ->assertSee('Met: Kees')
            ->assertSee('Vrij')
            ->assertSee('Geen planning')
            ->assertSee('Bekijk werk')
            ->assertSee('Open werkbon')
            ->assertSee('Route')
            ->assertSee('Industrieweg 8')
            ->assertSee('Werkadres')
            ->assertSee('Projecten')
            ->assertSee('vakman-week-split', false)
            ->assertSee('data-job-target', false)
            ->assertDontSee('Tekeningen')
            ->assertDontSee('Kindcentrum Veldhoeve')
            ->assertDontSee('Tarkett')
            ->assertDontSee('Classics-English Oak')
            ->assertDontSee('€')
            ->assertDontSee('87,50')
            ->assertDontSee('87.50')
            ->assertDontSee('uurtarief')
            ->assertDontSee('Gebruikers')
            ->assertDontSee('Vakmensen / ZZP')
            ->assertDontSee('Personeel')
            ->assertDontSee('Archief')
            ->assertDontSee('Opdrachtbon')
            ->getContent();

        $this->assertStringContainsString('href="'.route('vakman.planning').'"', $html);
        $this->assertStringContainsString('href="'.route('vakman.planning.day', '2026-09-10').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/planning').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/projecten').'"', $html);
        $this->assertStringNotContainsString('href="'.url('/gebruikers').'"', $html);
        $this->assertStringNotContainsString('images/decoloop.png', $html);
        $this->assertStringNotContainsString((string) $other->name, $html);
    }

    public function test_vakman_week_renders_a_day_picker_with_today_selected(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('vakman-day-strip', false)
            ->assertSee('data-day-target="2026-09-07"', false)
            ->assertSee('data-day-target="2026-09-10"', false)
            ->assertSee('data-day-target="2026-09-12"', false)
            ->assertSee('data-day-key="2026-09-10"', false)
            ->assertSee('Week 37')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'vakman-week-day is-today is-active'));
        $this->assertSame(1, substr_count($html, 'vakman-day-strip-btn is-selected is-today has-jobs'));
        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    public function test_vakman_week_shows_werkbon_summary_and_marks_winkelwerk(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine');
        $project = $this->makeProject('Gezondheidscentrum Laren', [
            'address' => 'Nieuweweg 8',
            'postal_code' => '1251 LG',
            'city' => 'Laren',
        ]);
        $shop = $this->makeProject('Gordijnen Janssen', [
            'kind' => 'winkel',
            'address' => 'Kerkstraat 12',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
            'work_description' => 'Screens plaatsen en inmeten.',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $shop->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
        ]);
        $ticket = WorkTicket::query()->create([
            'number' => 'WB-2026-0008',
            'kind' => WorkTicketKind::Werkbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $nick->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'notes' => 'Eerst egaliseren, daarna primer laten drogen.',
        ]);
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Projecten')
            ->assertSee('Winkel')
            ->assertSee('Werkadres')
            ->assertSee('Nieuweweg 8, 1251 LG Laren')
            ->assertSee('Kerkstraat 12, 3811 AA Amersfoort')
            ->assertSee('Eerst egaliseren, daarna primer laten drogen.')
            ->assertSee('Screens plaatsen en inmeten.')
            ->assertSee('href="'.route('work-tickets.show', $ticket).'" class="vakman-week-job-bon"', false);
    }

    public function test_tekening_button_opens_the_pdf_page_when_a_plattegrond_exists(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $drawing = ProjectDocument::query()->create([
            'project_id' => $own->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'Plattegrond_BG.pdf',
            'file_path' => 'projects/'.$own->id.'/plattegrond/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'parse_status' => 'done',
        ]);
        $user = User::factory()->vakman($nick->id)->create();
        $drawingUrl = route('vakman.drawings.show', [
            'project' => $own,
            'document' => $drawing,
            'day' => '2026-09-10',
        ]);

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('href="'.$drawingUrl.'"', false)
            ->assertSee('>Tekening</a>', false)
            ->assertDontSee('href="'.route('projects.show', $own).'"', false);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Tekening')
            ->assertSee('href="'.$drawingUrl.'"', false)
            ->assertSee('href="'.route('projects.show', $own).'"', false);
    }

    public function test_vakman_day_detail_shows_address_work_and_werkbon_without_prices(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $item = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'uurtarief' => 87.5,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $own->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $own->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'Entree',
            'square_meters' => 24,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => 24,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);
        WorkerAssignment::query()
            ->where('worker_id', $nick->id)
            ->where('project_id', $own->id)
            ->update(['work_item_id' => $item->id]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Donderdag 10 september')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Industrieweg 8, 8013 PM Zwolle')
            ->assertSee('Werkadres')
            ->assertSee('Projecten')
            ->assertSee('PVC')
            ->assertSee('120')
            ->assertSee('Begane grond')
            ->assertSee('0.01 Entree')
            ->assertSee('Bekijk werk')
            ->assertSee('Open werkbon')
            ->assertSee('Route')
            ->assertDontSee('Tekeningen')
            ->assertDontSee('Opdrachtbon')
            ->assertDontSee('€')
            ->assertDontSee('87,50');

        $this->actingAs($user)
            ->get(route('vakman.planning.werkbon', '2026-09-10'))
            ->assertOk()
            ->assertSee('Werkbon')
            ->assertSee('WERKBON')
            ->assertSee('Nicon Vloeren')
            ->assertSee('images/nicon-vloeren.png', false)
            ->assertSee('Laakse Tuinen')
            ->assertSee('PVC')
            ->assertSee('Vakmannen')
            ->assertSee('Nick Seine')
            ->assertDontSee('Eigen medewerker')
            ->assertDontSee('Opdrachtnemer')
            ->assertDontSee('€')
            ->assertDontSee('87,50')
            ->assertDontSee('Opdrachtbon');
    }

    public function test_vakman_day_shows_only_work_selected_on_the_ticket(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $primer = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'Primen & egaliseren',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 702.32,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $own->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $own->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'Entree',
            'square_meters' => 24,
            'status' => 'niet_gestart',
        ]);
        $assignment = WorkerAssignment::query()
            ->where('worker_id', $nick->id)
            ->where('project_id', $own->id)
            ->first();
        $ticket = WorkTicket::query()->create([
            'number' => 'WB-2026-0001',
            'kind' => WorkTicketKind::Werkbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $own->id,
            'worker_id' => $nick->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'notes' => 'alleen primer vandaag',
        ]);
        $ticket->lines()->create([
            'work_item_id' => $primer->id,
            'quantity' => 24,
            'unit' => WorkUnit::SquareMeter,
        ]);
        $ticket->floors()->attach($floor->id, ['entire_floor' => false]);
        $ticket->areas()->attach($area->id);
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Primen & Egaliseren')
            ->assertSee('24')
            ->assertSee('Begane grond')
            ->assertSee('0.01 Entree')
            ->assertSee('alleen primer vandaag')
            ->assertSee(route('work-tickets.show', $ticket), false)
            ->assertDontSee('PVC')
            ->assertDontSee('702,32');
    }

    public function test_zzp_day_shows_opdrachtbon_with_agreed_price_and_hides_werkbon(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $own = $this->makeProject('Laakse Tuinen', [
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $own->id,
            'name' => 'PVC leggen',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 120,
            'status' => 'gepland',
        ]);
        WorkOrder::query()->create([
            'project_id' => $own->id,
            'work_item_id' => $item->id,
            'worker_id' => $nick->id,
            'assignment_type' => WorkOrderType::WorkItem,
            'assigned_quantity' => 120,
            'unit' => WorkUnit::SquareMeter,
            'unit_price' => 12.5,
            'status' => 'gepland',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Mijn planning')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Zwolle')
            ->assertSee('08:00 – 16:00')
            ->assertSee('vakman-agenda-card', false)
            ->assertSee('vakman-week--zzp', false)
            ->assertSee('class="vakman-agenda-today"', false)
            ->assertDontSee('vakman-week-split', false)
            ->assertDontSee('Mijn week')
            ->assertDontSee('Hele dag')
            ->assertDontSee('Bekijk werk')
            ->assertDontSee('Open werkbon')
            ->assertDontSee('Je werkt met')
            ->assertDontSee('WERKBON')
            ->assertDontSee('Route')
            ->assertDontSee('Opdrachtbon')
            ->assertDontSee('Werkadres')
            ->assertDontSee('Projecten')
            ->assertDontSee('Omschrijving');

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('Projectinformatie')
            ->assertSee('08:00 – 16:00')
            ->assertDontSee('Bekijk werk')
            ->assertDontSee('Werkbon')
            ->assertSee('€ 12,50')
            ->assertDontSee('Gewerkte uren');

        $this->actingAs($user)
            ->get(route('vakman.planning.opdrachtbon', ['date' => '2026-09-10', 'project' => $own]))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('OPDRACHTBON')
            ->assertSee('Nicon Vloeren')
            ->assertSee('images/nicon-vloeren.png', false)
            ->assertSee('PVC')
            ->assertSee('€ 12,50')
            ->assertSee('120');

        $this->actingAs($user)
            ->get(route('vakman.planning.werkbon', '2026-09-10'))
            ->assertForbidden();
    }

    public function test_zzp_with_an_hourly_opdracht_can_fill_in_hours(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $own = $this->makeProject('Laakse Tuinen');
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkTicket::query()->create([
            'number' => 'OB-2026-0008',
            'kind' => WorkTicketKind::Opdrachtbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $own->id,
            'worker_id' => $nick->id,
            'billing_method' => WorkTicketBilling::Hourly,
            'hourly_rate' => 45,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('>Van<', false)
            ->assertSee('>Tot<', false)
            ->assertSee('>Pauze<', false)
            ->assertDontSee('Gewerkte uren')
            ->assertSee('Uurprijs')
            ->assertSee('€ 45,00');

        $this->actingAs($user)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-10',
                'worker_assignment_id' => $assignment->id,
                'project_id' => $own->id,
                'start_time' => '08:00',
                'end_time' => '14:00',
                'break_minutes' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '6u ingediend');
    }

    public function test_zzp_today_link_sits_outside_the_week_arrows(): void
    {
        $this->travelTo('2026-09-19 10:00:00');
        $worker = $this->makeWorker('MT Woning', 'zzp');
        $user = User::factory()->vakman($worker->id)->create(['name' => 'Michael Tooren']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Vandaag')
            ->assertSee('vakman-week-day is-today', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-label="Volgende">[^<]*<\/a>\s*<\/div>\s*<a href="[^"]+" class="vakman-agenda-today">Vandaag<\/a>/s',
            $html,
        );
    }

    public function test_zzp_day_and_opdrachtbon_show_tekeningen_when_a_plattegrond_exists(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $own = $this->makeProject('Laakse Tuinen', [
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $path = 'projects/'.$own->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, SimplePdf::bytes('begane grond'));
        $drawing = ProjectDocument::query()->create([
            'project_id' => $own->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'fase 1 verdieping 1 (4/5).pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'parse_status' => 'done',
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $drawingUrl = route('vakman.drawings.show', [
            'project' => $own,
            'document' => $drawing,
            'day' => '2026-09-10',
        ]);

        $this->actingAs($user)
            ->get(route('vakman.planning.day', '2026-09-10'))
            ->assertOk()
            ->assertSee('Tekeningen')
            ->assertSee('fase 1 verdieping 1 (4/5)')
            ->assertSee('href="'.$drawingUrl.'"', false)
            ->assertSee('href="'.route('projects.show', $own).'"', false)
            ->assertDontSee('href="'.route('projects.documents.show', [$own, $drawing]).'"', false)
            ->assertDontSee('€');

        $this->actingAs($user)
            ->get(route('vakman.planning.opdrachtbon', ['date' => '2026-09-10', 'project' => $own]))
            ->assertOk()
            ->assertSee('Tekening')
            ->assertSee('fase 1 verdieping 1 (4/5).pdf')
            ->assertSee('href="'.$drawingUrl.'"', false)
            ->assertSee('href="'.route('projects.documents.show', [$own, $drawing]).'"', false);
    }

    public function test_zzp_can_open_the_drawing_board_and_plattegrond_pdf(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        Storage::fake('local');
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $own = $this->makeProject('Laakse Tuinen');
        $other = $this->makeProject('Kindcentrum Veldhoeve');
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $path = 'projects/'.$own->id.'/plattegrond/plan.pdf';
        Storage::disk('local')->put($path, SimplePdf::bytes('begane grond'));
        $drawing = ProjectDocument::query()->create([
            'project_id' => $own->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'Plattegrond_BG.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'parse_status' => 'done',
        ]);
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('projects.show', $own))
            ->assertOk()
            ->assertSee('id="draw-page"', false)
            ->assertSee('id="project-board"', false);

        $this->actingAs($user)
            ->get(route('projects.documents.show', [$own, $drawing]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($user)
            ->get(route('projects.show', $other))
            ->assertForbidden();
    }

    public function test_eigen_vakman_cannot_open_opdrachtbon(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning.opdrachtbon', ['date' => '2026-09-10', 'project' => $own]))
            ->assertForbidden();
    }

    public function test_month_view_marks_days_with_work(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning', ['view' => 'month', 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Maand')
            ->assertSee('september 2026')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Wk')
            ->assertSee('vakman-month', false)
            ->assertSee(route('vakman.planning.day', '2026-09-10'), false);
    }

    public function test_week_board_marks_registered_absence_on_empty_days(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine');
        $project = $this->makeProject('Laakse Tuinen', [
            'city' => 'Amersfoort',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
        ]);
        $nick->availabilities()->create([
            'start_date' => '2026-09-11',
            'end_date' => '2026-09-11',
            'kind' => AvailabilityKind::Vacation,
            'hours' => 8,
        ]);
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertSee('Mijn week')
            ->assertSee('Laakse Tuinen')
            ->assertSee('Amersfoort')
            ->assertSee('VAKANTIE')
            ->assertSee('Geen planning')
            ->assertDontSee('Team');
    }

    public function test_empty_vakman_planning_explains_that_nothing_is_scheduled(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        $nick = $this->makeWorker('Nick Seine');
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Je staat deze week niet ingepland.');
    }

    public function test_planner_cannot_open_mijn_planning(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertForbidden();
    }

    public function test_vakman_week_shows_only_own_inzet_and_actual_colleagues(): void
    {
        $this->travelTo('2026-09-22 08:00:00');
        $team = Worker::query()->create([
            'name' => 'Team 2',
            'employment_type' => 'eigen',
            'people_count' => 3,
            'crew_members' => [
                ['name' => 'Alexandr', 'phone' => ''],
                ['name' => 'José', 'phone' => ''],
                ['name' => 'Peter', 'phone' => ''],
            ],
            'active' => true,
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $alexandr = $people[0];
        $jose = $people[1];
        $peter = $people[2];
        $amersfoort = $this->makeProject('Laakse Tuinen', [
            'address' => 'Cujikstraat 2',
            'postal_code' => '3826 KL',
            'city' => 'Amersfoort',
        ]);
        $laren = $this->makeProject('Het Vloerenhuis', [
            'address' => 'Eemnesserstraat 19',
            'postal_code' => '1251 NA',
            'city' => 'Laren',
        ]);
        $home = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $amersfoort->id,
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-22',
            'hours_per_day' => 8,
        ]);
        $home->syncPresentCrew([$alexandr->id, $jose->id]);
        $home->applyRoles($alexandr->id, $alexandr->id);
        $away = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $laren->id,
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-22',
            'hours_per_day' => 8,
        ]);
        $away->syncPresentCrew([$peter->id]);
        $away->applyRoles($peter->id, $peter->id);
        $alexandrUser = User::factory()->vakman($team->id, $alexandr->id)->create(['name' => 'Alexandr']);
        $peterUser = User::factory()->vakman($team->id, $peter->id)->create(['name' => 'Peter']);

        $this->actingAs($alexandrUser)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertSee('Cujikstraat 2')
            ->assertSee('Vakmannen')
            ->assertSee('Alexandr')
            ->assertSee('José')
            ->assertSee('WERKBON')
            ->assertSee('Jij bent verantwoordelijk')
            ->assertSee('Open werkbon')
            ->assertSee('Met: José')
            ->assertDontSee('Met: Alexandr')
            ->assertDontSee('Team 2')
            ->assertDontSee('Het Vloerenhuis')
            ->assertDontSee('Eemnesserstraat')
            ->assertDontSee('Peter');

        $this->actingAs($peterUser)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Het Vloerenhuis')
            ->assertSee('Eemnesserstraat 19')
            ->assertSee('WERKBON')
            ->assertDontSee('Laakse Tuinen')
            ->assertDontSee('Cujikstraat')
            ->assertDontSee('Alexandr')
            ->assertDontSee('José');
    }

    public function test_vakman_werkbon_lists_other_people_present_on_the_same_job(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick, $own] = $this->seedProjects();
        $nick->forceFill([
            'name' => 'Team 1 Nick',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => ''],
                ['name' => 'Mahmoud Ali', 'phone' => ''],
            ],
        ])->save();
        $crew = $nick->fresh()->crewPeople()->orderBy('sort_order')->get();
        $assignment = WorkerAssignment::query()
            ->where('worker_id', $nick->id)
            ->where('project_id', $own->id)
            ->first();
        $assignment->syncPresentCrew([$crew[0]->id, $crew[1]->id]);

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
        $others = $otherTeam->crewPeople()->orderBy('sort_order')->get();
        $otherAssignment = WorkerAssignment::query()->create([
            'worker_id' => $otherTeam->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        $otherAssignment->syncPresentCrew([$others[0]->id, $others[1]->id]);

        $zzp = Worker::query()->create([
            'name' => 'Arek',
            'employment_type' => 'zzp',
            'company' => 'MO Vloeren',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $zzp->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        $user = User::factory()->vakman($nick->id, $crew[0]->id)->create(['name' => 'Nick Seine']);

        $html = $this->actingAs($user)
            ->get(route('vakman.planning.werkbon', '2026-09-10'))
            ->assertOk()
            ->assertSee('Vakmannen')
            ->assertSee('Ook aanwezig')
            ->assertSeeInOrder([
                'Vakmannen',
                'Nick Seine',
                'Mahmoud Ali',
                'Ook aanwezig',
                'Alexandr',
                'José',
                'MO Vloeren',
            ])
            ->assertDontSee('Team 1')
            ->assertDontSee('Team 2')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/Ook aanwezig<\/div>\s*<p[^>]*>[^<]*Mahmoud/', $html);
    }

    public function test_teammate_sees_who_has_the_werkbon_and_cannot_open_it(): void
    {
        $this->travelTo('2026-09-21 08:00:00');
        $team = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => ''],
                ['name' => 'Mahmoud', 'phone' => ''],
            ],
            'active' => true,
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $nick = $people[0];
        $mahmoud = $people[1];
        $project = $this->makeProject('Feringa Building', [
            'address' => 'Nijenborgh 4',
            'postal_code' => '9747 AG',
            'city' => 'Groningen',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-21',
            'hours_per_day' => 8,
        ]);
        $assignment->syncPresentCrew([$nick->id, $mahmoud->id]);
        $assignment->applyRoles($nick->id, $nick->id);
        $nickUser = User::factory()->vakman($team->id, $nick->id)->create(['name' => 'Nick']);
        $mahmoudUser = User::factory()->vakman($team->id, $mahmoud->id)->create(['name' => 'Mahmoud']);

        $this->actingAs($mahmoudUser)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Feringa Building')
            ->assertSee('Vakmannen')
            ->assertSee('Nick')
            ->assertSee('Voorman')
            ->assertSee('Werkbon bij: Nick')
            ->assertSee('Met: Nick')
            ->assertDontSee('Met: Mahmoud')
            ->assertDontSee('Open werkbon')
            ->assertDontSee('Jij bent verantwoordelijk');

        $this->actingAs($mahmoudUser)
            ->get(route('vakman.planning.werkbon', '2026-09-21'))
            ->assertForbidden();

        $this->actingAs($nickUser)
            ->get(route('vakman.planning.werkbon', '2026-09-21'))
            ->assertOk()
            ->assertSee('WERKBON')
            ->assertSee('Vakmannen')
            ->assertSee('Nick')
            ->assertSee('Mahmoud')
            ->assertSee('Voorman Nick')
            ->assertSee('Werkbon bij Nick')
            ->assertDontSee('Team 1')
            ->assertDontSee('Eigen medewerker')
            ->assertDontSee('Opdrachtnemer');
    }

    public function test_week_card_places_the_hours_form_under_route(): void
    {
        $this->travelTo('2026-09-10 08:00:00');
        [$nick] = $this->seedProjects();
        $user = User::factory()->vakman($nick->id)->create();

        $html = $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Bekijk werk')
            ->assertSee('Uren indienen')
            ->getContent();

        $route = strpos($html, 'vakman-job-route');
        $drawing = strpos($html, 'Geen tekening');
        $hours = strpos($html, 'vakman-hours-block');
        $this->assertNotFalse($route);
        $this->assertNotFalse($drawing);
        $this->assertNotFalse($hours);
        $this->assertLessThan($drawing, $route);
        $this->assertLessThan($hours, $drawing);
        $this->assertStringContainsString('name="start_time"', $html);
    }

    public function test_mobile_hours_form_uses_the_full_card_width(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);
        $mobile = $this->cssMediaBlocks($css, 899);

        $this->assertStringNotContainsString('.vakman-job-card-actions > .vakman-job-route', $mobile);
        $this->assertStringContainsString('.vakman-agenda--split .vakman-agenda-title', $mobile);
        $this->assertStringContainsString('.vakman-agenda--split .vakman-agenda-leave-row', $mobile);
        $this->assertStringContainsString('.vakman-job-card-actions > .vakman-job-drawing', $mobile);
        $this->assertStringContainsString('.vakman-job-card-actions > .vakman-hours-block', $mobile);
        $this->assertStringContainsString('grid-column: 1 / -1', $mobile);
        $this->assertStringContainsString('min-width: 0', $mobile);
        $this->assertStringContainsString('max-width: 100%', $mobile);
        $this->assertStringContainsString('width: 100%', $mobile);
        $this->assertStringContainsString('overflow-x: hidden', $mobile);

        $narrow = $this->cssMediaBlocks($css, 360);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);', $narrow);
        $this->assertStringContainsString('.vakman-hours-times > .vakman-hours-field:nth-child(3)', $narrow);
        $this->assertStringContainsString('grid-column: 1 / -1', $narrow);
        $this->assertStringNotContainsString('minmax(0, 5.5rem)', $narrow);

        $this->assertStringContainsString(
            'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 5.5rem);',
            $css,
        );
    }

    public function test_mobile_vakman_header_is_shorter_than_on_desktop(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);
        $mobile = $this->cssMediaBlocks($css, 899);
        $desktop = $this->cssMediaBlocks($css, 900, 'min');

        $this->assertStringContainsString('.nicon-logo {', $css);
        $this->assertMatchesRegularExpression('/\.nicon-logo\s*\{[^}]*height:\s*1\.35rem;/s', $css);
        $this->assertStringContainsString('.nicon-topbar--vakman .nicon-logo', $mobile);
        $this->assertMatchesRegularExpression('/\.nicon-topbar--vakman \.nicon-logo\s*\{[^}]*height:\s*1\.25rem;/s', $mobile);
        $this->assertStringContainsString('.nicon-impersonate button', $mobile);
        $this->assertStringContainsString('.vakman-agenda--split .vakman-agenda-title', $mobile);
        $this->assertStringContainsString('.vakman-agenda--split .vakman-agenda-leave-row', $mobile);
        $this->assertStringContainsString('display: none', $mobile);
        $this->assertStringContainsString('.nicon-topbar--vakman .nicon-topbar-brand', $mobile);
        $this->assertStringContainsString('.nicon-topbar--vakman .nicon-topbar-menu a', $mobile);
        $this->assertStringContainsString('flex-direction: row', $mobile);
        $this->assertStringContainsString('align-items: center', $mobile);
        $this->assertStringContainsString('content: "|";', $mobile);
        $this->assertStringContainsString('min-height: 2.2rem', $mobile);
        $this->assertStringContainsString('white-space: nowrap', $mobile);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr))', $mobile);
        $this->assertStringContainsString('.vakman-agenda--split .vakman-agenda-nav a:first-of-type', $mobile);
        $this->assertStringContainsString('min-height: 2.35rem', $mobile);

        $this->assertStringNotContainsString('.nicon-topbar--vakman .nicon-topbar-menu a', $desktop);
        $this->assertStringNotContainsString('content: "|";', $desktop);
        $this->assertStringNotContainsString('min-height: 2.2rem', $desktop);
        $this->assertStringNotContainsString('height: 1.25rem', $desktop);
    }

    /**
     * @return array{0: Worker, 1: Project, 2?: Project}
     */
    private function seedProjects(): array
    {
        $nick = $this->makeWorker('Nick Seine');
        $own = $this->makeProject('Laakse Tuinen', [
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
        ]);
        $other = $this->makeProject('Kindcentrum Veldhoeve');
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $this->makeWorker('Andere ploeg')->id,
            'project_id' => $other->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        return [$nick, $own, $other];
    }

    private function makeWorker(string $name, string $employmentType = 'eigen'): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => $employmentType,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(string $name, array $attributes = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create(array_merge([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ], $attributes));
    }

    private function cssMediaBlocks(string $css, int $width, string $bound = 'max'): string
    {
        $blocks = '';
        $offset = 0;
        $needle = '@media ('.$bound.'-width: '.$width.'px)';
        while (($start = strpos($css, $needle, $offset)) !== false) {
            $open = strpos($css, '{', $start);
            $this->assertNotFalse($open);
            $blocks .= $this->cssBraceBlock($css, $open);
            $offset = $open + 1;
        }
        $this->assertNotSame('', $blocks);

        return $blocks;
    }

    private function cssBraceBlock(string $css, int $open): string
    {
        $depth = 0;
        $length = strlen($css);
        for ($index = $open; $index < $length; $index++) {
            if ($css[$index] === '{') {
                $depth++;
            } elseif ($css[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open, $index - $open + 1);
                }
            }
        }

        $this->fail('Unclosed CSS block.');
    }
}
