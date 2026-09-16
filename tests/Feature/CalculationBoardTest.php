<?php

namespace Tests\Feature;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
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
}
