<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VoucherType;
use App\Mail\WorkerVoucherMail;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkerRate;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Models\WorkProgressEntry;
use App\Services\VoucherPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class VoucherTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_create_redirects_to_login(): void
    {
        $this->get(route('vouchers.create', [
            'worker_id' => 1,
            'project_id' => 1,
            'type' => 'facturatie',
        ]))->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_open_voucher_form(): void
    {
        $user = User::factory()->uitvoerder()->create();
        [$worker, $project] = $this->seedProduction();

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'facturatie',
            ]))
            ->assertForbidden();
    }

    public function test_production_page_blocks_bon_maken_without_an_opdrachtbon(): void
    {
        $user = User::factory()->create();
        [$worker, $project] = $this->seedProduction();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('Eerst opdrachtbon')
            ->assertSee($worker->displayName())
            ->assertSee($project->name)
            ->assertDontSee('Bon maken')
            ->assertDontSee('name="selected[]"', false)
            ->assertDontSee('al op bon')
            ->assertDontSee('Volledig afgerekend');
    }

    public function test_uitvoerder_does_not_see_invoice_checkboxes(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $this->seedProduction();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertDontSee('name="selected[]"', false)
            ->assertDontSee('Bon maken');
    }

    public function test_bon_maken_without_quantities_is_rejected(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);

        $this->actingAs($user)
            ->from(route('production.index'))
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'facturatie',
                'lines' => [[
                    'project_area_id' => $area->id,
                    'work_item_id' => $item->id,
                    'description' => 'Primen & Egaliseren',
                    'quantity' => '',
                    'unit' => 'm2',
                    'unit_price' => '5.50',
                ]],
            ])
            ->assertRedirect(route('production.index'))
            ->assertSessionHasErrors(['lines']);

        $this->assertSame(0, Voucher::query()->where('type', VoucherType::Facturatie)->count());
    }

    public function test_sheet_only_lists_commissioned_rooms(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $otherArea = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $area->project_floor_id,
            'area_number' => '0.03',
            'name' => 'groepsruimte',
            'square_meters' => 51.16,
            'status' => 'in_uitvoering',
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'project_area_id' => $otherArea->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'completed_quantity' => 51.16,
            'unit' => 'm2',
            'worked_hours' => 8,
        ]);
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '50.25');

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('0.02 groepsruimte')
            ->assertSee('50,25')
            ->assertSee('klaar 50,25 m²')
            ->assertSee('0.03 groepsruimte')
            ->assertSee('51,16')
            ->assertSee('Klaar – nog niet in opdracht')
            ->assertSee('Toevoegen aan opdracht')
            ->assertDontSee('name="lines[1][quantity]"', false)
            ->assertDontSee('Klaar, nieuw onderdeel');
    }

    public function test_extra_completed_work_stays_off_the_bon_until_added_to_the_opdracht(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'linoleum',
            'unit' => 'm2',
            'unit_price' => 12.00,
        ]);
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'plinten',
            'unit' => 'm1',
            'unit_price' => 8.00,
        ]);
        $marmoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 50.25,
            'status' => 'in_uitvoering',
            'sort_order' => 2,
        ]);
        $plinten = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'unit' => 'm1',
            'ordered_quantity' => 23.17,
            'status' => 'in_uitvoering',
            'sort_order' => 3,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $marmoleum->id,
            'project_area_id' => $area->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-03',
            'completed_quantity' => 50.25,
            'unit' => 'm2',
            'worked_hours' => 4,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $plinten->id,
            'project_area_id' => $area->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-03',
            'completed_quantity' => 23.17,
            'unit' => 'm1',
            'worked_hours' => 2,
        ]);
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '50.25');
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '35'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('2e bon')
            ->assertSee('Klaar – nog niet in opdracht')
            ->assertSee('Marmoleum Real')
            ->assertSee('Plinten wit')
            ->assertSee('Toevoegen aan opdracht')
            ->assertSee('placeholder="max. 15,25"', false)
            ->assertDontSee('name="lines[1][quantity]"', false)
            ->assertDontSee('Klaar, nieuw onderdeel');

        $opdracht = Voucher::query()->where('type', VoucherType::Opdracht)->first();

        $this->actingAs($user)
            ->get(route('vouchers.edit', [
                'voucher' => $opdracht,
                'area' => $area->id,
                'item' => $marmoleum->id,
                'quantity' => '50.25',
            ]))
            ->assertOk()
            ->assertSee('Marmoleum')
            ->assertSee('Primen')
            ->assertSee('name="lines[1][quantity]"', false);

        $this->actingAs($user)
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => VoucherType::Facturatie->value,
                'lines' => [[
                    'project_area_id' => $area->id,
                    'work_item_id' => $marmoleum->id,
                    'description' => '0.02 groepsruimte: Marmoleum Real, 3120 rosato, Linoleum',
                    'quantity' => '50.25',
                    'unit' => 'm2',
                    'unit_price' => '12.00',
                ]],
            ])
            ->assertSessionHasErrors(['lines.0.description']);

        $this->assertSame(1, $opdracht->fresh()->lines()->count());
        $this->assertSame(1, Voucher::query()->where('type', VoucherType::Facturatie)->count());

        $this->actingAs($user)
            ->patch(route('vouchers.update', $opdracht), [
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'description' => $opdracht->lines()->first()->description,
                        'quantity' => '50.25',
                        'unit' => 'm2',
                        'unit_price' => '5.50',
                    ],
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $marmoleum->id,
                        'description' => '0.02 groepsruimte: Marmoleum Real, 3120 rosato, Linoleum',
                        'quantity' => '50.25',
                        'unit' => 'm2',
                        'unit_price' => '12.00',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $opdracht->fresh()->lines()->count());
        $this->assertSame('12.00', $opdracht->fresh()->lines()->where('work_item_id', $marmoleum->id)->value('unit_price'));
    }

    public function test_invoiced_material_shows_as_already_on_a_bon(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '50.25');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Volledig afgerekend')
            ->assertSee('Totaal ontvangen')
            ->assertSee('276,38')
            ->assertDontSee('Bon maken');

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'facturatie',
            ]))
            ->assertRedirect(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]));
    }

    public function test_production_shows_opdrachtbon_versus_invoiced_totals(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300'))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '50.25'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Totaal opdracht')
            ->assertSee('1.650,00')
            ->assertSee('Totaal ontvangen')
            ->assertSee('276,38')
            ->assertSee('Nog te ontvangen')
            ->assertSee('1.373,62')
            ->assertSee('1e bon');
    }

    public function test_exceeding_the_opdrachtbon_is_rejected(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '6.00', '20');

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('max. 20,00')
            ->assertDontSee('overschrijdt opdrachtbon');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '6.00'))
            ->assertSessionHasErrors(['lines.0.quantity']);

        $this->assertSame(0, Voucher::query()->where('type', VoucherType::Facturatie)->count());
    }

    public function test_create_form_prefills_the_agreed_worker_rate(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->seedProduction();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 4.50,
        ]);

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'opdracht',
            ]))
            ->assertOk()
            ->assertSee('Opdrachtbon')
            ->assertSee('Prijssoort')
            ->assertSee('Prijs per eenheid')
            ->assertSee('Vaste afgesproken prijs')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('4.5')
            ->assertSee('Afgesproken prijs');

        $this->assertSame('primen_egaliseren', $item->specialtyKey());
    }

    public function test_storing_a_facturatiebon_persists_lines_and_total(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '50.25');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '12,50'))
            ->assertRedirect();

        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();
        $this->assertNotNull($voucher);
        $this->assertSame(VoucherType::Facturatie, $voucher->type);
        $this->assertSame('BON-'.now()->year.'-0002', $voucher->number);
        $this->assertSame('276.38', $voucher->total_amount);
        $this->assertSame(1, $voucher->lines()->count());
        $this->assertSame('0.02 groepsruimte · Primen & Egaliseren', $voucher->lines()->first()->description);
        $this->assertSame('50.25', $voucher->lines()->first()->quantity);
        $this->assertSame('5.50', $voucher->lines()->first()->unit_price);
    }

    public function test_manual_price_overrides_the_agreed_rate(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 4.50,
        ]);
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '9.00', VoucherType::Opdracht, '50.25'))
            ->assertRedirect();

        $line = Voucher::query()->where('type', VoucherType::Opdracht)->first()->lines()->first();
        $this->assertSame('9.00', $line->unit_price);
        $this->assertSame('manual', $line->price_source->value);
        $this->assertSame('452.25', $line->voucher->total_amount);
    }

    public function test_facturatiebon_uses_prices_from_the_opdrachtbon(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '6.00', VoucherType::Opdracht))
            ->assertRedirect();

        $opdracht = Voucher::query()->first();
        $this->assertSame(VoucherType::Opdracht, $opdracht->type);

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('6,00')
            ->assertSee('1e bon');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '6.00'))
            ->assertRedirect();

        $bon = Voucher::query()->where('type', VoucherType::Facturatie)->first();
        $this->assertSame($opdracht->id, $bon->parent_id);
        $this->assertSame('6.00', $bon->lines()->first()->unit_price);
        $this->assertSame('voucher', $bon->lines()->first()->price_source->value);
    }

    public function test_cannot_invoice_more_than_the_remaining_quantity(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '300');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '10.00', VoucherType::Facturatie, '400'))
            ->assertSessionHasErrors(['lines.0.quantity']);

        $this->assertSame(0, Voucher::query()->where('type', VoucherType::Facturatie)->count());
    }

    public function test_second_bon_only_offers_the_remaining_quantity(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '300');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '100'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('1e bon')
            ->assertSee('2e bon')
            ->assertSee('max. 200,00');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '200'))
            ->assertRedirect();

        $this->assertSame(2, Voucher::query()->where('type', VoucherType::Facturatie)->count());
        $this->assertSame('1650.00', number_format((float) Voucher::query()->where('type', VoucherType::Facturatie)->sum('total_amount'), 2, '.', ''));

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Volledig afgerekend')
            ->assertDontSee('Bon maken');
    }

    public function test_second_bon_can_use_less_than_the_remaining_quantity(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '5.50', '50.25');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '35'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('placeholder="max. 15,25"', false)
            ->assertSee('name="lines[0][quantity]" value=""', false);

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '10'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('1e bon')
            ->assertSee('2e bon')
            ->assertSee('3e bon')
            ->assertSee('45,00')
            ->assertSee('5,25')
            ->assertSee('placeholder="max. 5,25"', false)
            ->assertSee('Bon maken');
    }

    public function test_opdrachtbon_stores_a_fixed_price_kind(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $payload = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300');
        $payload['lines'][0]['price_kind'] = 'fixed';
        $payload['lines'][0]['amount'] = '1650';

        $this->actingAs($user)
            ->post(route('vouchers.store'), $payload)
            ->assertRedirect();

        $line = Voucher::query()->where('type', VoucherType::Opdracht)->first()->lines()->first();
        $this->assertSame('fixed', $line->price_kind->value);
        $this->assertSame('300.00', $line->quantity);
        $this->assertSame('1650.00', $line->amount);
    }

    public function test_fixed_price_can_be_split_across_bons(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $opdracht = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300');
        $opdracht['lines'][0]['price_kind'] = 'fixed';
        $opdracht['lines'][0]['amount'] = '1650';
        $this->actingAs($user)->post(route('vouchers.store'), $opdracht)->assertRedirect();

        $first = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '100');
        $first['lines'][0]['price_kind'] = 'fixed';
        $first['lines'][0]['amount'] = '550';
        $this->actingAs($user)->post(route('vouchers.store'), $first)->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertSee('1e bon')
            ->assertSee('2e bon')
            ->assertSee('max. 200,00')
            ->assertSee('1.100,00')
            ->assertDontSee('Volledig afgerekend');

        $second = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '200');
        $second['lines'][0]['price_kind'] = 'fixed';
        $second['lines'][0]['amount'] = '1100';
        $this->actingAs($user)->post(route('vouchers.store'), $second)->assertRedirect();

        $this->assertSame(2, Voucher::query()->where('type', VoucherType::Facturatie)->count());
        $this->assertSame('1650.00', number_format((float) Voucher::query()->where('type', VoucherType::Facturatie)->sum('total_amount'), 2, '.', ''));

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertSee('Volledig afgerekend')
            ->assertDontSee('Bon maken');
    }

    public function test_line_stays_open_until_quantity_and_amount_are_used(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $opdracht = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300');
        $opdracht['lines'][0]['price_kind'] = 'fixed';
        $opdracht['lines'][0]['amount'] = '1650';
        $this->actingAs($user)->post(route('vouchers.store'), $opdracht)->assertRedirect();

        $first = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '300');
        $first['lines'][0]['price_kind'] = 'fixed';
        $first['lines'][0]['amount'] = '800';
        $this->actingAs($user)->post(route('vouchers.store'), $first)->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertSee('2e bon')
            ->assertSee('850,00')
            ->assertSee('Bon maken')
            ->assertDontSee('Volledig afgerekend');
    }

    public function test_cannot_invoice_more_than_the_remaining_fixed_amount(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $opdracht = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300');
        $opdracht['lines'][0]['price_kind'] = 'fixed';
        $opdracht['lines'][0]['amount'] = '1650';
        $this->actingAs($user)->post(route('vouchers.store'), $opdracht)->assertRedirect();

        $over = $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '100');
        $over['lines'][0]['price_kind'] = 'fixed';
        $over['lines'][0]['amount'] = '2000';

        $this->actingAs($user)
            ->post(route('vouchers.store'), $over)
            ->assertSessionHasErrors(['lines.0.amount']);

        $this->assertSame(0, Voucher::query()->where('type', VoucherType::Facturatie)->count());
    }

    public function test_print_page_shows_worker_project_and_total(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '8.00', '50.25');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '8.00'))
            ->assertRedirect();

        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();

        $this->actingAs($user)
            ->get(route('vouchers.show', $voucher))
            ->assertOk()
            ->assertSee($voucher->number)
            ->assertSee('ZZP Harm Wesselink')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('402,00')
            ->assertSee('Manenbergring 9')
            ->assertSee('8271 RX IJsselmuiden')
            ->assertSee('info@niconvloeren.nl')
            ->assertSee('038 303 12 20')
            ->assertSee('images/nicon-vloeren.png', false)
            ->assertSee('alt="Nicon Vloeren"', false)
            ->assertSee('Vul eerst het e-mailadres van de vakman in')
            ->assertSee('Download PDF')
            ->assertDontSee('Verstuur naar vakman');
    }

    public function test_escapes_notes_on_the_print_page(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '8.00', '50.25');
        $payload = $this->payload($worker, $project, $item, $area, '8.00');
        $payload['notes'] = '<script>alert("xss")</script>';

        $this->actingAs($user)->post(route('vouchers.store'), $payload)->assertRedirect();

        $this->actingAs($user)
            ->get(route('vouchers.show', Voucher::query()->where('type', VoucherType::Facturatie)->first()))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_missing_unit_price_is_rejected(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $payload = $this->payload($worker, $project, $item, $area, '', VoucherType::Opdracht, '50.25');
        unset($payload['lines'][0]['unit_price']);

        $this->actingAs($user)
            ->from(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'opdracht',
            ]))
            ->post(route('vouchers.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors(['lines.0.unit_price']);
    }

    public function test_limited_user_cannot_create_a_voucher_for_another_project(): void
    {
        [$worker, $project, $item, $area] = $this->seedProduction();
        $other = Project::query()->create([
            'project_number' => '260200099',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($other);

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '8.00'))
            ->assertForbidden();

        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_unauthenticated_edit_redirects_to_login(): void
    {
        $this->get('/productie/bonnen/1/aanpassen')->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_edit_a_voucher(): void
    {
        $planner = User::factory()->create();
        $uitvoerder = User::factory()->uitvoerder()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($planner)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300'))
            ->assertRedirect();

        $voucher = Voucher::query()->first();

        $this->actingAs($uitvoerder)
            ->get(route('vouchers.edit', $voucher))
            ->assertForbidden();

        $this->actingAs($uitvoerder)
            ->patch(route('vouchers.update', $voucher), [
                'lines' => [[
                    'description' => 'Primen & Egaliseren',
                    'quantity' => '10',
                    'unit' => 'm2',
                    'unit_price' => '5.50',
                ]],
            ])
            ->assertForbidden();

        $this->assertSame('1650.00', $voucher->fresh()->total_amount);
    }

    public function test_unauthenticated_send_redirects_to_login(): void
    {
        $this->post('/productie/bonnen/1/mail')->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_mail_a_voucher(): void
    {
        Mail::fake();
        $planner = User::factory()->create();
        $uitvoerder = User::factory()->uitvoerder()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $worker->forceFill(['email' => 'harm@example.test'])->save();
        $this->storeOpdracht($planner, $worker, $project, $item, $area);
        $this->actingAs($planner)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '50'))
            ->assertRedirect();
        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();

        $this->actingAs($uitvoerder)
            ->post(route('vouchers.send', $voucher))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_mailing_a_bon_requires_a_worker_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '50'))
            ->assertRedirect();
        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();

        $this->actingAs($user)
            ->from(route('vouchers.show', $voucher))
            ->post(route('vouchers.send', $voucher))
            ->assertRedirect(route('vouchers.show', $voucher))
            ->assertSessionHasErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_show_page_has_send_button_when_worker_has_email(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $worker->forceFill(['email' => 'harm@example.test'])->save();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '50'))
            ->assertRedirect();
        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();

        $this->actingAs($user)
            ->get(route('vouchers.show', $voucher))
            ->assertSee('Verstuur naar vakman')
            ->assertDontSee('Vul eerst het e-mailadres van de vakman in');
    }

    public function test_mailing_a_bon_sends_it_to_the_worker(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $worker->forceFill(['email' => 'harm@example.test'])->save();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '50'))
            ->assertRedirect();
        $voucher = Voucher::query()->where('type', VoucherType::Facturatie)->first();

        $this->actingAs($user)
            ->post(route('vouchers.send', $voucher))
            ->assertRedirect(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]))
            ->assertSessionHas('status', function (string $status): bool {
                return str_contains($status, '2e bon');
            });

        Mail::assertSent(WorkerVoucherMail::class, function (WorkerVoucherMail $mail) use ($voucher): bool {
            return $mail->voucher->is($voucher) && $mail->hasTo('harm@example.test');
        });

        $this->actingAs($user)
            ->get(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertSee('2e bon')
            ->assertSee('Nog te ontvangen')
            ->assertSee('Verstuur');
    }

    public function test_edit_form_shows_existing_lines_and_add_button(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300'))
            ->assertRedirect();

        $voucher = Voucher::query()->first();

        $this->actingAs($user)
            ->get(route('vouchers.edit', $voucher))
            ->assertOk()
            ->assertSee('Onderdeel toevoegen')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('300')
            ->assertSee('5.5');
    }

    public function test_opdrachtbon_can_change_quantity_and_add_an_extra_onderdeel(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300'))
            ->assertRedirect();

        $voucher = Voucher::query()->first();

        $this->actingAs($user)
            ->patch(route('vouchers.update', $voucher), [
                'notes' => 'Meerwerk plinten meegenomen',
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'description' => '0.02 groepsruimte · Primen & Egaliseren',
                        'quantity' => '250',
                        'unit' => 'm2',
                        'unit_price' => '5,50',
                    ],
                    [
                        'description' => 'Plinten wit',
                        'quantity' => '40',
                        'unit' => 'm1',
                        'unit_price' => '8.00',
                    ],
                ],
            ])
            ->assertRedirect(route('vouchers.show', $voucher));

        $voucher->refresh()->load('lines');
        $this->assertSame('1695.00', $voucher->total_amount);
        $this->assertSame(2, $voucher->lines->count());
        $this->assertSame('250.00', $voucher->lines[0]->quantity);
        $this->assertSame('Plinten wit', $voucher->lines[1]->description);
        $this->assertSame('8.00', $voucher->lines[1]->unit_price);
        $this->assertSame('Meerwerk plinten meegenomen', $voucher->notes);

        $this->actingAs($user)
            ->get(route('vouchers.show', $voucher))
            ->assertOk()
            ->assertSee('Aanpassen')
            ->assertSee('Plinten wit')
            ->assertSee('1.695,00');
    }

    public function test_blank_extra_line_is_dropped_on_update(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '300'))
            ->assertRedirect();

        $voucher = Voucher::query()->first();

        $this->actingAs($user)
            ->patch(route('vouchers.update', $voucher), [
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'description' => '0.02 groepsruimte · Primen & Egaliseren',
                        'quantity' => '300',
                        'unit' => 'm2',
                        'unit_price' => '5.50',
                    ],
                    [
                        'description' => '',
                        'quantity' => '',
                        'unit' => 'm2',
                        'unit_price' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('vouchers.show', $voucher))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $voucher->fresh()->lines()->count());
        $this->assertSame('1650.00', $voucher->fresh()->total_amount);
    }

    public function test_work_order_unit_price_is_used_when_no_rate_exists(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'assignment_type' => 'work_item',
            'unit' => 'm2',
            'unit_price' => 7.25,
            'status' => 'gepland',
        ]);

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'opdracht',
            ]))
            ->assertOk()
            ->assertSee('Van opdracht')
            ->assertSee('7.25');
    }

    #[DataProvider('createRoles')]
    public function test_voucher_create_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$worker, $project] = $this->seedProduction();

        $response = $this->actingAs($user)->get(route('vouchers.create', [
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'type' => 'opdracht',
        ]));

        if ($allowed) {
            $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }

    public function test_facturatie_without_opdrachtbon_is_blocked(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'facturatie',
            ]))
            ->assertRedirect(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]))
            ->assertSessionHasErrors(['type']);

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50'))
            ->assertSessionHasErrors(['type']);

        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_production_shows_bon_maken_after_an_opdrachtbon(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Bon maken')
            ->assertSee('Huidige bon')
            ->assertSee('name="lines[0][quantity]" value=""', false)
            ->assertSee('placeholder="max. 300,00"', false)
            ->assertSee('1e bon')
            ->assertSee('Nog te ontvangen')
            ->assertSee('300,00')
            ->assertDontSee('Eerst opdrachtbon')
            ->assertDontSee('name="selected[]"', false);
    }

    public function test_sheet_shows_each_bon_as_the_next_column(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '3.00', '200');

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '3.00', VoucherType::Facturatie, '100'))
            ->assertRedirect(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]))
            ->assertSessionHas('status', function (string $status): bool {
                return str_contains($status, '2e bon') && str_contains($status, '100,00 m²');
            });

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '3.00', VoucherType::Facturatie, '50'))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '3.00', VoucherType::Facturatie, '30'))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Onderaannemer')
            ->assertSee('Totaal opdracht')
            ->assertSee('600,00')
            ->assertSee('1e bon')
            ->assertSee('2e bon')
            ->assertSee('3e bon')
            ->assertSee('4e bon')
            ->assertSee('Totaal ontvangen')
            ->assertSee('180,00')
            ->assertSee('540,00')
            ->assertSee('Nog te ontvangen')
            ->assertSee('20,00')
            ->assertSee('60,00')
            ->assertSee('Bon maken');
    }

    public function test_cannot_invoice_an_item_that_is_not_on_the_opdrachtbon(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $other = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'unit' => 'm1',
            'ordered_quantity' => 40,
            'status' => 'in_uitvoering',
            'sort_order' => 2,
        ]);
        $payload = $this->payload($worker, $project, $other, $area, '8.00', VoucherType::Facturatie, '40');
        $payload['lines'][0]['description'] = 'Plinten wit';
        $payload['lines'][0]['unit'] = 'm1';

        $this->actingAs($user)
            ->post(route('vouchers.store'), $payload)
            ->assertSessionHasErrors(['lines.0.description']);

        $this->assertSame(0, Voucher::query()->where('type', VoucherType::Facturatie)->count());
    }

    public function test_cannot_reduce_opdracht_below_already_invoiced_quantity(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);

        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Facturatie, '100'))
            ->assertRedirect();

        $opdracht = Voucher::query()->where('type', VoucherType::Opdracht)->first();

        $this->actingAs($user)
            ->patch(route('vouchers.update', $opdracht), [
                'lines' => [[
                    'project_area_id' => $area->id,
                    'work_item_id' => $item->id,
                    'description' => '0.02 groepsruimte · Primen & Egaliseren',
                    'quantity' => '50',
                    'unit' => 'm2',
                    'unit_price' => '5.50',
                ]],
            ])
            ->assertSessionHasErrors(['lines.0.quantity']);

        $this->assertSame('1650.00', $opdracht->fresh()->total_amount);
    }

    public function test_extra_opdracht_line_can_be_invoiced(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $opdracht = Voucher::query()->where('type', VoucherType::Opdracht)->first();

        $this->actingAs($user)
            ->patch(route('vouchers.update', $opdracht), [
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'description' => '0.02 groepsruimte · Primen & Egaliseren',
                        'quantity' => '300',
                        'unit' => 'm2',
                        'unit_price' => '5.50',
                    ],
                    [
                        'description' => 'Plinten wit',
                        'quantity' => '40',
                        'unit' => 'm1',
                        'unit_price' => '8.00',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Plinten wit')
            ->assertSee('Bon maken')
            ->assertSee('1e bon');

        $this->actingAs($user)
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => VoucherType::Facturatie->value,
                'lines' => [[
                    'description' => 'Plinten wit',
                    'quantity' => '40',
                    'unit' => 'm1',
                    'unit_price' => '8.00',
                ]],
            ])
            ->assertRedirect();

        $bon = Voucher::query()->where('type', VoucherType::Facturatie)->first();
        $this->assertSame('320.00', $bon->total_amount);
        $this->assertSame('Plinten wit', $bon->lines()->first()->description);
    }

    public function test_create_form_groups_rooms_under_one_activity_price(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->addCompletedRoom($project, $area, $item, $worker, '0.03', 'groepsruimte', 51.16);
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 2.00,
        ]);

        $this->actingAs($user)
            ->get(route('vouchers.create', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => 'opdracht',
            ]))
            ->assertOk()
            ->assertSee('Primen & Egaliseren')
            ->assertSee('0.02 groepsruimte')
            ->assertSee('0.03 groepsruimte')
            ->assertSee('101,41')
            ->assertSee('name="activity_prices['.$item->id.'][m2][unit_price]"', false)
            ->assertSee('name="lines[0][room_label]"', false)
            ->assertSee('name="lines[1][room_label]"', false)
            ->assertDontSee('<details', false);
    }

    public function test_opdrachtbon_applies_one_agreed_price_to_every_room_of_the_activity(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $other = $this->addCompletedRoom($project, $area, $item, $worker, '0.03', 'groepsruimte', 51.16);

        $this->actingAs($user)
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => VoucherType::Opdracht->value,
                'activity_prices' => [
                    $item->id => [
                        'm2' => [
                            'description' => 'Primen & Egaliseren',
                            'price_kind' => 'unit',
                            'unit_price' => '2.00',
                            'unit' => 'm2',
                        ],
                    ],
                ],
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'room_label' => '0.02 groepsruimte',
                        'description' => 'Primen & Egaliseren',
                        'quantity' => '50.25',
                        'unit' => 'm2',
                        'unit_price' => '9.00',
                    ],
                    [
                        'project_area_id' => $other->id,
                        'work_item_id' => $item->id,
                        'room_label' => '0.03 groepsruimte',
                        'description' => 'Primen & Egaliseren',
                        'quantity' => '51.16',
                        'unit' => 'm2',
                        'unit_price' => '9.00',
                    ],
                ],
            ])
            ->assertRedirect();

        $voucher = Voucher::query()->where('type', VoucherType::Opdracht)->first();
        $this->assertNotNull($voucher);
        $this->assertSame('202.82', $voucher->total_amount);
        $this->assertSame(2, $voucher->lines()->count());
        $this->assertSame(['2.00', '2.00'], $voucher->lines()->orderBy('id')->pluck('unit_price')->all());
        $this->assertSame('0.02 groepsruimte · Primen & Egaliseren', $voucher->lines()->orderBy('id')->first()->description);

        $this->actingAs($user)
            ->get(route('vouchers.show', $voucher))
            ->assertOk()
            ->assertSee('Primen & Egaliseren')
            ->assertSee('101,41 m²')
            ->assertSee('€ 2,00/m²')
            ->assertSee('€ 202,82')
            ->assertSee('0.02 groepsruimte')
            ->assertSee('0.03 groepsruimte')
            ->assertSee('50,25 m²')
            ->assertSee('51,16 m²')
            ->assertDontSee('€ 100,50')
            ->assertDontSee('<details', false);
    }

    public function test_unauthenticated_pdf_redirects_to_login(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $voucher = Voucher::query()->first();
        $this->assertNotNull($voucher);

        $this->app['auth']->forgetGuards();

        $this->get(route('vouchers.pdf', $voucher))->assertRedirect(route('login'));
    }

    public function test_storing_an_opdrachtbon_opens_the_pdf(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();

        $response = $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, '5.50', VoucherType::Opdracht, '50.25'));

        $voucher = Voucher::query()->where('type', VoucherType::Opdracht)->first();
        $this->assertNotNull($voucher);
        $response->assertRedirect(route('vouchers.pdf', $voucher));
    }

    public function test_pdf_lists_rooms_under_the_activity_without_per_room_prices(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $other = $this->addCompletedRoom($project, $area, $item, $worker, '0.03', 'groepsruimte', 51.16);

        $this->actingAs($user)
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => VoucherType::Opdracht->value,
                'activity_prices' => [
                    $item->id => [
                        'm2' => [
                            'description' => 'Primen & Egaliseren',
                            'price_kind' => 'unit',
                            'unit_price' => '2.00',
                            'unit' => 'm2',
                        ],
                    ],
                ],
                'lines' => [
                    [
                        'project_area_id' => $area->id,
                        'work_item_id' => $item->id,
                        'room_label' => '0.02 groepsruimte',
                        'description' => 'Primen & Egaliseren',
                        'quantity' => '50.25',
                        'unit' => 'm2',
                        'unit_price' => '2.00',
                    ],
                    [
                        'project_area_id' => $other->id,
                        'work_item_id' => $item->id,
                        'room_label' => '0.03 groepsruimte',
                        'description' => 'Primen & Egaliseren',
                        'quantity' => '51.16',
                        'unit' => 'm2',
                        'unit_price' => '2.00',
                    ],
                ],
            ])
            ->assertRedirect();

        $voucher = Voucher::query()->where('type', VoucherType::Opdracht)->first();
        $this->assertNotNull($voucher);

        $response = $this->actingAs($user)->get(route('vouchers.pdf', $voucher));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'Opdrachtbon_Harm-Wesselink_260200090.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));

        $text = $this->pdfText($response);
        $this->assertStringContainsString('OPDRACHTBON', $text);
        $this->assertStringContainsString('Nicon Vloeren', $text);
        $this->assertStringContainsString('Manenbergring 9', $text);
        $this->assertStringContainsString('Harm Wesselink', $text);
        $this->assertStringContainsString('Primen & Egaliseren', $text);
        $this->assertStringContainsString('0.02 groepsruimte', $text);
        $this->assertStringContainsString('0.03 groepsruimte', $text);
        $this->assertStringContainsString('101,41', $text);
        $this->assertStringContainsString('202,82', $text);
        $this->assertStringContainsString('Totaal opdracht', $text);
        $this->assertStringContainsString('Opdracht verstrekt door', $text);
        $this->assertStringNotContainsString('100,50', $text);
        $this->assertStringNotContainsString('Productie · Nicon Planning', $text);
        $this->assertStringNotContainsString('nicon-planning.test', $text);
    }

    public function test_pdf_totals_four_rooms_at_the_activity_price(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->seedWesselinkProduction();
        $areas = $project->areas()->orderBy('area_number')->get();

        $this->actingAs($user)
            ->post(route('vouchers.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'type' => VoucherType::Opdracht->value,
                'activity_prices' => [
                    $item->id => [
                        'm2' => [
                            'description' => 'Primen & Egaliseren',
                            'price_kind' => 'unit',
                            'unit_price' => '2.00',
                            'unit' => 'm2',
                        ],
                    ],
                ],
                'lines' => $areas->map(fn (ProjectArea $area): array => [
                    'project_area_id' => $area->id,
                    'work_item_id' => $item->id,
                    'room_label' => $area->label(),
                    'description' => 'Primen & Egaliseren',
                    'quantity' => (string) $area->square_meters,
                    'unit' => 'm2',
                    'unit_price' => '2.00',
                ])->all(),
            ])
            ->assertRedirect();

        $voucher = Voucher::query()->where('type', VoucherType::Opdracht)->first();
        $this->assertNotNull($voucher);

        $response = $this->actingAs($user)->get(route('vouchers.pdf', $voucher));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'Opdrachtbon_Wesselink-Media_11P241267_250100010.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );

        $text = $this->pdfText($response);
        $this->assertStringContainsString('Wesselink Media', $text);
        $this->assertStringContainsString('Gezondheidscentrum Laren', $text);
        $this->assertStringContainsString('11P241267', $text);
        $this->assertStringContainsString('250100010', $text);
        $this->assertStringContainsString('Primen & Egaliseren', $text);
        $this->assertStringContainsString('143,01', $text);
        $this->assertStringContainsString('2,00', $text);
        $this->assertStringContainsString('286,02', $text);
        $this->assertStringContainsString('1.62 oefenruimte', $text);
        $this->assertStringContainsString('83,65', $text);
        $this->assertStringContainsString('1.64 cabine', $text);
        $this->assertStringContainsString('16,31', $text);
        $this->assertStringContainsString('1.65 cabine 3', $text);
        $this->assertStringContainsString('16,33', $text);
        $this->assertStringContainsString('1.67 behandelkamer groot/kracht', $text);
        $this->assertStringContainsString('26,72', $text);
        $this->assertStringNotContainsString('167,30', $text);
        $this->assertStringNotContainsString('32,62', $text);
        $this->assertStringNotContainsString('32,66', $text);
        $this->assertStringNotContainsString('53,44', $text);
        $this->assertStringNotContainsString('Productie · Nicon Planning', $text);
        $this->assertStringNotContainsString('nicon-planning.test', $text);
    }

    public function test_production_page_has_download_pdf_next_to_print(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area, '2.00', '50.25');
        $voucher = Voucher::query()->where('type', VoucherType::Opdracht)->first();
        $this->assertNotNull($voucher);

        $this->actingAs($user)
            ->get(route('production.index', [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertSee('Printen')
            ->assertSee('Download PDF')
            ->assertSee(route('vouchers.pdf', $voucher), false);
    }

    public function test_vakman_cannot_download_another_workers_pdf(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $voucher = Voucher::query()->first();
        $this->assertNotNull($voucher);

        $other = Worker::query()->create([
            'name' => 'Fabian',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($other->id)->create([
            'can_access_all_projects' => true,
        ]);

        $this->actingAs($vakman)
            ->get(route('vouchers.pdf', $voucher))
            ->assertForbidden();
    }

    public function test_escapes_dangerous_names_in_the_opdrachtbon_html(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item, $area] = $this->seedProduction();
        $worker->update(['company' => '<script>alert("xss")</script>']);
        $project->update(['name' => '<img src=x onerror=alert(1)>']);
        $this->storeOpdracht($user, $worker, $project, $item, $area);
        $voucher = Voucher::query()->first();
        $this->assertNotNull($voucher);

        $html = view('vouchers.pdf', app(VoucherPdfService::class)->build($voucher->fresh(['worker', 'project', 'lines.area'])))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function createRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'planner' => [UserRole::Planner, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
        ];
    }

    /**
     * @return array{0: Worker, 1: Project, 2: WorkItem}
     */
    private function seedWesselinkProduction(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Wesselink Media',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => '11P241267 Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 1,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 143.01,
            'status' => 'in_uitvoering',
            'sort_order' => 1,
        ]);
        foreach ([
            ['1.62', 'oefenruimte', 83.65],
            ['1.64', 'cabine', 16.31],
            ['1.65', 'cabine 3', 16.33],
            ['1.67', 'behandelkamer groot/kracht', 26.72],
        ] as $room) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $room[0],
                'name' => $room[1],
                'square_meters' => $room[2],
                'status' => 'in_uitvoering',
            ]);
            WorkProgressEntry::query()->create([
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'project_area_id' => $area->id,
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
                'completed_quantity' => $room[2],
                'unit' => 'm2',
                'worked_hours' => 8,
            ]);
        }

        return [$worker, $project, $item];
    }

    /**
     * @return array{0: Worker, 1: Project, 2: WorkItem, 3: ProjectArea}
     */
    private function seedProduction(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.02',
            'name' => 'groepsruimte',
            'square_meters' => 50.25,
            'status' => 'in_uitvoering',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 50.25,
            'status' => 'in_uitvoering',
            'sort_order' => 1,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'project_area_id' => $area->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'completed_quantity' => 50.25,
            'unit' => 'm2',
            'worked_hours' => 8,
        ]);

        return [$worker, $project, $item, $area];
    }

    private function addCompletedRoom(
        Project $project,
        ProjectArea $sibling,
        WorkItem $item,
        Worker $worker,
        string $number,
        string $name,
        float $meters,
    ): ProjectArea {
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $sibling->project_floor_id,
            'area_number' => $number,
            'name' => $name,
            'square_meters' => $meters,
            'status' => 'in_uitvoering',
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'project_area_id' => $area->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-02',
            'completed_quantity' => $meters,
            'unit' => 'm2',
            'worked_hours' => 4,
        ]);

        return $area;
    }

    private function storeOpdracht(
        User $user,
        Worker $worker,
        Project $project,
        WorkItem $item,
        ProjectArea $area,
        string $price = '5.50',
        string $quantity = '300',
    ): void {
        $this->actingAs($user)
            ->post(route('vouchers.store'), $this->payload($worker, $project, $item, $area, $price, VoucherType::Opdracht, $quantity))
            ->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Worker $worker,
        Project $project,
        WorkItem $item,
        ProjectArea $area,
        string $price,
        VoucherType $type = VoucherType::Facturatie,
        string $quantity = '50.25',
    ): array {
        $line = [
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'room_label' => '0.02 groepsruimte',
            'description' => 'Primen & Egaliseren',
            'quantity' => $quantity,
            'unit' => 'm2',
        ];
        if ($price !== '') {
            $line['unit_price'] = $price;
        }

        return [
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'type' => $type->value,
            'lines' => [$line],
        ];
    }

    private function pdfText($response): string
    {
        $text = (new Parser)->parseContent($response->getContent())->getText();

        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }
}
