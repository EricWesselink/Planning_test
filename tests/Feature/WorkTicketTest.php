<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkerRate;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Notifications\WorkTicketHoursSubmittedNotification;
use App\Services\ShopWorkService;
use App\Services\WorkTicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class WorkTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_create_redirects_to_login(): void
    {
        $this->get(route('work-tickets.create', 1))->assertRedirect(route('login'));
    }

    public function test_unauthenticated_delete_redirects_to_login(): void
    {
        $this->delete(route('work-tickets.destroy', 1))->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_open_werkbon_form(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('work-tickets.create', $seed['assignment']))
            ->assertForbidden();
    }

    public function test_planning_page_exposes_the_opdrachtbon_link_for_a_zzp_assignment(): void
    {
        $user = User::factory()->create();
        $this->seedJob(zzp: true);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14']))
            ->assertOk()
            ->assertSee('id="plan-ticket-link"', false)
            ->assertSee('data-ticket-url="'.url('/planning/assignments').'"', false)
            ->assertSee('data-ticket-label="Opdrachtbon maken"', false)
            ->assertDontSee('person-bar--ticket', false)
            ->assertDontSee('class="bar-ticket"', false);
    }

    public function test_planning_bar_shows_an_opdrachtbon_mark_when_the_assignment_has_one(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $ticket = WorkTicket::query()->create([
            'number' => 'OB-2026-0001',
            'kind' => WorkTicketKind::Opdrachtbon,
            'worker_assignment_id' => $seed['assignment']->id,
            'project_id' => $seed['project']->id,
            'worker_id' => $seed['worker']->id,
            'created_by' => $user->id,
            'billing_method' => WorkTicketBilling::Hourly,
            'hourly_rate' => 50,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14']))
            ->assertOk()
            ->assertSee('person-bar--ticket', false)
            ->assertSee('class="bar-ticket"', false)
            ->assertSee('>OB</span>', false)
            ->assertSee('Opdrachtbon OB-2026-0001')
            ->assertSee('data-ticket-existing="Opdrachtbon OB-2026-0001"', false)
            ->assertSee('data-ticket-show-url="'.route('work-tickets.show', $ticket).'"', false);
    }

    public function test_planning_bar_shows_a_werkbon_mark_when_the_assignment_has_one(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();
        WorkTicket::query()->create([
            'number' => 'WB-2026-0001',
            'kind' => WorkTicketKind::Werkbon,
            'worker_assignment_id' => $seed['assignment']->id,
            'project_id' => $seed['project']->id,
            'worker_id' => $seed['worker']->id,
            'created_by' => $user->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14']))
            ->assertOk()
            ->assertSee('person-bar--ticket', false)
            ->assertSee('>WB</span>', false)
            ->assertSee('Werkbon WB-2026-0001');
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
            ->assertSee('Algemeen werk')
            ->assertSee('Algemeen werk zonder ruimtes (nacalculatie)')
            ->assertSee('In de planning')
            ->assertSee('Tapijt')
            ->assertSee('data-ticket-extra value="'.$seed['extra']->id.'"', false)
            ->assertDontSee('Winkelwerk')
            ->assertDontSee('Screens')
            ->assertDontSee('Gordijnen')
            ->assertDontSee('data-ticket-shop-activity', false)
            ->assertDontSee('Werkzaamheden bijwerken')
            ->assertDontSee('id="complete-form"', false);
    }

    public function test_ticket_mode_collapses_project_info_so_the_bon_panel_stays_visible(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('is-ticket-mode', false)
            ->assertSee('id="ticket-panel"', false)
            ->assertSee('id="ticket-save"', false)
            ->assertSee('Werkbon maken');

        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.project-board.is-ticket-mode .board-project-info', $css);
        $this->assertStringContainsString('.project-board.is-ticket-mode.is-project-info-open .board-project-info', $css);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr)', $css);
    }

    public function test_projectleider_ticket_mode_keeps_the_drawing_and_omits_the_progress_form(): void
    {
        $user = User::factory()->projectleider()->create();
        $seed = $this->seedJob(zzp: true);

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('Opdrachtbon maken')
            ->assertSee('"pdf":true', false)
            ->assertSee('"canEnterProgress":true', false)
            ->assertDontSee('id="complete-form"', false)
            ->assertSee('id="draw-canvas"', false)
            ->assertSee('Materialen kiezen');
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
            ->assertSee('Manenbergring 9, 8271 RX IJsselmuiden')
            ->assertSee('Werkadres Nieuweweg 1, 1251 AA Laren')
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

    public function test_hourly_opdrachtbon_records_hours_per_workday(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('name="days[2026-09-14]"', false)
            ->assertSee('name="days[2026-09-18]"', false)
            ->assertDontSee('name="days[2026-09-19]"', false)
            ->assertSee('gepland 8u');

        $this->actingAs($user)
            ->from(route('work-tickets.show', $ticket))
            ->patch(route('work-tickets.hours.update', $ticket), [
                'days' => [
                    '2026-09-14' => '8',
                    '2026-09-15' => '8,5',
                    '2026-09-16' => '4',
                ],
            ])
            ->assertRedirect(route('work-tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(20.5, (float) $ticket->worked_hours);
        $this->assertSame([
            '2026-09-14' => 8.0,
            '2026-09-15' => 8.5,
            '2026-09-16' => 4.0,
        ], array_map(static fn (mixed $hours): float => (float) $hours, $ticket->day_hours));

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('€ 871,25');

        $seed['assignment']->end_date = '2026-09-19';
        $seed['assignment']->save();

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('name="days[2026-09-19]"', false);

        $seed['assignment']->include_saturday = true;
        $seed['assignment']->save();

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('name="days[2026-09-19]"', false);
    }

    public function test_hourly_opdrachtbon_rejects_negative_day_hours(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();

        $this->actingAs($user)
            ->from(route('work-tickets.show', $ticket))
            ->patch(route('work-tickets.hours.update', $ticket), [
                'days' => [
                    '2026-09-14' => '-1',
                ],
            ])
            ->assertRedirect(route('work-tickets.show', $ticket))
            ->assertSessionHasErrors([
                'days.2026-09-14' => 'Uren kunnen niet lager zijn dan 0.',
            ]);

        $this->assertNull($ticket->fresh()->worked_hours);
    }

    public function test_vakman_hours_notify_the_planner_to_make_a_billing_voucher(): void
    {
        Notification::fake();
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
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();
        $vakman = User::factory()->vakman($seed['worker']->id)->create();

        $this->actingAs($vakman)
            ->from(route('work-tickets.show', $ticket))
            ->patch(route('work-tickets.hours.update', $ticket), ['worked_hours' => '16'])
            ->assertRedirect(route('work-tickets.show', $ticket))
            ->assertSessionHas('status', 'Uren zijn teruggestuurd. De planner kan nu een bon maken om te factureren.');

        Notification::assertSentTo($planner, WorkTicketHoursSubmittedNotification::class);
        Notification::assertNotSentTo($vakman, WorkTicketHoursSubmittedNotification::class);

        $this->actingAs($planner)
            ->get(route('production.index', ['worker_id' => $seed['worker']->id, 'project_id' => $seed['project']->id]))
            ->assertOk()
            ->assertSee($ticket->number)
            ->assertSee('Uren teruggestuurd')
            ->assertSee('Bon maken');
    }

    public function test_updating_assignment_dates_updates_the_opdrachtbon_period(): void
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
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();
        $assignment = $seed['assignment'];
        $assignment->start_date = '2026-09-15';
        $assignment->end_date = '2026-09-17';
        $assignment->save();

        $ticket->refresh();
        $this->assertSame('2026-09-15', $ticket->start_date->toDateString());
        $this->assertSame('2026-09-17', $ticket->end_date->toDateString());
    }

    public function test_planner_deletes_an_opdrachtbon_from_production(): void
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
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();

        $this->actingAs($planner)
            ->from(route('production.index'))
            ->delete(route('work-tickets.destroy', $ticket))
            ->assertRedirect(route('production.index', [
                'worker_id' => $ticket->worker_id,
                'project_id' => $ticket->project_id,
            ]));

        $this->assertModelMissing($ticket);
    }

    public function test_uitvoerder_cannot_delete_an_opdrachtbon(): void
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
            'billing_method' => 'hourly',
            'hourly_rate' => '42.50',
        ]);
        $ticket = WorkTicket::query()->first();
        $uitvoerder = User::factory()->uitvoerder()->create();

        $this->actingAs($uitvoerder)
            ->delete(route('work-tickets.destroy', $ticket))
            ->assertForbidden();

        $this->assertModelExists($ticket);
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

    public function test_drawing_board_lists_extra_work_as_general_ticket_options(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();
        $this->extraWorkItem($seed['project']);

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('Algemeen werk')
            ->assertSee('vloer herstel')
            ->assertSee('4u · nacalculatie')
            ->assertSee('Zonder ruimtes te selecteren')
            ->assertDontSee('Algemeen werk zonder ruimtes (nacalculatie)');
    }

    public function test_opdrachtbon_saves_extra_work_without_rooms(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $extra = $this->extraWorkItem($seed['project']);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'extra_work_item_ids' => [$extra->id],
                'notes' => 'Vloeren aanhelen waar het nodig is',
                'billing_method' => 'hourly',
                'hourly_rate' => '42.50',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(WorkTicketKind::Opdrachtbon, $ticket->kind);
        $this->assertSame(WorkTicketBilling::Hourly, $ticket->billing_method);
        $this->assertSame(42.5, (float) $ticket->hourly_rate);
        $this->assertSame('Vloeren aanhelen waar het nodig is', $ticket->notes);
        $this->assertSame(0, $ticket->areas()->count());
        $this->assertSame(1, $ticket->lines()->count());
        $line = $ticket->lines->first();
        $this->assertSame((int) $extra->id, (int) $line->work_item_id);
        $this->assertSame(4.0, (float) $line->quantity);
        $this->assertSame(WorkUnit::Hours, $line->unit);
        $this->assertNull($line->unit_price);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('vloer herstel')
            ->assertSee('4,00 uren')
            ->assertSee('Vloeren aanhelen waar het nodig is');
    }

    public function test_opdrachtbon_rejects_a_work_activity_that_is_not_in_the_planning(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $screens = WorkActivity::query()->where('slug', 'screens')->firstOrFail();

        $this->actingAs($user)
            ->from(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->post(route('work-tickets.store', $seed['assignment']), [
                'shop_work_activity_ids' => [$screens->id],
                'notes' => 'Screens vanaf de winkel meenemen',
                'billing_method' => 'hourly',
                'hourly_rate' => '42.50',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('shop_work_activity_ids');

        $this->assertSame(0, WorkTicket::query()->count());
        $this->assertNull($seed['project']->workItems()->where('name', 'Screens')->first());
    }

    public function test_opdrachtbon_offers_a_planned_activity_and_keeps_it_after_planning_changes(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $activity = WorkActivity::query()->where('slug', 'vloer-aanhelen-herstel')->firstOrFail();
        $item = WorkItem::query()->create([
            'project_id' => $seed['project']->id,
            'work_activity_id' => $activity->id,
            'name' => $activity->name,
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => 6,
            'begrote_uren' => 6,
            'status' => 'gepland',
            'sort_order' => 30,
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('Vloer aanhelen / herstel')
            ->assertSee('data-ticket-extra value="'.$item->id.'"', false)
            ->assertDontSee('Screens')
            ->assertDontSee('Gordijnen');

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'extra_work_item_ids' => [$item->id],
                'notes' => 'Vloer aanhelen waar het nodig is',
                'billing_method' => 'hourly',
                'hourly_rate' => '42.50',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(1, $ticket->lines()->count());
        $this->assertSame((int) $item->id, (int) $ticket->lines->first()?->work_item_id);

        $item->update([
            'ordered_quantity' => 0,
            'begrote_uren' => null,
        ]);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Vloer aanhelen / herstel')
            ->assertSee('Vloer aanhelen waar het nodig is');

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $seed['project'],
                'bon' => $seed['assignment']->id,
            ]))
            ->assertOk()
            ->assertDontSee('data-ticket-extra value="'.$item->id.'"', false);
    }

    public function test_winkel_ticket_mode_lists_shop_activities(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $project = app(ShopWorkService::class)->create([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '12.5'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-18',
        ], $user);
        $worker = Worker::query()->create([
            'name' => 'Het Vloerenhuis',
            'employment_type' => 'zzp',
            'company' => 'Het Vloerenhuis',
            'active' => true,
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $project->workItems()->first()?->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', [
                'project' => $project,
                'bon' => $assignment->id,
            ]))
            ->assertOk()
            ->assertSee('Opdrachtbon maken')
            ->assertSee('werk uit de winkel')
            ->assertSee('PVC banen')
            ->assertSee('12,50 m²')
            ->assertSee('name="shop_work_activity_ids[]"', false)
            ->assertSee('Opdrachtbon opslaan')
            ->assertSee('Winkelwerk opslaan')
            ->assertDontSee('Bonselectie')
            ->assertDontSee('Ruimtes selecteren')
            ->assertDontSee('id="draw-canvas"', false);
    }

    public function test_winkel_page_offers_werkbon_maken_for_an_assigned_worker(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $project = app(ShopWorkService::class)->create([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '12.5'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-18',
        ], $user);
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $project->workItems()->first()?->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Werkbon maken')
            ->assertSee('werk uit de winkel')
            ->assertSee('Nick Seine')
            ->assertSee(route('projects.show', ['project' => $project, 'bon' => $assignment->id], false), false)
            ->assertDontSee('Opdrachtbon maken');
    }

    public function test_vakman_receives_the_winkel_werkbon(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-09-14 08:00:00');
        $planner = User::factory()->create();
        $pvc = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $project = app(ShopWorkService::class)->create([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '12.5'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-18',
        ], $planner);
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $project->workItems()->first()?->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($planner)
            ->post(route('work-tickets.store', $assignment), [
                'shop_work_activity_ids' => [$pvc->id],
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(WorkTicketKind::Werkbon, $ticket->kind);

        $vakman = User::factory()->vakman($worker->id)->create();

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-14'))
            ->assertOk()
            ->assertSee('PVC')
            ->assertSee('12,50')
            ->assertSee('Werkbon '.$ticket->number)
            ->assertSee(route('work-tickets.show', $ticket), false);
    }

    public function test_winkel_opdrachtbon_saves_the_selected_shop_activity(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $project = app(ShopWorkService::class)->create([
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '12.5'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-18',
        ], $user);
        $worker = Worker::query()->create([
            'name' => 'Het Vloerenhuis',
            'employment_type' => 'zzp',
            'company' => 'Het Vloerenhuis',
            'active' => true,
        ]);
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => WorkerRate::HOURLY_SPECIALTY,
            'unit' => WorkUnit::Hours,
            'unit_price' => 50,
        ]);
        $item = $project->workItems()->first();
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item?->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $assignment), [
                'shop_work_activity_ids' => [$pvc->id],
                'billing_method' => 'hourly',
                'hourly_rate' => '48.00',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(WorkTicketKind::Opdrachtbon, $ticket->kind);
        $this->assertSame(1, $ticket->lines()->count());
        $line = $ticket->lines->first();
        $this->assertSame((int) $item->id, (int) $line->work_item_id);
        $this->assertSame(12.5, (float) $line->quantity);
        $this->assertSame(WorkUnit::SquareMeter, $line->unit);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Kloppenburg Interieur')
            ->assertSee('PVC banen')
            ->assertSee('12,50 m²');
    }

    public function test_opdrachtbon_combines_extra_work_with_a_room_selection(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        $extra = $this->extraWorkItem($seed['project']);

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'floors' => [
                    $seed['floor']->id => [
                        'included' => '1',
                        'scope' => 'rooms',
                        'area_ids' => [$seed['areas'][0]->id],
                    ],
                ],
                'work_item_ids' => [$seed['pvc']->id],
                'extra_work_item_ids' => [$extra->id],
                'billing_method' => 'hourly',
                'hourly_rate' => '42.50',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertSame(1, $ticket->areas()->count());
        $this->assertTrue($ticket->areas()->whereKey($seed['areas'][0]->id)->exists());
        $this->assertSame(2, $ticket->lines()->count());
        $this->assertSame(28.0, (float) $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id)->quantity);
        $this->assertSame(WorkUnit::SquareMeter, $ticket->lines->firstWhere('work_item_id', $seed['pvc']->id)->unit);
        $this->assertSame(4.0, (float) $ticket->lines->firstWhere('work_item_id', $extra->id)->quantity);
        $this->assertSame(WorkUnit::Hours, $ticket->lines->firstWhere('work_item_id', $extra->id)->unit);
    }

    public function test_general_work_flag_saves_an_hourly_line_without_rooms(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->post(route('work-tickets.store', $seed['assignment']), [
                'general_work' => '1',
                'notes' => 'herstel op nacalculatie',
            ])
            ->assertRedirect();

        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame(0, $ticket->areas()->count());
        $this->assertSame(1, $ticket->lines()->count());
        $line = $ticket->lines->first();
        $this->assertSame((int) $seed['pvc']->id, (int) $line->work_item_id);
        $this->assertSame(40.0, (float) $line->quantity);
        $this->assertSame(WorkUnit::Hours, $line->unit);
        $this->assertSame('herstel op nacalculatie', $ticket->notes);
    }

    public function test_store_rejects_a_ticket_without_rooms_or_general_work(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)
            ->from(route('work-tickets.create', $seed['assignment']))
            ->post(route('work-tickets.store', $seed['assignment']), [
                'notes' => 'alleen een opmerking',
            ])
            ->assertRedirect(route('work-tickets.create', $seed['assignment']))
            ->assertSessionHasErrors([
                'selections' => 'Voeg minstens één selectie toe, of kies algemeen werk of winkelwerk.',
            ]);

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
            ->assertSee('class="drawing-page"', false)
            ->assertSee('snag-pdf', false);
    }

    public function test_pdf_shows_the_drawing_on_the_werkbon(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();
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
                    'scope' => 'rooms',
                    'area_ids' => $seed['areas']->pluck('id')->all(),
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'document_ids' => [$seed['drawing']->id],
        ]);
        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);

        $html = view('work-tickets.pdf', app(WorkTicketPdfService::class)->build($ticket, false))->render();
        $this->assertStringContainsString('class="ticket-page"', $html);
        $this->assertStringContainsString('Tekeningen', $html);
        $this->assertStringContainsString('plattegrond.png', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringNotContainsString('class="drawing-page"', $html);
        $this->assertStringNotContainsString('niconPrintTicket', $html);

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('class="map-drawing"', false)
            ->assertSee('plattegrond.png')
            ->assertDontSee('class="drawing-page"', false)
            ->assertSee('niconPrintTicket', false);

        $response = $this->actingAs($user)->get(route('work-tickets.pdf', $ticket));
        $response->assertOk();
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
    }

    public function test_pdf_print_view_keeps_a_pdf_plattegrond_on_a_following_page(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();

        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'rooms',
                    'area_ids' => $seed['areas']->pluck('id')->all(),
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'document_ids' => [$seed['drawing']->id],
        ]);
        $ticket = WorkTicket::query()->first();
        $this->assertNotNull($ticket);

        $this->actingAs($user)
            ->get(route('work-tickets.pdf', $ticket))
            ->assertOk()
            ->assertSee('class="ticket-page"', false)
            ->assertSee('class="drawing-page"', false)
            ->assertSee('Tekening laden')
            ->assertSee('data-print-when-ready="1"', false)
            ->assertSee('data-drawing-url="'.route('projects.documents.show', [$seed['project'], $seed['drawing']], false).'"', false)
            ->assertSee('snag-pdf', false)
            ->assertSee('niconPrintTicket', false)
            ->assertSee('@page { margin: 0', false)
            ->assertDontSee('%PDF', false);
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

    public function test_werkbon_shows_plattegrond_layer_with_room_pins(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob();
        foreach ($seed['areas'] as $index => $area) {
            AreaDrawingMarker::query()->create([
                'project_area_id' => $area->id,
                'project_document_id' => $seed['drawing']->id,
                'page' => 2,
                'x' => 0.20 + ($index * 0.15),
                'y' => 0.40,
                'width' => 0.10,
                'height' => 0.04,
                'label_text' => $area->label(),
                'source' => 'auto',
            ]);
        }

        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'rooms',
                    'area_ids' => $seed['areas']->pluck('id')->all(),
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'document_ids' => [$seed['drawing']->id],
        ]);
        $ticket = WorkTicket::query()->first();

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('data-page="2"', false)
            ->assertSee('Tekening laden')
            ->assertSee('class="room-pin"', false)
            ->assertSee('1.63')
            ->assertSee('1e verdieping')
            ->assertSee('data-drawing-url="'.route('projects.documents.show', [$seed['project'], $seed['drawing']], false).'"', false)
            ->assertSee('snag-pdf');
    }

    public function test_opdrachtbon_with_a_stored_plattegrond_opens(): void
    {
        $user = User::factory()->create();
        $seed = $this->seedJob(zzp: true);
        Storage::disk('local')->put(
            $seed['drawing']->file_path,
            SimplePdf::bytes('1e verdieping'),
        );

        $this->actingAs($user)->post(route('work-tickets.store', $seed['assignment']), [
            'floors' => [
                $seed['floor']->id => [
                    'included' => '1',
                    'scope' => 'rooms',
                    'area_ids' => $seed['areas']->pluck('id')->all(),
                ],
            ],
            'work_item_ids' => [$seed['pvc']->id],
            'document_ids' => [$seed['drawing']->id],
            'billing_method' => 'unit',
            'unit_prices' => [$seed['pvc']->id => '12.50'],
        ]);
        $ticket = WorkTicket::query()->first();

        $this->actingAs($user)
            ->get(route('work-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('OB-2026-0001')
            ->assertSee('Tekening laden');
    }

    private function extraWorkItem(Project $project, string $name = 'vloer herstel', float $hours = 4): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => $hours,
            'begrote_uren' => $hours,
            'status' => 'gepland',
            'sort_order' => 20,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
        ]);
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
            'postal_code' => '1251 AA',
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
