<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
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
use App\Models\WorkerRate;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WorkTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_create_redirects_to_login(): void
    {
        $this->get(route('work-tickets.create', 1))->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_open_werkbon_form(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('work-tickets.create', $seed['assignment']))
            ->assertForbidden();
    }

    public function test_planner_opens_drawing_board_in_ticket_mode_from_a_planned_assignment(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('work-tickets.create', $seed['assignment']))
            ->assertRedirect(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]));

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('Werkbon maken')
            ->assertSee('Bonselectie')
            ->assertSee('Materialen kiezen')
            ->assertSee('Ruimtes selecteren')
            ->assertSee('Selectie toevoegen')
            ->assertSee('Bon bekijken')
            ->assertSee('Werkbon opslaan')
            ->assertSee('1.63')
            ->assertSee('PVC')
            ->assertSee('Deze verdieping')
            ->assertSee('Hele werk')
            ->assertSee('Hele werk voor alle verdiepingen')
            ->assertDontSee('Werkzaamheden bijwerken')
            ->assertDontSee('id="complete-form"', false);
    }

    public function test_drawing_board_without_ticket_mode_does_not_show_bonselectie(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('projects.show', $seed['project']))
            ->assertOk()
            ->assertSee('Materialen kiezen')
            ->assertDontSee('Bonselectie')
            ->assertDontSee('Selectie toevoegen')
            ->assertDontSee('Werkbon opslaan')
            ->assertDontSee('Ruimtes selecteren');
    }

    public function test_uitvoerder_cannot_open_drawing_board_in_ticket_mode(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertForbidden();
    }

    public function test_planner_creates_werkbon_with_meetstaat_quantities_and_without_prices(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'rooms',
                        'area_ids' => $seed['areas']->pluck('id')->all(),
                    ],
                ],
                'work_item_ids' => [$seed['primer']->id, $seed['pvc']->id, $seed['plinten']->id],
                'document_ids' => [$seed['drawing']->id],
                'notes' => 'starten in oefenruimte',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(WorkTicketKind::Werkbon, $ticket->kind);
        $this->assertNull($ticket->billing_method);
        $this->assertSame((int) $seed['assignment']->id, (int) $ticket->worker_assignment_id);
        $this->assertSame((int) $seed['worker']->id, (int) $ticket->worker_id);
        $this->assertSame('starten in oefenruimte', $ticket->notes);
        $this->assertSame(3, $ticket->lines()->count());
        $this->assertSame(84.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['primer']->id)->quantity);
        $this->assertSame(84.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id)->quantity);
        $this->assertSame(62.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['plinten']->id)->quantity);
        $this->assertNull($ticket->lines->first()->unit_price);
        $this->assertTrue($ticket->areas()->whereKey($seed['areas']->pluck('id'))->count() === 3);
        $this->assertTrue($ticket->documents()->whereKey($seed['drawing']->id)->exists());

        $html = $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Werkbon')
            ->assertSee('WERKBON')
            ->assertSee('Nicon Vloeren')
            ->assertSee('images/nicon-vloeren.png', false)
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('1.63 oefenruimte')
            ->assertSee('Primen & egaliseren')
            ->assertSee('84,00 m²')
            ->assertSee('Plinten')
            ->assertSee('62,00 m¹')
            ->assertSee('fase 1 verdieping 1 (4/5).pdf')
            ->assertSee('starten in oefenruimte')
            ->assertSee('Kees Jansen')
            ->getContent();

        $this->assertStringNotContainsString('€', $html);
        $this->assertStringNotContainsString('12,50', $html);
    }

    public function test_entire_floor_with_one_material_uses_all_meetstaat_rooms(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'entire',
                    ],
                ],
                'work_item_ids' => [$seed['pvc']->id],
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertSame(3, $ticket->areas()->count());
        $this->assertTrue((bool) $ticket->floors()->first()->pivot->entire_floor);
        $this->assertSame(1, $ticket->lines()->count());
        $this->assertSame(84.0, (float) $ticket->lines->first()->quantity);
    }

    public function test_store_from_drawing_board_selections_uses_work_keys_and_meetstaat_quantities(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'selections' => [
                    [
                        'floor_id' => $seed['floor']->id,
                        'entire' => '0',
                        'area_ids' => $seed['areas']->pluck('id')->all(),
                        'work_keys' => ['vloer|'.$seed['pvc']->id, 'ondergrond'],
                    ],
                ],
                'document_ids' => [$seed['drawing']->id],
                'notes' => 'van het tekeningenbord',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(2, $ticket->lines()->count());
        $this->assertSame(84.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id)->quantity);
        $this->assertSame(84.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['primer']->id)->quantity);
        $this->assertNull($ticket->lines->firstWhere('work_item_id', $seed['plinten']->id));
        $this->assertSame('van het tekeningenbord', $ticket->notes);
        $this->assertTrue($ticket->documents()->whereKey($seed['drawing']->id)->exists());
    }

    public function test_selections_on_different_floors_keep_separate_meetstaat_quantities(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();
        $floorTwo = ProjectFloor::query()->create([
            'project_id' => $seed['project']->id,
            'name' => '2e verdieping',
            'sort_order' => 2,
        ]);
        $areaTwo = ProjectArea::query()->create([
            'project_id' => $seed['project']->id,
            'project_floor_id' => $floorTwo->id,
            'area_number' => '2.01',
            'name' => 'hal',
            'square_meters' => 40,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $areaTwo->id,
            'work_item_id' => $seed['primer']->id,
            'ordered_quantity' => 40,
            'unit' => WorkUnit::SquareMeter,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $areaTwo->id,
            'work_item_id' => $seed['pvc']->id,
            'ordered_quantity' => 40,
            'unit' => WorkUnit::SquareMeter,
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'selections' => [
                    [
                        'floor_id' => $seed['floor']->id,
                        'entire' => '0',
                        'area_ids' => [$seed['areas'][0]->id],
                        'work_keys' => ['vloer|'.$seed['pvc']->id],
                    ],
                    [
                        'floor_id' => $floorTwo->id,
                        'entire' => '1',
                        'work_keys' => ['ondergrond'],
                    ],
                ],
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertSame(28.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id)->quantity);
        $this->assertSame(40.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['primer']->id)->quantity);
        $this->assertSame(2, $ticket->areas()->count());
        $this->assertFalse((bool) $ticket->floors->firstWhere('id', $seed['floor']->id)->pivot->entire_floor);
        $this->assertTrue((bool) $ticket->floors->firstWhere('id', $floorTwo->id)->pivot->entire_floor);
    }

    public function test_zzp_opdrachtbon_stores_unit_prices_times_selected_quantity(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'rooms',
                        'area_ids' => $seed['areas']->pluck('id')->all(),
                    ],
                ],
                'work_item_ids' => [$seed['pvc']->id, $seed['plinten']->id],
                'billing_method' => 'unit',
                'unit_prices' => [
                    $seed['pvc']->id => '12,50',
                    $seed['plinten']->id => '8,00',
                ],
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertSame(WorkTicketKind::Opdrachtbon, $ticket->kind);
        $this->assertSame(WorkTicketBilling::Unit, $ticket->billing_method);
        $pvc = $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id);
        $this->assertSame(12.5, (float) $pvc->unit_price);
        $this->assertSame(1050.0, (float) $pvc->amount);
        $this->assertSame(496.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['plinten']->id)->amount);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('OPDRACHTBON')
            ->assertSee('Nicon Vloeren')
            ->assertSee('€ 12,50')
            ->assertSee('€ 1.050,00');
    }

    public function test_hourly_opdrachtbon_stores_rate_without_line_prices(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'entire',
                    ],
                ],
                'work_item_ids' => [$seed['pvc']->id],
                'billing_method' => 'hourly',
                'hourly_rate' => '50',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertSame(WorkTicketBilling::Hourly, $ticket->billing_method);
        $this->assertSame(50.0, (float) $ticket->hourly_rate);
        $this->assertNull($ticket->lines->first()->unit_price);
        $this->assertSame(84.0, (float) $ticket->lines->first()->quantity);

        $this->actingAs($user)
            ->from(route('work-tickets.show', $ticket))
            ->patch(route('work-tickets.hours.update', $ticket), ['worked_hours' => '16'])
            ->assertRedirect(route('work-tickets.show', $ticket));

        $this->assertSame(16.0, (float) $ticket->fresh()->worked_hours);
    }

    public function test_store_rejects_selection_without_meetstaat_quantity(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->from(route('work-tickets.create', $seed['assignment']))
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'rooms',
                        'area_ids' => [$seed['areas'][0]->id],
                    ],
                ],
                'work_item_ids' => [$seed['extra']->id],
            ])
            ->assertRedirect(route('work-tickets.create', $seed['assignment']))
            ->assertSessionHasErrors(['work_item_ids']);

        $this->assertSame(0, WorkTicket::query()->count());
    }

    public function test_eigen_vakman_opens_werkbon_without_prices(): void
    {
        $this->travelTo('2026-09-15 08:00:00');
        $planner = User::factory()->create();
        $seed = $this->seedJob();
        $this->actingAs($planner)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'rooms',
                    'area_ids' => $seed['areas']->pluck('id')->all(),
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
        ]);
        $ticket = WorkTicket::query()->first();
        $vakman = User::factory()->vakman($seed['worker']->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-15'))
            ->assertOk()
            ->assertSee(route('work-tickets.show', $ticket), false)
            ->assertSee('Werkbon');

        $html = $this->actingAs($vakman)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('1.63 oefenruimte')
            ->assertSee('PVC')
            ->assertSee('Kees Jansen')
            ->getContent();

        $this->assertStringNotContainsString('€', $html);
        $this->assertStringNotContainsString('12,50', $html);
    }

    public function test_zzp_vakman_sees_only_own_opdrachtbon_prices(): void
    {
        $planner = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $this->actingAs($planner)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'billing_method' => 'unit',
            'unit_prices' => [$seed['pvc']->id => '12.50'],
        ]);
        $ticket = WorkTicket::query()->first();
        $zzp = User::factory()->vakman($seed['worker']->id)->create();
        $other = User::factory()->vakman($seed['colleague']->id)->create();

        $this->actingAs($zzp)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('€ 12,50')
            ->assertSee('€ 1.050,00');

        $this->actingAs($other)
            ->get(route('work-tickets.show', $ticket))
            ->assertForbidden();
    }

    public function test_pdf_download_is_available_for_the_assigned_vakman(): void
    {
        $planner = User::factory()->create();
        $seed = $this->seedJob();
        $this->actingAs($planner)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'notes' => 'starten in oefenruimte',
        ]);
        $ticket = WorkTicket::query()->first();
        $vakman = User::factory()->vakman($seed['worker']->id)->create();

        $this->actingAs($vakman)
            ->get(route('work-tickets.pdf', $ticket))
            ->assertOk()
            ->assertSee('%PDF', false);
    }

    public function test_winkel_ticket_uses_kloppenburg_letterhead(): void
    {
        $planner = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $seed['project']->forceFill(['kind' => ProjectKind::Winkel])->save();
        $this->actingAs($planner)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'billing_method' => 'unit',
            'unit_prices' => [$seed['pvc']->id => '12.50'],
        ]);
        $ticket = WorkTicket::query()->first();

        $html = $this->actingAs($planner)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Kloppenburg Interieur')
            ->assertSee('OPDRACHTBON')
            ->assertSee('images/kloppenburg-interieur.png', false)
            ->assertSee('€ 12,50')
            ->getContent();

        $this->assertStringNotContainsString('Nicon Vloeren', $html);
        $this->assertStringNotContainsString('images/nicon-vloeren.png', $html);
    }

    /**
     * @return array{
     *     worker: Worker,
     *     colleague: Worker,
     *     assignment: WorkerAssignment,
     *     floor: ProjectFloor,
     *     areas: Collection<int, ProjectArea>,
     *     primer: WorkItem,
     *     pvc: WorkItem,
     *     plinten: WorkItem,
     *     extra: WorkItem,
     *     drawing: ProjectDocument
     * }
     */
    private function seedJob(bool $zzp = false): array
    {
        $worker = Worker::query()->create([
            'name' => $zzp ? 'Het Vloerenhuis' : 'Nick Seine',
            'employment_type' => $zzp ? 'zzp' : 'eigen',
            'company' => $zzp ? 'Het Vloerenhuis' : null,
            'active' => true,
        ]);
        $colleague = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'address' => 'Nieuweweg 1',
            'city' => 'Laren',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 1,
        ]);
        $rooms = [
            ['1.63', 'oefenruimte', 28],
            ['1.64', 'cabine', 28],
            ['1.65', 'cabine 3', 28],
        ];
        $areas = collect();
        foreach ($rooms as $index => $room) {
            $areas->push(ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $room[0],
                'name' => $room[1],
                'square_meters' => $room[2],
                'status' => 'niet_gestart',
                'sort_order' => $index + 1,
            ]));
        }
        $primer = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & egaliseren',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 84,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 84,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);
        $plinten = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten',
            'unit' => WorkUnit::LinearMeter,
            'ordered_quantity' => 62,
            'status' => 'gepland',
            'sort_order' => 3,
        ]);
        $extra = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tapijt',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 40,
            'status' => 'gepland',
            'sort_order' => 4,
        ]);
        $plintQty = [20, 20, 22];
        foreach ($areas as $index => $area) {
            foreach ([[$primer, 28, WorkUnit::SquareMeter], [$pvc, 28, WorkUnit::SquareMeter], [$plinten, $plintQty[$index], WorkUnit::LinearMeter]] as $task) {
                AreaTask::query()->create([
                    'project_area_id' => $area->id,
                    'work_item_id' => $task[0]->id,
                    'ordered_quantity' => $task[1],
                    'unit' => $task[2],
                    'status' => 'niet_gestart',
                ]);
            }
        }
        if ($zzp) {
            WorkerRate::query()->create([
                'worker_id' => $worker->id,
                'specialty' => 'pvc',
                'unit' => WorkUnit::SquareMeter,
                'unit_price' => 12.5,
            ]);
            WorkerRate::query()->create([
                'worker_id' => $worker->id,
                'specialty' => WorkerRate::HOURLY_SPECIALTY,
                'unit' => WorkUnit::Hours,
                'unit_price' => 50,
            ]);
        }
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $pvc->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $colleague->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        $drawing = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'fase 1 verdieping 1 (4/5).pdf',
            'file_path' => 'projects/'.$project->id.'/plattegrond/plan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'parse_status' => 'done',
        ]);

        return [
            'worker' => $worker,
            'colleague' => $colleague,
            'assignment' => $assignment,
            'floor' => $floor,
            'areas' => $areas,
            'primer' => $primer,
            'pvc' => $pvc,
            'plinten' => $plinten,
            'extra' => $extra,
            'drawing' => $drawing,
            'project' => $project,
        ];
    }
}
