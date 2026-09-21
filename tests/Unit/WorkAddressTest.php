<?php

namespace Tests\Unit;

use App\Support\WorkAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkAddressTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_parses_a_dutch_address_without_dropping_text(
        string $line,
        ?string $street,
        ?string $postal,
        ?string $city,
        string $stored,
    ): void {
        $parsed = WorkAddress::parse($line);

        $this->assertSame($street, $parsed['address']);
        $this->assertSame($postal, $parsed['postal_code']);
        $this->assertSame($city, $parsed['city']);
        $this->assertSame($stored, $parsed['work_address']);
    }

    public static function addresses(): array
    {
        return [
            'full address with addition' => [
                'Willem Schuylenburglaan 40-9, 3571 SJ Utrecht',
                'Willem Schuylenburglaan 40-9',
                '3571 SJ',
                'Utrecht',
                'Willem Schuylenburglaan 40-9, 3571 SJ Utrecht',
            ],
            'letter addition' => [
                'Kerkstraat 12A, 1234 AB Amsterdam',
                'Kerkstraat 12A',
                '1234 AB',
                'Amsterdam',
                'Kerkstraat 12A, 1234 AB Amsterdam',
            ],
            'bis addition' => [
                'Kerkstraat 12 bis, 1234 AB Amsterdam',
                'Kerkstraat 12 bis',
                '1234 AB',
                'Amsterdam',
                'Kerkstraat 12 bis, 1234 AB Amsterdam',
            ],
            'postal code without a space' => [
                'Kerkstraat 12, 3571SJ Utrecht',
                'Kerkstraat 12',
                '3571 SJ',
                'Utrecht',
                'Kerkstraat 12, 3571 SJ Utrecht',
            ],
            'no postal code' => [
                'Kerkstraat 12, Utrecht',
                'Kerkstraat 12',
                null,
                'Utrecht',
                'Kerkstraat 12, Utrecht',
            ],
            'street only' => [
                'Kerkstraat 12',
                'Kerkstraat 12',
                null,
                null,
                'Kerkstraat 12',
            ],
            'place only' => [
                'Utrecht',
                null,
                null,
                'Utrecht',
                'Utrecht',
            ],
            'postal code and place' => [
                '3571 SJ Utrecht',
                null,
                '3571 SJ',
                'Utrecht',
                '3571 SJ Utrecht',
            ],
            'addition is not treated as a postal code' => [
                'Kerkstraat 12, 40-9',
                'Kerkstraat 12, 40-9',
                null,
                null,
                'Kerkstraat 12, 40-9',
            ],
        ];
    }

    public function test_compose_joins_only_the_parts_that_exist(): void
    {
        $this->assertSame('Kerkstraat 12, Utrecht', WorkAddress::compose('Kerkstraat 12', null, 'Utrecht'));
        $this->assertSame('Kerkstraat 12', WorkAddress::compose('Kerkstraat 12', '  ', null));
        $this->assertSame('3571 SJ Utrecht', WorkAddress::compose(null, '3571 SJ', 'Utrecht'));
        $this->assertNull(WorkAddress::compose(null, null, null));
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertSame([
            'work_address' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
        ], WorkAddress::parse('   '));
    }
}
