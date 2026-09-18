<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\MeasurementFormRow;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Services\WorkTicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class MeasurementFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_shows_collapsed_measurement_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertSee('Inmeetformulier vloeren / plint / trap')
            ->assertSee('Nog niet ingevuld')
            ->assertSee('Inmeetformulier invullen')
            ->assertSee('data-measurement-product', false)
            ->assertSee('data-measurement-panel', false)
            ->assertSee('hidden', false);
    }

    public function test_planner_creates_winkelwerk_without_a_measurement_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.winkel.store'), $this->winkelPayload([
                'measurement' => [
                    'meter_user_id' => '',
                    'ordered_at' => '',
                    'installation_at' => '',
                    'rows' => [
                        $this->emptyRow(),
                        $this->emptyRow(),
                    ],
                ],
            ]))
            ->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $this->assertDatabaseCount('measurement_forms', 0);
        $this->assertDatabaseCount('measurement_form_rows', 0);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Inmeetformulier — Nog niet ingevuld');
    }

    public function test_planner_creates_winkelwerk_with_a_measurement_form_and_skips_empty_rows(): void
    {
        $user = User::factory()->create(['name' => 'Inmeter Jansen']);
        $pvc = $this->activity('pvc-banen');
        $plinten = $this->activity('plinten');

        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'work_activity_ids' => [$pvc->id, $plinten->id],
            'activity_quantities' => [$pvc->id => '100', $plinten->id => '20'],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $plinten->id => WorkUnit::LinearMeter->value,
            ],
            'measurement' => [
                'meter_user_id' => $user->id,
                'ordered_at' => '2026-09-10',
                'installation_at' => '2026-09-18',
                'rows' => [
                    $this->emptyRow(),
                    [
                        'room' => 'Woonkamer',
                        'product' => 'PVC banen',
                        'brand' => 'Quick-Step',
                        'type' => 'Alpha',
                        'color_number' => '4013',
                        'quantity' => '12,5',
                        'unit' => WorkUnit::SquareMeter->value,
                        'underlay' => 'Silent',
                        'skirting' => 'Wit 60mm',
                        'steps' => '',
                        'profile' => 'Eindprofiel',
                        'available_on_site' => '1',
                        'available_location' => 'winkel',
                    ],
                    [
                        'room' => 'Hal',
                        'product' => 'Plinten',
                        'brand' => 'Quick-Step',
                        'type' => '',
                        'color_number' => '',
                        'quantity' => '8',
                        'unit' => WorkUnit::LinearMeter->value,
                        'underlay' => '',
                        'skirting' => '',
                        'steps' => '',
                        'profile' => '',
                        'available_on_site' => '0',
                    ],
                    $this->emptyRow(),
                ],
            ],
        ]))->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $this->assertDatabaseCount('measurement_forms', 1);
        $this->assertDatabaseCount('measurement_form_rows', 2);

        $form = $project->measurementForm;
        $this->assertNotNull($form);
        $this->assertSame($user->id, $form->meter_user_id);
        $this->assertSame('2026-09-10', $form->ordered_at?->toDateString());
        $this->assertSame('2026-09-18', $form->installation_at?->toDateString());
        $this->assertSame(['Woonkamer', 'Hal'], $form->rows->pluck('room')->all());
        $this->assertSame('12.50', $form->rows->first()?->quantity);
        $this->assertSame(WorkUnit::SquareMeter, $form->rows->first()?->unit);
        $this->assertTrue($form->rows->first()?->available_on_site);
        $this->assertSame('winkel', $form->rows->first()?->available_location?->value);
        $this->assertNull($form->rows->last()?->available_location);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Inmeetformulier ✓ Ingevuld')
            ->assertSee('Bekijken / wijzigen');
    }

    public function test_existing_winkelwerk_without_a_form_can_still_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload())->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $screens = $this->activity('screens');

        $this->actingAs($user)->patch(route('projects.winkel.update', $project), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$screens->id],
            'work_description' => 'Screens plaatsen',
        ])->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('Screens plaatsen', $project->work_description);
        $this->assertDatabaseCount('measurement_forms', 0);
    }

    public function test_planner_updates_a_measurement_form_and_can_add_and_remove_rows(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $tapijt = $this->activity('tapijt');
        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'work_activity_ids' => [$pvc->id, $tapijt->id],
            'activity_quantities' => [$pvc->id => '100', $tapijt->id => '20'],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $tapijt->id => WorkUnit::SquareMeter->value,
            ],
            'measurement' => [
                'meter_user_id' => $user->id,
                'rows' => [
                    ['room' => 'Keuken', 'product' => 'PVC banen', 'quantity' => '10', 'unit' => WorkUnit::SquareMeter->value],
                    ['room' => 'Toilet', 'product' => 'Tapijt', 'quantity' => '3', 'unit' => WorkUnit::SquareMeter->value],
                ],
            ],
        ]))->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $screens = $this->activity('screens');

        $this->actingAs($user)->patch(route('projects.winkel.update', $project), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id, $tapijt->id, $screens->id],
            'activity_quantities' => [$pvc->id => '100', $tapijt->id => '20'],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $tapijt->id => WorkUnit::SquareMeter->value,
            ],
            'measurement' => [
                'meter_user_id' => $user->id,
                'ordered_at' => '2026-09-12',
                'rows' => [
                    ['room' => 'Keuken', 'product' => 'PVC banen', 'quantity' => '11', 'unit' => WorkUnit::SquareMeter->value],
                    ['room' => 'Overloop', 'product' => 'Tapijt', 'quantity' => '6', 'unit' => WorkUnit::SquareMeter->value],
                    $this->emptyRow(),
                ],
            ],
        ])->assertRedirect(route('projects.show', $project));

        $project->refresh()->load('measurementForm.rows');
        $this->assertSame('2026-09-12', $project->measurementForm?->ordered_at?->toDateString());
        $this->assertSame(['Keuken', 'Overloop'], $project->measurementForm?->rows->pluck('room')->all());
        $this->assertSame('PVC banen', $project->measurementForm?->rows->first()?->product);
        $this->assertDatabaseCount('measurement_form_rows', 2);
    }

    public function test_rejects_a_measurement_row_with_an_invalid_unit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), $this->winkelPayload([
                'measurement' => [
                    'rows' => [
                        ['room' => 'Kamer', 'quantity' => '4', 'unit' => 'stuks'],
                    ],
                ],
            ]))
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors('measurement.rows.0.unit');

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('measurement_forms', 0);
    }

    public function test_measurement_form_pdf_uses_letterhead_and_row_text(): void
    {
        $user = User::factory()->create(['name' => 'Inmeter Jansen']);
        $pvc = $this->activity('pvc-banen');
        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'customer_name' => 'De Vries',
            'city' => 'Enschede',
            'address' => 'Kerkstraat 12',
            'postal_code' => '7511 AA',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'measurement' => [
                'meter_user_id' => $user->id,
                'ordered_at' => '2026-09-10',
                'installation_at' => '2026-09-20',
                'rows' => [
                    [
                        'room' => 'Woonkamer',
                        'product' => 'PVC banen',
                        'brand' => 'Quick-Step',
                        'quantity' => '20',
                        'unit' => WorkUnit::SquareMeter->value,
                        'available_on_site' => '1',
                        'available_location' => 'nicon',
                    ],
                ],
            ],
        ]))->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $response = $this->actingAs($user)->get(route('projects.winkel.measurement.pdf', $project));
        $response->assertOk();
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
        $text = preg_replace('/\s+/', '', (new Parser)->parseContent($response->getContent())->getText()) ?? '';
        $this->assertStringContainsString('INMEETFORMULIER', $text);
        $this->assertStringContainsString('DeVries', $text);
        $this->assertStringContainsString('Woonkamer', $text);
        $this->assertStringContainsString('PVCbanen', $text);
        $this->assertStringContainsString('Ja·Nicon', $text);
        $this->assertStringContainsString('InmeterJansen', $text);
        $this->assertStringContainsString('KloppenburgInterieur', $text);
    }

    public function test_werkbon_without_a_measurement_form_keeps_drawings_and_omits_measurement_pages(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $seed = $this->seedConstructionJob();
        $relative = 'projects/'.$seed['project']->id.'/plattegrond/plan.png';
        Storage::disk('local')->put($relative, (string) file_get_contents(public_path('images/nicon-vloeren.png')));
        $seed['drawing']->forceFill([
            'original_filename' => 'plattegrond.png',
            'file_path' => $relative,
            'mime_type' => 'image/png',
        ])->save();

        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'document_ids' => [$seed['drawing']->id],
        ]);
        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertFalse((bool) $ticket->include_measurement_form);

        $html = view('work-tickets.pdf', app(WorkTicketPdfService::class)->build($ticket, false))->render();
        $this->assertStringContainsString('class="ticket-page"', $html);
        $this->assertStringContainsString('class="drawing-page"', $html);
        $this->assertStringNotContainsString('class="measurement-page"', $html);
        $this->assertStringNotContainsString('INMEETFORMULIER', $html);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Inmeetformulier beschikbaar');
    }

    public function test_werkbon_with_a_measurement_form_can_append_it_after_drawings(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['name' => 'Inmeter Jansen']);
        $pvc = $this->activity('pvc-banen');
        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'customer_name' => 'Bakker',
            'city' => 'Almelo',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'measurement' => [
                'meter_user_id' => $user->id,
                'rows' => [
                    ['room' => 'Slaapkamer', 'product' => 'PVC banen', 'quantity' => '18', 'unit' => WorkUnit::SquareMeter->value],
                ],
            ],
        ]))->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $relative = 'projects/'.$project->id.'/plattegrond/plan.png';
        Storage::disk('local')->put($relative, (string) file_get_contents(public_path('images/nicon-vloeren.png')));
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'bijlage',
            'original_filename' => 'plattegrond.png',
            'file_path' => $relative,
            'mime_type' => 'image/png',
            'file_size' => 800,
            'parse_status' => 'done',
        ]);
        $assignment = $this->assignWorker($project);
        $this->actingAs($user)->post(route('work-tickets.store', $assignment), [
            'general_work' => '1',
            'document_ids' => [$drawing->id],
            'include_measurement_form' => '1',
        ]);
        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertTrue($ticket->include_measurement_form);
        $this->assertSame(WorkTicketKind::Werkbon, $ticket->kind);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Inmeetformulier beschikbaar')
            ->assertSee('Inmeetformulier toevoegen aan PDF')
            ->assertSee('id="ticket-include-measurement"', false);

        $html = view(
            'work-tickets.pdf',
            app(WorkTicketPdfService::class)->build($ticket, false, includeMeasurementForm: true)
        )->render();
        $ticketPos = strpos($html, 'class="ticket-page"');
        $drawingPos = strpos($html, 'class="drawing-page"');
        $measurementPos = strpos($html, 'class="measurement-page"');
        $this->assertNotFalse($ticketPos);
        $this->assertNotFalse($drawingPos);
        $this->assertNotFalse($measurementPos);
        $this->assertGreaterThan($ticketPos, $drawingPos);
        $this->assertGreaterThan($drawingPos, $measurementPos);
        $this->assertStringContainsString('Slaapkamer', $html);
        $this->assertStringContainsString('INMEETFORMULIER', $html);
        $this->assertStringContainsString('data:image/png;base64,', substr($html, $drawingPos, $measurementPos - $drawingPos));

        $without = view(
            'work-tickets.pdf',
            app(WorkTicketPdfService::class)->build($ticket, false, includeMeasurementForm: false)
        )->render();
        $this->assertStringNotContainsString('class="measurement-page"', $without);
        $this->assertStringContainsString('class="drawing-page"', $without);

        $this->actingAs($user)
            ->get(route('work-tickets.pdf', ['workTicket' => $ticket, 'inmeetformulier' => 0]))
            ->assertOk();
    }

    public function test_rejects_a_product_that_is_not_checked_in_vloeren(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), $this->winkelPayload([
                'work_activity_ids' => [$pvc->id],
                'activity_quantities' => [$pvc->id => '100'],
                'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
                'measurement' => [
                    'rows' => [
                        ['room' => 'Kamer', 'product' => 'Marmoleum', 'quantity' => '10', 'unit' => WorkUnit::SquareMeter->value],
                    ],
                ],
            ]))
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors('measurement.rows.0.product');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_rejects_when_measurement_square_meters_exceed_the_shop_quantity(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), $this->winkelPayload([
                'work_activity_ids' => [$pvc->id],
                'activity_quantities' => [$pvc->id => '100'],
                'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
                'measurement' => [
                    'rows' => [
                        ['room' => 'Woonkamer', 'product' => 'PVC banen', 'quantity' => '80', 'unit' => WorkUnit::SquareMeter->value],
                        ['room' => 'Keuken', 'product' => 'PVC banen', 'quantity' => '20', 'unit' => WorkUnit::SquareMeter->value],
                        ['room' => 'Hal', 'product' => 'PVC banen', 'quantity' => '10', 'unit' => WorkUnit::SquareMeter->value],
                    ],
                ],
            ]))
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['measurement.rows' => 'Te veel ingevoerd. PVC banen: 100 m² beschikbaar, 110 m² reeds verdeeld.']);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('measurement_forms', 0);
    }

    public function test_rejects_overflow_even_when_row_units_are_empty(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), $this->winkelPayload([
                'work_activity_ids' => [$pvc->id],
                'activity_quantities' => [$pvc->id => '100'],
                'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
                'measurement' => [
                    'rows' => [
                        ['room' => 'Woonkamer', 'product' => 'PVC banen', 'quantity' => '80'],
                        ['room' => 'Keuken', 'product' => 'PVC banen', 'quantity' => '30'],
                    ],
                ],
            ]))
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['measurement.rows' => 'Te veel ingevoerd. PVC banen: 100 m² beschikbaar, 110 m² reeds verdeeld.']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_allows_measurement_rows_that_match_the_shop_square_meters(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'measurement' => [
                'rows' => [
                    ['room' => 'Woonkamer', 'product' => 'PVC banen', 'quantity' => '80', 'unit' => WorkUnit::SquareMeter->value],
                    ['room' => 'Keuken', 'product' => 'PVC banen', 'quantity' => '20', 'unit' => WorkUnit::SquareMeter->value],
                ],
            ],
        ]))->assertRedirect();

        $this->assertDatabaseCount('measurement_form_rows', 2);
        $this->assertEquals(100, MeasurementFormRow::query()->sum('quantity'));
    }

    public function test_clears_available_location_when_material_is_not_present(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), $this->winkelPayload([
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'measurement' => [
                'rows' => [
                    [
                        'room' => 'Kamer',
                        'product' => 'PVC banen',
                        'quantity' => '10',
                        'unit' => WorkUnit::SquareMeter->value,
                        'available_on_site' => '0',
                        'available_location' => 'klant',
                    ],
                ],
            ],
        ]))->assertRedirect();

        $row = MeasurementFormRow::query()->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->available_on_site);
        $this->assertNull($row->available_location);
    }

    public function test_guest_is_redirected_from_the_measurement_form_pdf(): void
    {
        $this->get(route('projects.winkel.measurement.pdf', 1))->assertRedirect(route('login'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function winkelPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$this->activity('screens')->id],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(): array
    {
        return [
            'room' => '',
            'product' => '',
            'brand' => '',
            'type' => '',
            'color_number' => '',
            'quantity' => '',
            'unit' => '',
            'underlay' => '',
            'skirting' => '',
            'steps' => '',
            'profile' => '',
            'available_on_site' => '0',
            'available_location' => '',
        ];
    }

    private function activity(string $slug): WorkActivity
    {
        return WorkActivity::query()->where('slug', $slug)->firstOrFail();
    }

    private function assignWorker(Project $project): WorkerAssignment
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $item = $project->workItems()->orderBy('id')->first();
        $this->assertNotNull($item);

        return WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
    }

    /**
     * @return array{
     *     assignment: WorkerAssignment,
     *     project: Project,
     *     floor: ProjectFloor,
     *     pvc: WorkItem,
     *     drawing: ProjectDocument
     * }
     */
    private function seedConstructionJob(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'status' => 'in_uitvoering',
        ]);
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
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 28,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $pvc->id,
            'ordered_quantity' => 28,
            'unit' => WorkUnit::SquareMeter,
            'status' => 'niet_gestart',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $pvc->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
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

        return [
            'assignment' => $assignment,
            'project' => $project,
            'floor' => $floor,
            'pvc' => $pvc,
            'drawing' => $drawing,
        ];
    }
}
