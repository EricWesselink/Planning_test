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
            ->assertSee('Ruimtenaam')
            ->assertSee('Vloercode')
            ->assertSee('Gegevens wijzigen')
            ->assertSee('id="room-groups"', false)
            ->assertSee('id="work-legend"', false)
            ->assertSee('id="room-status"', false)
            ->assertSee('room-panel-head', false)
            ->assertSee('is-review', false)
            ->assertSee('data-filter="floors"', false)
            ->assertSee('id="calculation-board"', false);
    }

    public function test_board_reuses_drawing_labels_and_keeps_material_codes_compact(): void
    {
        $js = file_get_contents(resource_path('js/calculation-board.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('appendCodeChip', $js);
        $this->assertStringContainsString("className = 'calc-code-chip'", $js);
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
        $this->assertStringContainsString("style.fill = 'transparent'", $js);
        $this->assertStringContainsString('.calc-code-chip', $css);
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
