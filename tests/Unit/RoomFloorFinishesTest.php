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
}
