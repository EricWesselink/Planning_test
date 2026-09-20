<?php

namespace Tests\Feature;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculationBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_calculation_board(): void
    {
        $calculation = $this->makeCalculation();

        $this->get(route('calculations.board', $calculation))
            ->assertRedirect(route('login'));
    }

    public function test_guest_cannot_update_a_board_room(): void
    {
        $calculation = $this->makeCalculation();
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $this->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
            'floor_quantity' => '4,20',
        ])->assertUnauthorized();
    }

    public function test_vakman_is_forbidden_from_the_calculation_board(): void
    {
        $user = User::factory()->vakman()->create();
        $calculation = $this->makeCalculation();

        $this->actingAs($user)
            ->get(route('calculations.board', $calculation))
            ->assertForbidden();
    }

    public function test_board_groups_rooms_and_shows_material_legend(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->get(route('calculations.board', $calculation))
            ->assertOk()
            ->assertSee('Calculatiebord')
            ->assertSee('Regels')
            ->assertSee('Totalen')
            ->assertSee('Bronbestanden')
            ->assertSee('Alle materialen')
            ->assertSee('v04')
            ->assertSee('Gietvloer')
            ->assertSee('A-00-13')
            ->assertSee('MIVA T')
            ->assertSee('fase 1 verdieping 1')
            ->assertSee('Controleren')
            ->assertSee('Handmatig aangepast')
            ->assertSee('Alle ruimtes')
            ->assertSee('Ruimtenaam')
            ->assertSee('Oppervlakte aanpassen')
            ->assertSee('Materiaal kiezen/aanpassen')
            ->assertSee('Dubbelklik op een lege plek om een ontbrekende ruimte aan te maken, met materiaal en m².')
            ->assertSee('id="calc-material-dialog"', false)
            ->assertSee('id="calc-open-material"', false)
            ->assertSee('id="calc-material-code"', false)
            ->assertSee('id="calc-material-apply"', false)
            ->assertSee('id="calc-create-number"', false)
            ->assertSee('id="calc-create-m2"', false)
            ->assertSee('Positie herstellen')
            ->assertSee('Automatisch herstellen')
            ->assertSee('Gegevens wijzigen')
            ->assertSee('id="room-groups"', false)
            ->assertSee('id="work-legend"', false)
            ->assertSee('id="room-status"', false)
            ->assertSee('id="room-review-kind"', false)
            ->assertSee('room-panel-head', false)
            ->assertSee('is-review', false)
            ->assertSee('data-filter="floors"', false)
            ->assertSee('data-filter="manual"', false)
            ->assertSee('id="calculation-board"', false);
    }

    public function test_board_reuses_drawing_labels_and_keeps_material_codes_compact(): void
    {
        $js = file_get_contents(resource_path('js/calculation-board.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('appendCodeChip', $js);
        $this->assertStringContainsString('className = `calc-code-chip ${reviewKindClass(room)}`', $js);
        $this->assertStringContainsString('consumeDoubleActivate', $js);
        $this->assertStringContainsString('revealMaterialDialog', $js);
        $this->assertStringContainsString("content.code || '+'", $js);
        $this->assertStringContainsString('roomOverlayContent', $js);
        $this->assertStringContainsString('keepView: true', $js);
        $this->assertStringContainsString('Alle materialen', $js);
        $this->assertStringContainsString('groupCardHtml', $js);
        $this->assertStringContainsString('class="work-group', $js);
        $this->assertStringContainsString('work-card-qty', $js);
        $this->assertStringContainsString('function workCardQtyHtml', $js);
        $this->assertStringContainsString('work-card-qty-part', $js);
        $this->assertStringContainsString('.work-card-qty', $css);
        $this->assertStringContainsString('.work-card-qty-part', $css);
        $this->assertDoesNotMatchRegularExpression('/\.work-card-title\s*\{[^}]*line-clamp/s', $css);
        $this->assertMatchesRegularExpression('/\.work-card-title\s*\{[^}]*font-size:\s*0\.75rem/s', $css);
        $this->assertMatchesRegularExpression('/\.work-card-qty-part\s*\{[^}]*white-space:\s*nowrap/s', $css);
        $this->assertMatchesRegularExpression('/\.group-status\s*\{[^}]*border-radius:\s*999px/s', $css);
        $this->assertStringContainsString('Oppervlakte deelvlak', $js);
        $this->assertStringContainsString('local-area-form', $js);
        $this->assertStringContainsString('work-role', $js);
        $this->assertStringContainsString('renderWorkLegend', $js);
        $this->assertStringContainsString("classList.toggle('has-room'", $js);
        $this->assertStringContainsString('roomMaterialGroups', $js);
        $this->assertStringNotContainsString("className = 'room-name-overlay'", $js);
        $this->assertStringContainsString('openCreateRoomDialog', $js);
        $this->assertStringContainsString('unplacedRoomDraft', $js);
        $this->assertStringContainsString('create_room', $js);
    }

    public function test_rules_page_jumps_to_the_same_room_on_the_board(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $rowKey = $calculation->drawings()->value('id').'|a-00-13';

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Bekijk op tekening')
            ->assertSee(route('calculations.board', [$calculation, 'room' => $rowKey]), false);
    }

    public function test_board_escapes_dangerous_room_names(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user, roomName: "<script>alert('xss')</script>");

        $html = $this->actingAs($user)
            ->get(route('calculations.board', $calculation))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
    }

    public function test_board_room_update_corrects_values_and_returns_the_new_color(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'room_number' => 'A-00-13',
                'room_name' => 'MIVA T',
                'floor_code' => 'v09',
                'floor_product' => 'Tapijt',
                'floor_quantity' => '4,20',
                'plinth_code' => 'pl02',
                'plinth_product' => 'Holplint',
                'plinth_quantity' => '12,84',
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('room.floor_code', 'v09')
            ->assertJsonPath('room.floor_product', 'Tapijt')
            ->assertJsonPath('room.m2', 4.2);

        $floor->refresh();
        $this->assertSame('v09', $floor->product_code);
        $this->assertSame('Tapijt', $floor->product);
        $this->assertEqualsWithDelta(4.2, (float) $floor->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $floor->source);
        $this->assertSame('v09', $response->json('room.material_key'));
        $this->assertNotSame($response->json('room.material_color'), null);
        $this->assertNotSame('#9ca3af', $response->json('room.material_color'));
        $this->assertTrue(collect($response->json('materials'))->contains(
            fn (array $material): bool => ($material['key'] ?? null) === 'v09'
        ));
    }

    public function test_board_subtracts_a_new_local_floor_area_from_the_main_floor_that_still_has_the_room_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $main = $calculation->lines()->where('product_code', 'v01.g')->first();
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $other = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-01')->first();

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $local]), [
                'floors' => [
                    ['id' => $local->id, 'quantity' => '4,20'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('room.groups.1.progress_label', 'Calc. 4,20 m²')
            ->assertJsonPath('room.groups.1.needs_local_area', false)
            ->assertJsonPath('room.groups.1.is_local', true)
            ->assertJsonPath('room.groups.0.progress_label', 'Calc. 27,70 m²');

        $local->refresh();
        $main->refresh();
        $other->refresh();
        $this->assertEqualsWithDelta(4.2, (float) $local->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $local->source);
        $this->assertEqualsWithDelta(27.7, (float) $main->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $main->source);
        $this->assertNull($other->quantity);
        $this->assertSame(QuantitySource::Review, $other->source);
        $this->assertEqualsWithDelta(31.9, (float) $response->json('room.m2'), 0.001);

        $totals = app(CalculationTotals::class)->grouped($calculation->fresh()->lines);
        $byCode = collect($totals)->keyBy(fn (array $row) => mb_strtolower((string) $row['product_code']));
        $this->assertEqualsWithDelta(27.7, (float) $byCode['v01.g']['quantity'], 0.001);
        $this->assertEqualsWithDelta(4.2, (float) $byCode['v09']['quantity'], 0.001);
    }

    public function test_board_does_not_subtract_from_a_main_floor_that_was_already_changed(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $main = $calculation->lines()->where('product_code', 'v01.g')->first();
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $main->update(['quantity' => 30.0, 'source' => QuantitySource::Manual]);

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $local]), [
                'floors' => [
                    ['id' => $local->id, 'quantity' => '4,20'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('room.groups.1.progress_label', 'Calc. 4,20 m²')
            ->assertJsonPath('room.groups.0.progress_label', 'Calc. 30,00 m²');

        $local->refresh();
        $main->refresh();
        $this->assertEqualsWithDelta(4.2, (float) $local->quantity, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $main->quantity, 0.001);
    }

    public function test_board_subtracts_a_second_local_floor_area_from_the_remaining_main_floor(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $main = $calculation->lines()->where('product_code', 'v01.g')->first();
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $second = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $local->calculation_drawing_id,
            'sort_order' => 4,
            'room_number' => 'A-00-18',
            'room_name' => 'ENTREE',
            'product_code' => 'v10',
            'product' => 'Extra deelvlak',
            'quantity' => null,
            'room_area' => 31.9,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Local,
            'source' => QuantitySource::Review,
        ]);
        $local->update(['quantity' => 4.2, 'source' => QuantitySource::Manual]);
        $main->update(['quantity' => 27.7, 'source' => QuantitySource::Manual]);

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $second]), [
                'floors' => [
                    ['id' => $second->id, 'quantity' => '2,00'],
                ],
            ])
            ->assertOk();

        $main->refresh();
        $second->refresh();
        $local->refresh();
        $this->assertEqualsWithDelta(2.0, (float) $second->quantity, 0.001);
        $this->assertEqualsWithDelta(25.7, (float) $main->quantity, 0.001);
        $this->assertEqualsWithDelta(4.2, (float) $local->quantity, 0.001);
    }

    public function test_board_does_not_overwrite_an_existing_local_floor_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $local->update(['quantity' => 4.2, 'source' => QuantitySource::Manual]);

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $local]), [
                'floors' => [
                    ['id' => $local->id, 'quantity' => '9,99'],
                ],
            ])
            ->assertOk();

        $local->refresh();
        $this->assertEqualsWithDelta(4.2, (float) $local->quantity, 0.001);
    }

    public function test_board_rejects_a_non_numeric_local_floor_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $local]), [
                'floors' => [
                    ['id' => $local->id, 'quantity' => 'abc'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('floors.0.quantity');

        $local->refresh();
        $this->assertNull($local->quantity);
    }

    public function test_vakman_is_forbidden_from_updating_a_board_room(): void
    {
        $user = User::factory()->vakman()->create();
        $calculation = $this->makeCalculation();
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'floor_quantity' => '4,20',
            ])
            ->assertForbidden();
    }

    public function test_board_saves_a_chip_position_without_changing_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $quantity = (float) $floor->quantity;
        $code = $floor->product_code;
        $product = $floor->product;

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'chip' => ['x' => 0.42, 'y' => 0.33, 'page' => 1],
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('room.chip.x', 0.42)
            ->assertJsonPath('room.chip.y', 0.33)
            ->assertJsonPath('room.chip.manual', true)
            ->assertJsonPath('room.floor_code', $code)
            ->assertJsonPath('room.floor_product', $product)
            ->assertJsonPath('room.m2', $quantity);

        $floor->refresh();
        $this->assertEqualsWithDelta($quantity, (float) $floor->quantity, 0.001);
        $this->assertSame($code, $floor->product_code);
        $this->assertSame($product, $floor->product);
        $trace = json_decode((string) $floor->calculation_trace, true);
        $this->assertIsArray($trace);
        $this->assertSame(0.42, $trace['chip']['x']);
        $this->assertTrue($trace['chip']['manual']);
    }

    public function test_board_saves_a_local_chip_without_changing_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $main = $calculation->lines()->where('product_code', 'v01.g')->first();
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $mainQuantity = (float) $main->quantity;
        $localQuantity = $local->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $main]), [
                'chip' => ['x' => 0.31, 'y' => 0.44, 'page' => 1, 'finish_id' => $local->id],
            ])
            ->assertOk()
            ->assertJsonPath('room.floors.1.chip.x', 0.31)
            ->assertJsonPath('room.floors.1.chip.manual', true)
            ->assertJsonPath('room.floors.0.chip', null);

        $main->refresh();
        $local->refresh();
        $this->assertEqualsWithDelta($mainQuantity, (float) $main->quantity, 0.001);
        $this->assertSame($localQuantity, $local->quantity);
        $this->assertSame('v01.g', $main->product_code);
        $this->assertSame('v09', $local->product_code);
        $trace = json_decode((string) $local->calculation_trace, true);
        $this->assertSame(0.31, $trace['chip']['x']);
        $this->assertNull(json_decode((string) $main->calculation_trace, true)['chip'] ?? null);
    }

    public function test_board_resets_a_chip_position_and_keeps_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $floor->update([
            'calculation_trace' => json_encode([
                'role' => 'room_floor',
                'reliable' => true,
                'chip' => ['x' => 0.42, 'y' => 0.33, 'page' => 1, 'manual' => true],
            ]),
        ]);
        $quantity = (float) $floor->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'chip_reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('room.chip', null)
            ->assertJsonPath('room.m2', $quantity);

        $floor->refresh();
        $this->assertEqualsWithDelta($quantity, (float) $floor->quantity, 0.001);
        $trace = json_decode((string) $floor->calculation_trace, true);
        $this->assertSame('room_floor', $trace['role']);
        $this->assertArrayNotHasKey('chip', $trace);
    }

    public function test_board_restores_automatic_material_without_changing_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $floor->update([
            'product_code' => 'v09',
            'product' => 'Tapijt',
            'original_product_code' => 'v04',
            'original_product' => 'Gietvloer',
        ]);
        $quantity = (float) $floor->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'restore_automatic' => true,
            ])
            ->assertOk()
            ->assertJsonPath('room.floor_code', 'v04')
            ->assertJsonPath('room.floor_product', 'Gietvloer')
            ->assertJsonPath('room.m2', $quantity);

        $floor->refresh();
        $this->assertSame('v04', $floor->product_code);
        $this->assertSame('Gietvloer', $floor->product);
        $this->assertEqualsWithDelta($quantity, (float) $floor->quantity, 0.001);
    }

    public function test_board_changes_material_from_the_legend_without_changing_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $calculation->drawings()->first()->update([
            'legend' => [
                ['code' => 'v01', 'product' => 'Marmoleum - Forbo 3733', 'kind' => 'floor'],
                ['code' => 'v04', 'product' => 'Gietvloer', 'kind' => 'floor'],
            ],
        ]);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $quantity = (float) $floor->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'floor_code' => 'v01',
                'floor_product' => 'Marmoleum - Forbo 3733',
            ])
            ->assertOk()
            ->assertJsonPath('room.floor_code', 'v01')
            ->assertJsonPath('room.floor_product', 'Marmoleum - Forbo 3733')
            ->assertJsonPath('room.m2', $quantity);

        $floor->refresh();
        $this->assertSame('v01', $floor->product_code);
        $this->assertSame('Marmoleum - Forbo 3733', $floor->product);
        $this->assertEqualsWithDelta($quantity, (float) $floor->quantity, 0.001);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
    }

    public function test_board_assigns_material_when_none_was_recognized(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $floor->update([
            'product_code' => null,
            'product' => null,
            'original_product_code' => null,
            'original_product' => null,
        ]);
        $quantity = (float) $floor->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'floor_code' => 'v01e',
                'floor_product' => 'Marmoleum - Forbo 3750',
            ])
            ->assertOk()
            ->assertJsonPath('room.floor_code', 'v01e')
            ->assertJsonPath('room.floor_product', 'Marmoleum - Forbo 3750')
            ->assertJsonPath('room.m2', $quantity);

        $floor->refresh();
        $this->assertSame('v01e', $floor->product_code);
        $this->assertSame('Marmoleum - Forbo 3750', $floor->product);
        $this->assertEqualsWithDelta($quantity, (float) $floor->quantity, 0.001);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
    }

    public function test_board_creates_a_missing_room_with_material_and_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $drawing = $calculation->drawings()->first();

        $response = $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.store', $calculation), [
                'document_id' => $drawing->id,
                'page' => 1,
                'room_number' => 'K-01-09',
                'room_name' => 'KANTOOR MAS',
                'floor_code' => 'v01e',
                'floor_product' => 'Marmoleum - Forbo 3750',
                'floor_quantity' => '8,6',
                'chip' => ['x' => 0.42, 'y' => 0.55, 'page' => 1],
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('room.number', 'K-01-09')
            ->assertJsonPath('room.name', 'KANTOOR MAS')
            ->assertJsonPath('room.floor_code', 'v01e')
            ->assertJsonPath('room.floor_product', 'Marmoleum - Forbo 3750')
            ->assertJsonPath('room.m2', 8.6)
            ->assertJsonPath('room.chip.x', 0.42)
            ->assertJsonPath('room.chip.manual', true);

        $this->assertSame(2, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
        $created = $calculation->lines()->where('room_number', 'K-01-09')->where('unit', WorkUnit::SquareMeter)->first();
        $this->assertNotNull($created);
        $this->assertSame('v01e', $created->product_code);
        $this->assertEqualsWithDelta(8.6, (float) $created->quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $created->source);
        $this->assertSame(FinishRole::Main, $created->finish_role);
    }

    public function test_board_creates_a_second_floor_when_the_same_number_has_another_name(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $drawing = $calculation->drawings()->first();
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 3,
            'room_number' => 'K-01-09',
            'room_name' => 'SER',
            'product_code' => 'v01',
            'product' => 'Marmoleum',
            'quantity' => 9.8,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.store', $calculation), [
                'document_id' => $drawing->id,
                'page' => 1,
                'room_number' => 'K-01-09',
                'room_name' => 'KANTOOR MAS',
                'floor_code' => 'v06.b',
                'floor_product' => 'PVC 06.b',
                'floor_quantity' => '8,6',
                'chip' => ['x' => 0.62, 'y' => 0.40, 'page' => 1],
            ]);

        $response->assertOk()
            ->assertJsonPath('room.name', 'KANTOOR MAS')
            ->assertJsonPath('room.floor_code', 'v06.b')
            ->assertJsonPath('room.m2', 8.6);

        $floors = $calculation->lines()
            ->where('unit', WorkUnit::SquareMeter)
            ->where('room_number', 'K-01-09')
            ->get();
        $this->assertCount(2, $floors);
        $this->assertSame(['KANTOOR MAS', 'SER'], $floors->pluck('room_name')->sort()->values()->all());
        $created = $floors->firstWhere('room_name', 'KANTOOR MAS');
        $existing = $floors->firstWhere('room_name', 'SER');
        $this->assertSame('v06.b', $created?->product_code);
        $this->assertEqualsWithDelta(8.6, (float) $created?->quantity, 0.001);
        $this->assertSame('v01', $existing?->product_code);
        $this->assertEqualsWithDelta(9.8, (float) $existing?->quantity, 0.001);
    }

    public function test_board_fills_an_existing_unrecognized_room_instead_of_adding_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $drawing = $calculation->drawings()->first();
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.store', $calculation), [
                'document_id' => $drawing->id,
                'page' => 1,
                'room_number' => $floor->room_number,
                'room_name' => $floor->room_name,
                'floor_code' => 'v01e',
                'floor_product' => 'Marmoleum - Forbo 3750',
                'floor_quantity' => '8,6',
                'chip' => ['x' => 0.31, 'y' => 0.44, 'page' => 1],
            ])
            ->assertOk()
            ->assertJsonPath('room.floor_code', 'v01e')
            ->assertJsonPath('room.m2', 8.6);

        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
        $floor->refresh();
        $this->assertSame('v01e', $floor->product_code);
        $this->assertEqualsWithDelta(8.6, (float) $floor->quantity, 0.001);
    }

    public function test_board_rejects_creating_a_room_without_material_or_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $drawing = $calculation->drawings()->first();

        $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.store', $calculation), [
                'document_id' => $drawing->id,
                'page' => 1,
                'room_number' => 'K-01-09',
                'chip' => ['x' => 0.4, 'y' => 0.4, 'page' => 1],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.floor_code.0', 'Kies of vul een materiaalcode in.')
            ->assertJsonPath('errors.floor_quantity.0', 'Vul de oppervlakte in m² in.');
    }

    public function test_guest_cannot_create_a_board_room(): void
    {
        $calculation = $this->makeCalculation();
        $drawing = $calculation->drawings()->first();

        $this->postJson(route('calculations.board.rooms.store', $calculation), [
            'document_id' => $drawing->id,
            'page' => 1,
            'room_number' => 'K-01-09',
            'room_name' => 'KANTOOR MAS',
            'floor_code' => 'v01e',
            'floor_quantity' => '8,6',
            'chip' => ['x' => 0.4, 'y' => 0.4, 'page' => 1],
        ])->assertUnauthorized();
    }

    public function test_vakman_is_forbidden_from_creating_a_board_room(): void
    {
        $user = User::factory()->vakman()->create();
        $calculation = $this->makeCalculation();
        $drawing = $calculation->drawings()->first();

        $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.store', $calculation), [
                'document_id' => $drawing->id,
                'page' => 1,
                'room_number' => 'K-01-09',
                'room_name' => 'KANTOOR MAS',
                'floor_code' => 'v01e',
                'floor_quantity' => '8,6',
                'chip' => ['x' => 0.4, 'y' => 0.4, 'page' => 1],
            ])
            ->assertForbidden();
    }

    public function test_board_corrects_quantity_on_the_same_line_without_adding_area(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCompleteRoomCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'floor_quantity' => '16,40',
            ]);

        $response->assertOk()
            ->assertJsonPath('room.floor_code', 'v01')
            ->assertJsonPath('room.m2', 16.4)
            ->assertJsonPath('room.review_kind', 'manual')
            ->assertJsonPath('room.review_kind_label', 'Handmatig aangepast')
            ->assertJsonPath('room.original_floor_quantity', 15.9);

        $floor->refresh();
        $this->assertEqualsWithDelta(16.4, (float) $floor->quantity, 0.001);
        $this->assertEqualsWithDelta(15.9, (float) $floor->original_quantity, 0.001);
        $this->assertSame(QuantitySource::Manual, $floor->source);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
        $this->assertEqualsWithDelta(16.4, (float) collect($response->json('materials'))->firstWhere('key', 'v01')['m2'], 0.001);
    }

    public function test_board_moves_existing_area_to_a_legend_material_on_the_same_line(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCompleteRoomCalculation($user);
        $calculation->drawings()->first()->update([
            'legend' => [
                ['code' => 'v01', 'product' => 'Marmoleum', 'kind' => 'floor'],
                ['code' => 'v06.b', 'product' => 'PVC 06.b', 'kind' => 'floor'],
            ],
        ]);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'floor_code' => 'v06.b',
                'floor_product' => 'PVC 06.b',
            ]);

        $response->assertOk()
            ->assertJsonPath('room.floor_code', 'v06.b')
            ->assertJsonPath('room.floor_product', 'PVC 06.b')
            ->assertJsonPath('room.m2', 15.9)
            ->assertJsonPath('room.review_kind', 'manual');

        $floor->refresh();
        $this->assertSame('v06.b', $floor->product_code);
        $this->assertSame('v01', $floor->original_product_code);
        $this->assertEqualsWithDelta(15.9, (float) $floor->quantity, 0.001);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
        $this->assertEqualsWithDelta(15.9, (float) collect($response->json('materials'))->firstWhere('key', 'v06.b')['m2'], 0.001);
        $this->assertNull(collect($response->json('materials'))->firstWhere('key', 'v01'));
    }

    public function test_board_restores_automatic_quantity_and_material(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCompleteRoomCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $floor->update([
            'product_code' => 'v06.b',
            'product' => 'PVC 06.b',
            'quantity' => 16.4,
            'source' => QuantitySource::Manual,
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $floor]), [
                'restore_automatic' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('room.floor_code', 'v01')
            ->assertJsonPath('room.floor_product', 'Marmoleum')
            ->assertJsonPath('room.m2', 15.9)
            ->assertJsonPath('room.review_kind', 'certain');

        $floor->refresh();
        $this->assertSame('v01', $floor->product_code);
        $this->assertSame('Marmoleum', $floor->product);
        $this->assertEqualsWithDelta(15.9, (float) $floor->quantity, 0.001);
        $this->assertSame(QuantitySource::FromDrawing, $floor->source);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
    }

    public function test_board_changes_a_local_finish_material_without_changing_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeSplitFloorCalculation($user);
        $calculation->drawings()->first()->update([
            'legend' => [
                ['code' => 'v01.g', 'product' => 'Marmoleum - Forbo 3752', 'kind' => 'floor'],
                ['code' => 'v04', 'product' => 'Gietvloer', 'kind' => 'floor'],
                ['code' => 'v09', 'product' => 'Schoonloopmat', 'kind' => 'floor'],
            ],
        ]);
        $main = $calculation->lines()->where('product_code', 'v01.g')->first();
        $local = $calculation->lines()->where('product_code', 'v09')->where('room_number', 'A-00-18')->first();
        $mainQuantity = (float) $main->quantity;

        $this->actingAs($user)
            ->patchJson(route('calculations.board.rooms.update', [$calculation, $main]), [
                'floors' => [
                    ['id' => $local->id, 'code' => 'v04', 'product' => 'Gietvloer'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('room.floors.1.code', 'v04')
            ->assertJsonPath('room.floors.1.product', 'Gietvloer')
            ->assertJsonPath('room.floors.0.code', 'v01.g')
            ->assertJsonPath('room.m2', $mainQuantity);

        $main->refresh();
        $local->refresh();
        $this->assertSame('v04', $local->product_code);
        $this->assertSame('Gietvloer', $local->product);
        $this->assertNull($local->quantity);
        $this->assertSame('v01.g', $main->product_code);
        $this->assertEqualsWithDelta($mainQuantity, (float) $main->quantity, 0.001);
        $this->assertSame(3, $calculation->lines()->where('unit', WorkUnit::SquareMeter)->count());
    }

    public function test_board_payload_lists_the_drawing_legend(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $calculation->drawings()->first()->update([
            'legend' => [
                ['code' => 'v01', 'product' => 'Marmoleum - Forbo 3733', 'kind' => 'floor'],
            ],
        ]);

        $html = $this->actingAs($user)
            ->get(route('calculations.board', $calculation))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('v01 – Marmoleum - Forbo 3733', $html);
    }

    public function test_board_room_confirm_rejects_incomplete_rooms(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();

        $this->actingAs($user)
            ->postJson(route('calculations.board.rooms.confirm', [$calculation, $floor]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Vul ontbrekende waarden eerst in voordat je bevestigt.');
    }

    public function test_totals_and_files_tabs_render(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->get(route('calculations.totals', $calculation))
            ->assertOk()
            ->assertSee('Gietvloer')
            ->assertSee('3,70');

        $this->actingAs($user)
            ->get(route('calculations.files', $calculation))
            ->assertOk()
            ->assertSee('fase 1 verdieping 1.pdf');
    }

    private function makeCalculation(?User $user = null, string $roomName = 'MIVA T'): Calculation
    {
        $user ??= User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'fase 1 verdieping 1.pdf',
            'file_path' => 'calculations/1/demo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 1,
            'room_number' => 'A-00-13',
            'room_name' => $roomName,
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 2,
            'room_number' => 'A-00-13',
            'room_name' => $roomName,
            'product_code' => 'pl02',
            'product' => 'Holplint',
            'quantity' => null,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Review,
        ]);

        return $calculation->fresh(['lines', 'drawings']) ?? $calculation;
    }

    private function makeCompleteRoomCalculation(User $user): Calculation
    {
        $calculation = Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'fase 1 verdieping 1.pdf',
            'file_path' => 'calculations/1/demo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'legend' => [
                ['code' => 'v01', 'product' => 'Marmoleum', 'kind' => 'floor'],
                ['code' => 'v06.b', 'product' => 'PVC 06.b', 'kind' => 'floor'],
            ],
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 1,
            'room_number' => 'K-01-09',
            'room_name' => 'KANTOOR MAS',
            'product_code' => 'v01',
            'product' => 'Marmoleum',
            'original_product_code' => 'v01',
            'original_product' => 'Marmoleum',
            'quantity' => 15.9,
            'original_quantity' => 15.9,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
            'found_source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 2,
            'room_number' => 'K-01-09',
            'room_name' => 'KANTOOR MAS',
            'product_code' => 'pl01',
            'product' => 'Plint',
            'quantity' => 12.5,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::FromDrawing,
            'found_source' => QuantitySource::FromDrawing,
        ]);

        return $calculation->fresh(['lines', 'drawings']) ?? $calculation;
    }

    private function makeSplitFloorCalculation(User $user): Calculation
    {
        $calculation = Calculation::query()->create([
            'name' => 'COA Oisterwijk 3 gebouwen',
            'dated_on' => '2026-09-16',
            'created_by' => $user->id,
        ]);
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg.pdf',
            'file_path' => 'calculations/1/bg.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 1,
            'room_number' => 'A-00-18',
            'room_name' => 'ENTREE',
            'product_code' => 'v01.g',
            'product' => 'Marmoleum - Forbo 3752',
            'quantity' => 31.9,
            'room_area' => 31.9,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Main,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 2,
            'room_number' => 'A-00-18',
            'room_name' => 'ENTREE',
            'product_code' => 'v09',
            'product' => 'Schoonloopmat',
            'quantity' => null,
            'room_area' => 31.9,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Local,
            'source' => QuantitySource::Review,
            'note' => 'Deelvlak zonder eigen m²; niet de volledige ruimteoppervlakte.',
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 3,
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'product_code' => 'v09',
            'product' => 'Schoonloopmat',
            'quantity' => null,
            'room_area' => 78.9,
            'unit' => WorkUnit::SquareMeter,
            'finish_role' => FinishRole::Local,
            'source' => QuantitySource::Review,
        ]);

        return $calculation->fresh(['lines', 'drawings']) ?? $calculation;
    }
}
