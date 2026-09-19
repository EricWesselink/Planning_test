<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('whatsAppIds')]
    public function test_builds_a_whatsapp_id(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::whatsAppId($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whatsAppIds(): array
    {
        return [
            'dutch_dash' => ['06-57925505', '31657925505'],
            'dutch_plus' => ['+31 6 57925505', '31657925505'],
            'polish_zeros' => ['0048-690668857', '48690668857'],
            'polish_plus' => ['+48 690 668 857', '48690668857'],
        ];
    }

    public function test_formats_a_dutch_mobile_for_display(): void
    {
        $this->assertSame('06-57925505', PhoneNumber::display('06 57925505'));
    }

    public function test_rejects_empty_values(): void
    {
        $this->assertNull(PhoneNumber::whatsAppId(''));
        $this->assertNull(PhoneNumber::loginKey(null));
        $this->assertFalse(PhoneNumber::hasNumber(''));
        $this->assertNull(PhoneNumber::e164(null));
    }

    public function test_formats_a_dutch_mobile_as_e164(): void
    {
        $this->assertSame('+31612345678', PhoneNumber::e164('06 12345678'));
    }
}
