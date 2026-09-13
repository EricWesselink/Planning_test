<?php

namespace Tests\Unit;

use App\Support\VoucherWorkedPeriod;
use Tests\TestCase;

class VoucherWorkedPeriodTest extends TestCase
{
    public function test_weekdays_in_a_week_become_those_dates(): void
    {
        $dates = VoucherWorkedPeriod::datesFromInput(null, 2026, 44, [1, 2]);

        $this->assertSame(['2026-10-26', '2026-10-27'], $dates);
        $this->assertSame('ma 26-10-2026 – di 27-10-2026 · week 44', VoucherWorkedPeriod::label($dates));
        $this->assertSame('ma 26-10-2026, di 27-10-2026', VoucherWorkedPeriod::datesListLabel($dates));
        $this->assertSame('2026-10-26', VoucherWorkedPeriod::weekdayDates(2026, 44)[1]);
    }

    public function test_week_37_tuesday_and_wednesday_are_the_eighth_and_ninth_of_september(): void
    {
        $dates = VoucherWorkedPeriod::datesFromInput(null, 2026, 37, [2, 3]);

        $this->assertSame(['2026-09-08', '2026-09-09'], $dates);
        $this->assertSame('di 08-09-2026, wo 09-09-2026', VoucherWorkedPeriod::datesListLabel($dates));
    }

    public function test_a_date_is_used_when_no_weekdays_are_ticked(): void
    {
        $dates = VoucherWorkedPeriod::datesFromInput('2026-09-13', 2026, 37, []);

        $this->assertSame(['2026-09-13'], $dates);
        $this->assertSame('zo 13-09-2026 · week 37', VoucherWorkedPeriod::label($dates));
    }

    public function test_a_week_without_days_covers_monday_through_saturday(): void
    {
        $dates = VoucherWorkedPeriod::datesFromInput(null, 2026, 44, []);

        $this->assertSame([
            '2026-10-26',
            '2026-10-27',
            '2026-10-28',
            '2026-10-29',
            '2026-10-30',
            '2026-10-31',
        ], $dates);
        $this->assertSame('ma 26-10-2026 – za 31-10-2026 · week 44', VoucherWorkedPeriod::label($dates));
    }

    public function test_changing_the_week_ignores_a_date_from_another_week(): void
    {
        $dates = VoucherWorkedPeriod::datesFromInput('2026-09-13', 2026, 44, []);

        $this->assertSame('2026-10-26', $dates[0] ?? null);
        $this->assertSame(6, count($dates));
    }
}
