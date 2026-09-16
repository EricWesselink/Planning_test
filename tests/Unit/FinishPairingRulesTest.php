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
}
