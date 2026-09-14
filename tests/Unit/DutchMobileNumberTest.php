<?php

namespace Tests\Unit;

use App\Support\DutchMobileNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DutchMobileNumberTest extends TestCase
{
    #[DataProvider('mobileNumbers')]
    public function test_normalizes_dutch_mobile_numbers_to_06_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, DutchMobileNumber::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mobileNumbers(): array
    {
        return [
            'digits' => ['0612345678', '0612345678'],
            'spaces' => ['06 12345678', '0612345678'],
            'dash' => ['06-12345678', '0612345678'],
            'plus' => ['+31612345678', '0612345678'],
            'plus_spaced' => ['+31 6 12345678', '0612345678'],
            'international_zeros' => ['0031612345678', '0612345678'],
        ];
    }

    public function test_rejects_values_that_are_not_dutch_mobiles(): void
    {
        $this->assertNull(DutchMobileNumber::normalize('0381234567'));
        $this->assertNull(DutchMobileNumber::normalize('nick@example.test'));
        $this->assertNull(DutchMobileNumber::normalize(''));
        $this->assertNull(DutchMobileNumber::normalize(null));
    }
}
