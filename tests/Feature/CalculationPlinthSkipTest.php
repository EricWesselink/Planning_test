<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\ImportStatus;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationRoomRows;
use App\Services\QuoteCalculation\CalculationStoreService;
use App\Services\QuoteCalculation\CalculationTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class CalculationPlinthSkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_v04_without_plinth_code_stays_in_review(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Plintcode ontbreekt')
            ->assertSee('Geen plint van toepassing')
            ->assertSee('1 controleren')
            ->assertDontSee('Calculatie volledig gecontroleerd');
    }

    public function test_marking_no_plinth_removes_the_room_from_review_without_adding_meters(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user);
        $floor = $calculation->lines()->first();
        $this->assertNotNull($floor);

        $this->actingAs($user)->patch(route('calculations.update', $calculation), [
            'name' => $calculation->name,
            'dated_on' => $calculation->dated_on?->toDateString(),
            'status' => $calculation->status->value,
            'lines' => [[
                'id' => $floor->id,
                'room_number' => $floor->room_number,
                'room_name' => $floor->room_name,
                'product_code' => $floor->product_code,
                'product' => $floor->product,
                'quantity' => $floor->quantity,
                'unit' => $floor->unit->value,
                'source' => $floor->source->value,
                'note' => $floor->note,
                'plinth_not_applicable' => '1',
            ]],
        ])->assertRedirect(route('calculations.show', $calculation));

        $floor->refresh();
        $this->assertTrue($floor->plinth_not_applicable);
        $this->assertSame('v04', $floor->product_code);
        $this->assertSame('Gietvloer', $floor->product);
        $this->assertEqualsWithDelta(3.7, (float) $floor->quantity, 0.001);
        $this->assertSame(1, $calculation->lines()->count());
        $this->assertSame(0, $calculation->lines()->where('unit', WorkUnit::LinearMeter)->count());

        $table = (new CalculationRoomRows)->table($calculation->lines()->get());
        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertSame(0, $table['blocking_count']);
        $this->assertSame(0, $table['plinth_linked_count']);
        $this->assertSame(0, $table['plinth_meters_count']);
        $this->assertEqualsWithDelta(3.7, $table['square_meters'], 0.001);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertStringContainsString('Geen plint van toepassing', $table['rows'][0]['status_label']);

        $totals = (new CalculationTotals)->grouped($calculation->lines()->get());
        $this->assertCount(1, $totals);
        $this->assertSame('v04', $totals[0]['product_code']);
        $this->assertSame('m2', $totals[0]['unit']);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Geen plint van toepassing')
            ->assertSee('0 controleren')
            ->assertSee('✓ Calculatie volledig gecontroleerd')
            ->assertDontSee('Plintcode ontbreekt');
    }

    public function test_v01_without_exact_variant_still_requires_review(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Variant',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'product_code' => 'v01',
            'product' => null,
            'quantity' => 4.0,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
            'note' => 'Exacte v01-variant ontbreekt.',
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'product_code' => 'pl01',
            'product' => 'Aluminium plakplint',
            'quantity' => 8.86,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Calculated,
        ]);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Exacte v01-variant ontbreekt')
            ->assertDontSee('Geen plint van toepassing')
            ->assertSee('1 controleren');
    }

    public function test_linked_plinth_stays_unchanged_and_has_no_skip_option(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Met plint',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-13',
            'room_name' => 'MIVA T',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'A-00-13',
            'room_name' => 'MIVA T',
            'product_code' => 'pl02',
            'product' => 'Holplint',
            'quantity' => 7.72,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::Calculated,
        ]);

        $html = $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('pl02')
            ->assertSee('Holplint')
            ->assertSee('Zeker')
            ->getContent();

        $this->assertStringNotContainsString('Geen plint van toepassing', $html);
        $this->assertSame(1, $calculation->lines()->where('unit', WorkUnit::LinearMeter)->count());
        $this->assertFalse((bool) $calculation->lines()->where('unit', WorkUnit::SquareMeter)->value('plinth_not_applicable'));
    }

    public function test_skipped_plinth_choice_survives_drawing_reprocess(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Herverwerken',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        $path = 'calculations/'.$calculation->id.'/bg.pdf';
        Storage::disk('local')->put($path, SimplePdf::bytes("01.13 MIVA T 3,7 m2 v04\nv04 = Gietvloer"));
        $drawing = CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 120,
            'import_status' => ImportStatus::Pending,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_drawing_id' => $drawing->id,
            'sort_order' => 1,
            'room_number' => '01.13',
            'room_name' => 'MIVA T',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
            'plinth_not_applicable' => true,
        ]);

        app(CalculationStoreService::class)->processDrawing($drawing->fresh());

        $floor = $calculation->lines()->where('unit', WorkUnit::SquareMeter)->first();
        $this->assertNotNull($floor);
        $this->assertTrue($floor->fresh()->plinth_not_applicable);
        $this->assertSame('v04', $floor->product_code);
        $this->assertSame(0, $calculation->lines()->where('unit', WorkUnit::LinearMeter)->count());
    }

    private function calculationWithV04(User $user): Calculation
    {
        $calculation = Calculation::query()->create([
            'name' => 'Toilet gietvloer',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-13',
            'room_name' => 'MIVA T',
            'product_code' => 'v04',
            'product' => 'Gietvloer',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        return $calculation;
    }
}
