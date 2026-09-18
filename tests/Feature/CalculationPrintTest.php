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

class CalculationPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_print_options_and_print(): void
    {
        $calculation = $this->makeCalculation();

        $this->get(route('calculations.print.options', $calculation))
            ->assertRedirect(route('login'));
        $this->get(route('calculations.print', $calculation))
            ->assertRedirect(route('login'));
    }

    public function test_vakman_is_forbidden_from_print(): void
    {
        $user = User::factory()->vakman()->create();
        $calculation = $this->makeCalculation();

        $this->actingAs($user)
            ->get(route('calculations.print.options', $calculation))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['legend'],
            ]))
            ->assertForbidden();
    }

    public function test_overview_shows_print_pdf_next_to_open(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $html = $this->actingAs($user)
            ->get(route('calculations.index'))
            ->assertOk()
            ->assertSee('Openen')
            ->assertSee('Print / PDF')
            ->assertSee('Wat wil je exporteren?')
            ->assertSee('Gekleurde calculatietekeningen')
            ->getContent();

        $this->assertStringContainsString(route('calculations.print.options', $calculation), $html);
        $this->assertStringContainsString('data-print-open', $html);
    }

    public function test_print_options_list_drawing_names_and_materials(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->getJson(route('calculations.print.options', $calculation))
            ->assertOk()
            ->assertJsonPath('name', 'Offerte Wepro')
            ->assertJsonPath('drawings.0.label', 'fase 1 verdieping 1')
            ->assertJsonPath('materials.0.code', 'v04')
            ->assertJsonPath('defaults.include.0', 'colored');
    }

    public function test_print_view_puts_each_drawing_on_a3_and_keeps_the_legend_beside_it(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $drawingId = $calculation->drawings()->value('id');

        $html = $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['colored', 'rooms', 'codes', 'legend'],
                'drawing_ids' => [$drawingId],
                'output' => 'pdf',
            ]))
            ->assertOk()
            ->assertSee('A3 liggend')
            ->assertSee('Opslaan als PDF')
            ->assertSee('id="calc-print-sheets"', false)
            ->assertSee('data-print-start', false)
            ->getContent();

        $this->assertStringContainsString('fase 1 verdieping 1', $html);
        $this->assertStringContainsString('v04', $html);
        $this->assertStringContainsString('Gietvloer', $html);
        $this->assertStringContainsString('"show_drawings":true', $html);
    }

    public function test_print_json_keeps_one_or_seven_drawings_and_room_geometry(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $drawings = [];
        foreach (range(1, 7) as $index) {
            $drawings[] = CalculationDrawing::query()->create([
                'calculation_id' => $calculation->id,
                'original_filename' => "tekening-{$index}.pdf",
                'file_path' => "calculations/1/{$index}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 1,
            ]);
        }
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawings[0]->id,
            'sort_order' => 1,
            'room_number' => 'K-00-23',
            'room_name' => 'keuken',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 12.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
            'calculation_trace' => json_encode([
                'role' => 'room_floor',
                'reliable' => true,
                'page' => 1,
                'rects' => [
                    ['x' => 0.41, 'y' => 0.62, 'w' => 0.10, 'h' => 0.08],
                ],
            ]),
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawings[0]->id,
            'sort_order' => 2,
            'room_number' => 'A-01-99',
            'room_name' => 'entree',
            'product_code' => 'v09',
            'product' => 'Schoonloopmat',
            'quantity' => 4.2,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        $one = $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['colored', 'rooms', 'codes', 'legend'],
                'drawing_ids' => [$drawings[0]->id],
                'material_mode' => 'all',
                'output' => 'pdf',
            ]))
            ->assertOk()
            ->getContent();
        $seven = $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['codes'],
                'drawing_ids' => array_map(fn (CalculationDrawing $drawing): int => $drawing->id, $drawings),
                'output' => 'pdf',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"label":"tekening-1"', $one);
        $this->assertStringNotContainsString('"label":"tekening-2"', $one);
        $this->assertStringContainsString('"K-00-23"', $one);
        $this->assertStringContainsString('"x":0.41', $one);
        $this->assertStringContainsString('"y":0.62', $one);
        $this->assertStringContainsString('"A-01-99"', $one);
        $this->assertStringContainsString('"contour":null', $seven);
        foreach (range(1, 7) as $index) {
            $this->assertStringContainsString('"label":"tekening-'.$index.'"', $seven);
        }
        $this->assertStringContainsString('"codes":true', $seven);
        $this->assertStringContainsString('"colored":false', $seven);
        $this->assertStringContainsString('"rooms":false', $seven);
        $this->assertStringContainsString('"legend":false', $seven);
    }

    public function test_print_view_can_add_room_lists_and_square_meter_totals(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['room_list', 'area_totals', 'plinth_totals', 'details'],
                'output' => 'print',
            ]))
            ->assertOk()
            ->assertSee('Ruimtelijst · fase 1 verdieping 1')
            ->assertSee('A-00-13')
            ->assertSee('MIVA T')
            ->assertSee('Materiaaltotalen m²')
            ->assertSee('Plinttotalen m¹')
            ->assertSee('Detailregels calculatie')
            ->assertSee('3,70')
            ->assertSee('12,84')
            ->assertSee('Kies A3 liggend in het afdrukvenster.');
    }

    public function test_selected_materials_limit_totals_to_those_codes(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $calculation->drawings()->value('id'),
            'sort_order' => 3,
            'room_number' => 'A-00-01',
            'room_name' => 'Entree',
            'product_code' => 'v09',
            'product' => 'Schoonloopmat',
            'quantity' => 4.2,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        $html = $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['area_totals'],
                'material_mode' => 'selected',
                'material_keys' => ['v09'],
            ]))
            ->assertOk()
            ->assertSee('Schoonloopmat')
            ->assertSee('4,20')
            ->getContent();

        $this->assertStringNotContainsString('Gietvloer', $html);
    }

    public function test_print_rejects_empty_includes_and_unknown_drawings(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->from(route('calculations.index'))
            ->get(route('calculations.print', $calculation))
            ->assertRedirect(route('calculations.index'))
            ->assertSessionHasErrors(['include']);

        $this->actingAs($user)
            ->from(route('calculations.index'))
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['colored'],
                'drawing_ids' => [999999],
            ]))
            ->assertRedirect(route('calculations.index'))
            ->assertSessionHasErrors(['drawing_ids.0']);
    }

    public function test_print_requires_a_drawing_when_exporting_the_plan(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->from(route('calculations.index'))
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['colored', 'legend'],
            ]))
            ->assertRedirect(route('calculations.index'))
            ->assertSessionHasErrors(['drawing_ids']);
    }

    public function test_print_escapes_dangerous_room_names(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user, roomName: "<script>alert('xss')</script>");

        $html = $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['details'],
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
    }

    public function test_board_keeps_a_print_button_for_the_current_drawing(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);

        $this->actingAs($user)
            ->get(route('calculations.board', $calculation))
            ->assertOk()
            ->assertSee('Print / PDF')
            ->assertSee('data-print-current-drawing', false);
    }

    public function test_print_does_not_change_calculation_quantities(): void
    {
        $user = User::factory()->create();
        $calculation = $this->makeCalculation($user);
        $quantity = (float) $calculation->lines()->where('unit', WorkUnit::SquareMeter)->value('quantity');

        $this->actingAs($user)
            ->get(route('calculations.print', [
                'calculation' => $calculation,
                'include' => ['details'],
            ]))
            ->assertOk();

        $this->assertSame($quantity, (float) $calculation->fresh()->lines()->where('unit', WorkUnit::SquareMeter)->value('quantity'));
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
            'quantity' => 12.84,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        return $calculation->fresh(['lines', 'drawings']) ?? $calculation;
    }
}
