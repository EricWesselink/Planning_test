<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\RoomDraftFilter;
use Tests\TestCase;

class RoomDraftFilterTest extends TestCase
{
    public function test_drops_a_row_without_number_name_area_or_material(): void
    {
        $this->assertTrue((new RoomDraftFilter)->isEmpty([
            'room_number' => '',
            'room_name' => null,
            'square_meters' => null,
            'floor_code' => null,
            'plinth_code' => null,
        ]));
    }

    public function test_keeps_a_row_that_has_a_room_number(): void
    {
        $this->assertFalse((new RoomDraftFilter)->isEmpty([
            'room_number' => 'A-00-04',
        ]));
    }

    public function test_marks_a_room_without_nearby_floor_work_as_not_applicable(): void
    {
        $filter = new RoomDraftFilter;

        $this->assertTrue($filter->notApplicable([
            'room_number' => 'A-02-01',
            'room_name' => 'INST prefab',
            'square_meters' => 281.2,
            'floor_work' => false,
        ]));
        $this->assertFalse($filter->notApplicable([
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'square_meters' => 4.0,
            'floor_code' => 'v01',
            'floor_work' => false,
        ]));
    }
}
