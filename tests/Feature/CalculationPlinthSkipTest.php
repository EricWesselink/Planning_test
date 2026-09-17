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

    public function test_browser_save_of_no_plinth_checkbox_persists_and_leaves_other_rooms_untouched(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user, withLegend: true);
        $floor = $calculation->lines()->where('room_number', 'A-00-14')->first();
        $other = $calculation->lines()->where('room_number', 'K-00-38')->first();
        $this->assertNotNull($floor);
        $this->assertNotNull($other);
        $this->assertFalse($floor->plinth_not_applicable);

        $html = $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('name="plinth_decisions"', false)
            ->assertSee('id="skip-plinth-'.$floor->id.'"', false)
            ->assertSee('Kies plintproduct')
            ->getContent();

        $payload = $this->patchPayloadFromReviewForm($html);
        $this->assertArrayHasKey('lines', $payload);
        $this->tickSkipPlinth($payload, $floor->id);

        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.show', $calculation));

        $floor->refresh();
        $other->refresh();
        $this->assertTrue($floor->plinth_not_applicable);
        $this->assertSame('v04', $floor->product_code);
        $this->assertSame('Gietvloer v04', $floor->product);
        $this->assertEqualsWithDelta(3.7, (float) $floor->quantity, 0.001);
        $this->assertSame('v07', $other->product_code);
        $this->assertSame('Coating', $other->product);
        $this->assertEqualsWithDelta(12.5, (float) $other->quantity, 0.001);
        $this->assertSame(0, $calculation->lines()->where('unit', WorkUnit::LinearMeter)->count());

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Geen plint van toepassing')
            ->assertDontSee('Plintcode ontbreekt')
            ->assertDontSee('Vloercode ontbreekt');
    }

    public function test_truncated_save_does_not_clear_a_room_that_lost_later_fields(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user);
        $floor = $calculation->lines()->where('room_number', 'A-00-14')->first();
        $other = $calculation->lines()->where('room_number', 'K-00-38')->first();
        $this->assertNotNull($floor);
        $this->assertNotNull($other);

        $this->actingAs($user)->patch(route('calculations.update', $calculation), [
            'name' => $calculation->name,
            'dated_on' => $calculation->dated_on?->toDateString(),
            'status' => $calculation->status->value,
            'lines' => [
                [
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
                ],
                [
                    'id' => $other->id,
                    'unit' => $other->unit->value,
                    'source' => $other->source->value,
                    'note' => $other->note,
                ],
            ],
        ])->assertRedirect(route('calculations.show', $calculation));

        $floor->refresh();
        $other->refresh();
        $this->assertTrue($floor->plinth_not_applicable);
        $this->assertSame('v07', $other->product_code);
        $this->assertSame('Coating', $other->product);
        $this->assertSame('K-00-38', $other->room_number);
        $this->assertEqualsWithDelta(12.5, (float) $other->quantity, 0.001);
    }

    public function test_plinth_decision_json_saves_skip_when_the_room_line_is_missing_from_the_post(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user);
        $floor = $calculation->lines()->where('room_number', 'A-00-14')->first();
        $other = $calculation->lines()->where('room_number', 'K-00-38')->first();
        $this->assertNotNull($floor);
        $this->assertNotNull($other);

        $this->actingAs($user)->patch(route('calculations.update', $calculation), [
            'name' => $calculation->name,
            'dated_on' => $calculation->dated_on?->toDateString(),
            'status' => $calculation->status->value,
            'plinth_decisions' => json_encode([
                (string) $floor->id => ['not_applicable' => true],
            ]),
            'lines' => [[
                'id' => $other->id,
                'room_number' => $other->room_number,
                'room_name' => $other->room_name,
                'product_code' => $other->product_code,
                'product' => $other->product,
                'quantity' => $other->quantity,
                'unit' => $other->unit->value,
                'source' => $other->source->value,
                'note' => $other->note,
            ]],
        ])->assertRedirect(route('calculations.show', $calculation));

        $this->assertTrue($floor->fresh()->plinth_not_applicable);
        $this->assertSame('v07', $other->fresh()->product_code);
    }

    public function test_choosing_a_plinth_product_for_v04_keeps_the_floor_and_does_not_skip(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user, withLegend: true);
        $floor = $calculation->lines()->where('room_number', 'A-00-14')->first();
        $this->assertNotNull($floor);

        $html = $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Kies plintproduct')
            ->assertSee('pl02 – Holplint')
            ->getContent();

        $payload = $this->patchPayloadFromReviewForm($html);
        $this->choosePlinthProduct($payload, $floor->id, 'pl02', 'Holplint');

        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.show', $calculation));

        $floor->refresh();
        $this->assertFalse($floor->plinth_not_applicable);
        $this->assertSame('v04', $floor->product_code);
        $this->assertEqualsWithDelta(3.7, (float) $floor->quantity, 0.001);
        $plinth = $calculation->lines()->where('unit', WorkUnit::LinearMeter)->first();
        $this->assertNotNull($plinth);
        $this->assertSame('pl02', $plinth->product_code);
        $this->assertSame('Holplint', $plinth->product);
        $this->assertSame('A-00-14', $plinth->room_number);

        $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Holplint')
            ->assertDontSee('Plintcode ontbreekt');
    }

    public function test_pl01_without_product_gets_a_dropdown_and_saves_the_chosen_material(): void
    {
        $user = User::factory()->create();
        $calculation = Calculation::query()->create([
            'name' => 'Plintproduct',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => 'bg.pdf',
            'file_path' => 'calculations/'.$calculation->id.'/bg.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'legend' => [
                ['code' => 'pl01', 'product' => 'Aluminium plakplint'],
            ],
        ]);
        $floor = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'K-00-20',
            'room_name' => 'OPSLAG',
            'product_code' => 'v01.g',
            'product' => 'Marmoleum',
            'quantity' => 12.0,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        $plinth = CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'K-00-20',
            'room_name' => 'OPSLAG',
            'product_code' => 'pl01',
            'product' => null,
            'quantity' => 8.86,
            'unit' => WorkUnit::LinearMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        $html = $this->actingAs($user)
            ->get(route('calculations.show', $calculation))
            ->assertOk()
            ->assertSee('Plintproduct ontbreekt')
            ->assertSee('Kies plintproduct')
            ->assertDontSee('Geen plint van toepassing')
            ->getContent();

        $payload = $this->patchPayloadFromReviewForm($html);
        $this->choosePlinthProduct($payload, $floor->id, 'pl01', 'Aluminium plakplint');

        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.show', $calculation));

        $plinth->refresh();
        $this->assertSame('pl01', $plinth->product_code);
        $this->assertSame('Aluminium plakplint', $plinth->product);
        $this->assertEqualsWithDelta(8.86, (float) $plinth->quantity, 0.001);
        $this->assertSame('v01.g', $floor->fresh()->product_code);
    }

    public function test_marking_no_plinth_removes_the_room_from_review_without_adding_meters(): void
    {
        $user = User::factory()->create();
        $calculation = $this->calculationWithV04($user);
        $floor = $calculation->lines()->where('room_number', 'A-00-14')->first();
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
        $this->assertSame('Gietvloer v04', $floor->product);
        $this->assertEqualsWithDelta(3.7, (float) $floor->quantity, 0.001);
        $this->assertSame(0, $calculation->lines()->where('unit', WorkUnit::LinearMeter)->count());
        $this->assertSame('v07', $calculation->lines()->where('room_number', 'K-00-38')->value('product_code'));

        $table = (new CalculationRoomRows)->table($calculation->lines()->get());
        $skipped = collect($table['rows'])->firstWhere('room_number', 'A-00-14');
        $this->assertNotNull($skipped);
        $this->assertSame(CheckStatus::Certain, $skipped['status']);
        $this->assertFalse($skipped['needs_review']);
        $this->assertSame(0, $table['blocking_count']);
        $this->assertSame(0, $table['plinth_linked_count']);
        $this->assertSame(0, $table['plinth_meters_count']);
        $this->assertEqualsWithDelta(16.2, $table['square_meters'], 0.001);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertStringContainsString('Geen plint van toepassing', $skipped['status_label']);

        $totals = collect((new CalculationTotals)->grouped($calculation->lines()->get()));
        $this->assertSame('v04', $totals->firstWhere('product_code', 'v04')['product_code']);
        $this->assertSame('v07', $totals->firstWhere('product_code', 'v07')['product_code']);
        $this->assertNull($totals->firstWhere('unit', 'm1'));

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

    private function calculationWithV04(User $user, bool $withLegend = false): Calculation
    {
        $calculation = Calculation::query()->create([
            'name' => 'Toilet gietvloer',
            'dated_on' => '2026-03-06',
            'created_by' => $user->id,
        ]);
        if ($withLegend) {
            CalculationDrawing::query()->create([
                'calculation_id' => $calculation->id,
                'original_filename' => 'bg.pdf',
                'file_path' => 'calculations/'.$calculation->id.'/bg.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 10,
                'legend' => [
                    ['code' => 'v04', 'product' => 'Gietvloer v04'],
                    ['code' => 'pl01', 'product' => 'Aluminium plakplint'],
                    ['code' => 'pl02', 'product' => 'Holplint'],
                ],
            ]);
        }
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 1,
            'room_number' => 'A-00-14',
            'room_name' => 'T',
            'product_code' => 'v04',
            'product' => 'Gietvloer v04',
            'quantity' => 3.7,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);
        CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => 2,
            'room_number' => 'K-00-38',
            'room_name' => 'BERGING',
            'product_code' => 'v07',
            'product' => 'Coating',
            'quantity' => 12.5,
            'unit' => WorkUnit::SquareMeter,
            'source' => QuantitySource::FromDrawing,
        ]);

        return $calculation;
    }

    /**
     * @return array<string, mixed>
     */
    private function patchPayloadFromReviewForm(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $form = null;
        foreach ($dom->getElementsByTagName('form') as $candidate) {
            foreach ($candidate->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') === '_method' && $input->getAttribute('value') === 'PATCH') {
                    $form = $candidate;
                    break 2;
                }
            }
        }
        $this->assertNotNull($form);

        $payload = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $name = $input->getAttribute('name');
            if ($name === '' || $name === '_method') {
                continue;
            }
            if ($input->getAttribute('type') === 'checkbox' && ! $input->hasAttribute('checked')) {
                continue;
            }
            $this->assignFormValue($payload, $name, $input->getAttribute('value'));
        }
        foreach ($form->getElementsByTagName('select') as $select) {
            $name = $select->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $value = '';
            foreach ($select->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $value = $option->getAttribute('value');
                    break;
                }
            }
            $this->assignFormValue($payload, $name, $value);
        }
        unset($payload['_token']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tickSkipPlinth(array &$payload, int $floorId): void
    {
        foreach ($payload['lines'] ?? [] as $index => $line) {
            if ((int) ($line['id'] ?? 0) !== $floorId) {
                continue;
            }
            $payload['lines'][$index]['plinth_not_applicable'] = '1';
            $payload['lines'][$index]['plinth_choice'] = '';
        }
        $payload['plinth_decisions'] = json_encode([
            (string) $floorId => ['not_applicable' => true],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function choosePlinthProduct(array &$payload, int $floorId, string $code, string $product): void
    {
        foreach ($payload['lines'] ?? [] as $index => $line) {
            if ((int) ($line['id'] ?? 0) !== $floorId) {
                continue;
            }
            $payload['lines'][$index]['plinth_choice'] = $code;
            $payload['lines'][$index]['plinth_not_applicable'] = '0';
        }
        $payload['plinth_decisions'] = json_encode([
            (string) $floorId => [
                'not_applicable' => false,
                'product_code' => $code,
                'product' => $product,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assignFormValue(array &$payload, string $name, string $value): void
    {
        if (! preg_match('/^([^\[]+)((?:\[[^\]]*\])+)$/', $name, $matches)) {
            $payload[$name] = $value;

            return;
        }
        $path = [$matches[1]];
        preg_match_all('/\[([^\]]*)\]/', $matches[2], $keys);
        foreach ($keys[1] as $key) {
            $path[] = $key;
        }
        $cursor = &$payload;
        foreach ($path as $i => $key) {
            if ($i === count($path) - 1) {
                $cursor[$key] = $value;

                return;
            }
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
        }
    }
}
