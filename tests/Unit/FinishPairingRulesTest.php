<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\FinishPairingRules;
use Tests\TestCase;

class FinishPairingRulesTest extends TestCase
{
    public function test_assigns_holplint_to_gietvloer_rooms_without_a_plinth_code(): void
    {
        $rooms = (new FinishPairingRules)->apply([
            [
                'room_number' => 'A-00-13',
                'floor_code' => 'v04',
                'plinth_code' => null,
            ],
            [
                'room_number' => 'A-00-12',
                'floor_code' => 'v02',
                'plinth_code' => 'pl01',
            ],
        ], [
            ['code' => 'pl01', 'product' => 'Aluminium plakplint', 'kind' => 'plinth'],
            ['code' => 'pl02', 'product' => 'Holplint', 'kind' => 'plinth'],
        ]);

        $this->assertSame('pl02', $rooms[0]['plinth_code']);
        $this->assertSame('Holplint', $rooms[0]['plinth_product']);
        $this->assertTrue($rooms[0]['plinth_inferred']);
        $this->assertSame('pl01', $rooms[1]['plinth_code']);
        $this->assertSame('Aluminium plakplint', $rooms[1]['plinth_product']);
    }

    public function test_defaults_holplint_product_when_the_legend_has_no_name(): void
    {
        $rooms = (new FinishPairingRules)->apply([
            [
                'room_number' => 'A-00-13',
                'floor_code' => 'v04',
            ],
        ], []);

        $this->assertSame('pl02', $rooms[0]['plinth_code']);
        $this->assertSame('Holplint', $rooms[0]['plinth_product']);
    }

    public function test_keeps_a_detected_plakplint_code_on_a_gietvloer_room(): void
    {
        $rooms = (new FinishPairingRules)->apply([
            [
                'room_number' => 'A-00-15',
                'floor_code' => 'v04',
                'plinth_code' => 'pl01',
                'plinth_product' => 'Holplint',
            ],
        ], [
            ['code' => 'pl01', 'product' => 'Aluminium plakplint', 'kind' => 'plinth'],
            ['code' => 'pl02', 'product' => 'Holplint', 'kind' => 'plinth'],
        ]);

        $this->assertSame('pl01', $rooms[0]['plinth_code']);
        $this->assertSame('Aluminium plakplint', $rooms[0]['plinth_product']);
        $this->assertFalse($rooms[0]['plinth_inferred']);
    }

    public function test_does_not_keep_holplint_as_the_product_for_plakplint(): void
    {
        $this->assertNull(FinishPairingRules::productFor('pl01', [
            'pl01' => 'Holplint',
            'pl02' => 'Holplint',
        ], 'Holplint'));
        $this->assertSame('Aluminium plakplint', FinishPairingRules::productFor('pl01', [
            'pl01' => 'Aluminium plakplint',
            'pl02' => 'Holplint',
        ], 'Holplint'));
    }

    public function test_prefers_a_specific_variant_over_a_family_code(): void
    {
        $this->assertTrue(FinishPairingRules::compatible('v01', 'v01.d'));
        $this->assertFalse(FinishPairingRules::compatible('v01.d', 'v01.a'));
        $this->assertSame('v01.d', FinishPairingRules::prefer('v01', 'v01.d'));
        $this->assertSame('v01.d', FinishPairingRules::prefer('v01.d', 'v01'));
        $this->assertTrue(FinishPairingRules::hasVariants('v01', ['v01.a', 'v01.d', 'v04']));
        $this->assertFalse(FinishPairingRules::hasVariants('v04', ['v01.a', 'v04']));
    }

    public function test_groups_specific_floor_variants_from_the_legend(): void
    {
        $grouped = FinishPairingRules::variantsFromLegend([
            ['code' => 'v01.d', 'product' => 'Marmoleum d', 'kind' => 'floor'],
            ['code' => 'v01.a', 'product' => 'Marmoleum a', 'kind' => 'floor'],
            ['code' => 'v01', 'product' => 'Marmoleum', 'kind' => 'floor'],
            ['code' => 'v04', 'product' => 'Gietvloer', 'kind' => 'floor'],
            ['code' => 'pl02', 'product' => 'Holplint', 'kind' => 'plinth'],
        ]);

        $this->assertSame(['v01.a', 'v01.d'], array_column($grouped['v01'], 'code'));
        $this->assertSame('Marmoleum a', $grouped['v01'][0]['product']);
        $this->assertArrayNotHasKey('v04', $grouped);
        $this->assertArrayNotHasKey('pl02', $grouped);
    }
}
