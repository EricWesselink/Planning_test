<?php

namespace Tests\Unit\Support;

use App\Support\DutchNumber;
use Tests\TestCase;

class DutchNumberTest extends TestCase
{
    public function test_machine_decimals_are_not_read_as_dutch_thousands(): void
    {
        $this->assertEqualsWithDelta(1.105, DutchNumber::fromMachine('1.105'), 0.0001);
        $this->assertEqualsWithDelta(3.6975, DutchNumber::fromMachine('3.6974999999999998'), 0.0001);
        $this->assertEqualsWithDelta(3.7, DutchNumber::fromMachine('3,70'), 0.0001);
        $this->assertEqualsWithDelta(24.5, DutchNumber::fromMachine('24.5'), 0.0001);
    }

    public function test_dutch_grouped_thousands_still_parse_from_meetstaat_text(): void
    {
        $this->assertSame(1500.0, DutchNumber::parse('1.500'));
        $this->assertSame(1105.0, DutchNumber::parse('1.105'));
    }
}
