<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\RoomNumberRange;
use Tests\TestCase;

class RoomNumberRangeTest extends TestCase
{
    public function test_expands_a_hyphen_range_into_the_individual_room_numbers(): void
    {
        $numbers = (new RoomNumberRange)->expand('A-00-14-17');

        $this->assertSame(['A-00-14', 'A-00-15', 'A-00-16', 'A-00-17'], $numbers);
    }

    public function test_expands_a_slash_range_the_same_way(): void
    {
        $numbers = (new RoomNumberRange)->expand('K-00-23/26');

        $this->assertSame(['K-00-23', 'K-00-24', 'K-00-25', 'K-00-26'], $numbers);
    }

    public function test_keeps_a_single_room_number_unchanged(): void
    {
        $this->assertSame(['A-00-14'], (new RoomNumberRange)->expand('A-00-14'));
    }

    public function test_does_not_expand_a_backwards_or_huge_range(): void
    {
        $this->assertSame(['A-00-17-14'], (new RoomNumberRange)->expand('A-00-17-14'));
        $this->assertSame(['A-00-01-99'], (new RoomNumberRange)->expand('A-00-01-99'));
    }
}
