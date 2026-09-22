<?php

namespace Tests\Unit;

use App\Enums\FinishRole;
use App\Services\QuoteCalculation\RoomFloorFinishes;
use Tests\TestCase;

class RoomFloorFinishesTest extends TestCase
{
    public function test_a_single_floor_code_keeps_the_full_room_area(): void
    {
        $floors = (new RoomFloorFinishes)->split(['v01.d'], 78.9);

        $this->assertCount(1, $floors);
        $this->assertSame('v01.d', $floors[0]['code']);
        $this->assertSame(FinishRole::Main->value, $floors[0]['role']);
        $this->assertEqualsWithDelta(78.9, (float) $floors[0]['quantity'], 0.001);
    }

    public function test_a_local_finish_never_receives_the_full_room_area(): void
    {
        $floors = (new RoomFloorFinishes)->split(['v01.d', 'v09'], 78.9, [78.9]);

        $this->assertSame('v09', $floors[1]['code']);
        $this->assertSame(FinishRole::Local->value, $floors[1]['role']);
        $this->assertNull($floors[1]['quantity']);
        $this->assertEqualsWithDelta(78.9, (float) $floors[0]['quantity'], 0.001);
        $this->assertFalse((new RoomFloorFinishes)->matchesRoomArea($floors, 78.9));
    }

    public function test_the_main_finish_gets_the_remainder_when_local_areas_are_known(): void
    {
        $floors = (new RoomFloorFinishes)->split(['v01.d', 'v09'], 78.9, [4.2]);

        $this->assertEqualsWithDelta(74.7, (float) $floors[0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(4.2, (float) $floors[1]['quantity'], 0.001);
        $this->assertTrue((new RoomFloorFinishes)->matchesRoomArea($floors, 78.9));
    }

    public function test_the_remainder_is_rounded_to_two_decimals(): void
    {
        $floors = (new RoomFloorFinishes)->split(['v01.d', 'v09'], 78.9, [6.24]);

        $this->assertEqualsWithDelta(72.66, (float) $floors[0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(6.24, (float) $floors[1]['quantity'], 0.001);
        $this->assertTrue((new RoomFloorFinishes)->matchesRoomArea($floors, 78.9));
    }

    public function test_several_local_finishes_are_subtracted_from_the_main_finish(): void
    {
        $roomArea = 78.9;
        $floors = (new RoomFloorFinishes)->split(['v01.d', 'v09', 'v10'], $roomArea, [6.04, 4.2]);

        $this->assertEqualsWithDelta(68.66, (float) $floors[0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(6.04, (float) $floors[1]['quantity'], 0.001);
        $this->assertEqualsWithDelta(4.2, (float) $floors[2]['quantity'], 0.001);
        $this->assertTrue((new RoomFloorFinishes)->matchesRoomArea($floors, $roomArea));
    }

    public function test_the_main_finish_never_becomes_negative(): void
    {
        $floors = (new RoomFloorFinishes)->split(['v01.d', 'v09', 'v10'], 10.0, [6.0, 6.0]);

        $this->assertNull($floors[0]['quantity']);
        $this->assertEqualsWithDelta(6.0, (float) $floors[1]['quantity'], 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $floors[2]['quantity'], 0.001);
        foreach ($floors as $finish) {
            $this->assertTrue($finish['quantity'] === null || $finish['quantity'] >= 0);
        }
    }
}
