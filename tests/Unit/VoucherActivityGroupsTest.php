<?php

namespace Tests\Unit;

use App\Enums\VoucherPriceKind;
use App\Enums\WorkUnit;
use App\Support\VoucherActivityGroups;
use Tests\TestCase;

class VoucherActivityGroupsTest extends TestCase
{
    public function test_groups_rooms_of_the_same_work_item_into_one_activity(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'work_item_id' => 12,
                'project_area_id' => 1,
                'room_label' => '1.62 oefenruimte',
                'description' => 'Primen & Egaliseren',
                'quantity' => 83.65,
                'unit' => WorkUnit::SquareMeter,
                'unit_price' => 2,
                'amount' => 167.3,
                'price_kind' => VoucherPriceKind::Unit,
            ],
            [
                'work_item_id' => 12,
                'project_area_id' => 2,
                'room_label' => '1.64 cabine',
                'description' => 'Primen & Egaliseren',
                'quantity' => 16.31,
                'unit' => WorkUnit::SquareMeter,
                'unit_price' => 2,
                'amount' => 32.62,
                'price_kind' => VoucherPriceKind::Unit,
            ],
            [
                'work_item_id' => 12,
                'project_area_id' => 3,
                'room_label' => '1.65 cabine 3',
                'description' => 'Primen & Egaliseren',
                'quantity' => 16.33,
                'unit' => WorkUnit::SquareMeter,
                'unit_price' => 2,
                'amount' => 32.66,
                'price_kind' => VoucherPriceKind::Unit,
            ],
            [
                'work_item_id' => 12,
                'project_area_id' => 4,
                'room_label' => '1.67 behandelkamer groot/kracht',
                'description' => 'Primen & Egaliseren',
                'quantity' => 26.72,
                'unit' => WorkUnit::SquareMeter,
                'unit_price' => 2,
                'amount' => 53.44,
                'price_kind' => VoucherPriceKind::Unit,
            ],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame('Primen & Egaliseren', $groups[0]['description']);
        $this->assertSame(143.01, $groups[0]['quantity']);
        $this->assertSame(286.02, $groups[0]['amount']);
        $this->assertTrue($groups[0]['has_rooms']);
        $this->assertCount(4, $groups[0]['entries']);
        $this->assertSame('1.62 oefenruimte', $groups[0]['entries'][0]['room_label']);
        $this->assertSame('1.67 behandelkamer groot/kracht', $groups[0]['entries'][3]['room_label']);
    }

    public function test_strips_room_prefix_from_stored_descriptions(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'work_item_id' => 8,
                'project_area_id' => 1,
                'description' => '0.02 groepsruimte · Primen & Egaliseren',
                'quantity' => 50.25,
                'unit' => 'm2',
                'unit_price' => 5.5,
                'amount' => 276.38,
            ],
            [
                'work_item_id' => 8,
                'project_area_id' => 2,
                'description' => '0.03 groepsruimte · Primen & Egaliseren',
                'quantity' => 51.16,
                'unit' => 'm2',
                'unit_price' => 5.5,
                'amount' => 281.38,
            ],
        ]);

        $this->assertSame('Primen & Egaliseren', $groups[0]['description']);
        $this->assertSame('0.02 groepsruimte', $groups[0]['entries'][0]['room_label']);
        $this->assertSame('0.03 groepsruimte', $groups[0]['entries'][1]['room_label']);
        $this->assertSame(101.41, $groups[0]['quantity']);
    }

    public function test_keeps_custom_lines_without_work_item_separate(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'description' => 'Plinten wit',
                'quantity' => 40,
                'unit' => 'm1',
                'unit_price' => 8,
                'amount' => 320,
            ],
            [
                'description' => 'Meerwerk',
                'quantity' => 1,
                'unit' => 'stuks',
                'unit_price' => 50,
                'amount' => 50,
            ],
        ]);

        $this->assertCount(2, $groups);
        $this->assertFalse($groups[0]['has_rooms']);
        $this->assertFalse($groups[1]['has_rooms']);
        $this->assertSame('Plinten wit', $groups[0]['description']);
        $this->assertSame('Meerwerk', $groups[1]['description']);
    }

    public function test_activity_key_is_the_same_for_every_room_of_a_work_item(): void
    {
        $this->assertSame(
            'item:12:m2',
            VoucherActivityGroups::activityKey(12, WorkUnit::SquareMeter, '1.62 oefenruimte · Primen & Egaliseren'),
        );
        $this->assertSame(
            'item:12:m2',
            VoucherActivityGroups::activityKey(12, 'm2', '1.64 cabine · Primen & Egaliseren'),
        );
        $this->assertSame(
            'custom:plinten wit:m1',
            VoucherActivityGroups::activityKey(null, WorkUnit::LinearMeter, 'Plinten wit'),
        );
    }

    public function test_hours_activity_shows_room_square_meters_as_specification(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'work_item_id' => 12,
                'project_area_id' => 1,
                'room_label' => '1.62 oefenruimte',
                'description' => 'Primen & Egaliseren',
                'quantity' => 8,
                'unit' => WorkUnit::Hours,
                'unit_price' => 45,
                'amount' => 360,
                'price_kind' => VoucherPriceKind::Unit,
                'spec_m2' => 83.65,
            ],
            [
                'work_item_id' => 12,
                'project_area_id' => 2,
                'room_label' => '1.64 cabine',
                'description' => 'Primen & Egaliseren',
                'quantity' => 0,
                'unit' => WorkUnit::Hours,
                'unit_price' => 45,
                'amount' => 0,
                'price_kind' => VoucherPriceKind::Unit,
                'spec_m2' => 16.31,
            ],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(8.0, $groups[0]['quantity']);
        $this->assertSame(360.0, $groups[0]['amount']);
        $this->assertSame('8,00 uren', VoucherActivityGroups::quantityLabel($groups[0]));
        $this->assertSame('€ 45,00/uren', VoucherActivityGroups::priceLabel($groups[0]));
        $this->assertSame('83,65 m²', VoucherActivityGroups::roomQuantityLabel($groups[0], $groups[0]['entries'][0]));
        $this->assertSame('16,31 m²', VoucherActivityGroups::roomQuantityLabel($groups[0], $groups[0]['entries'][1]));
        $this->assertSame('m²', VoucherActivityGroups::roomSpecUnitLabel($groups[0]['unit']));
        $this->assertSame('', VoucherActivityGroups::periodLabel($groups[0]));
    }

    public function test_period_label_shows_the_weekday_and_iso_week(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'work_item_id' => 12,
                'project_area_id' => 1,
                'room_label' => '1.62 oefenruimte',
                'description' => 'Primen & Egaliseren',
                'quantity' => 8,
                'unit' => WorkUnit::Hours,
                'unit_price' => 43,
                'amount' => 344,
                'worked_on' => '2026-09-13',
            ],
        ]);

        $this->assertSame('zo 13-09-2026 · week 37', VoucherActivityGroups::periodLabel($groups[0]));
    }

    public function test_period_label_shows_a_range_and_weeks_when_rooms_differ(): void
    {
        $groups = VoucherActivityGroups::fromFormLines([
            [
                'work_item_id' => 12,
                'project_area_id' => 1,
                'room_label' => '1.62 oefenruimte',
                'description' => 'Primen & Egaliseren',
                'quantity' => 8,
                'unit' => WorkUnit::Hours,
                'unit_price' => 43,
                'amount' => 344,
                'worked_on' => '2026-09-02',
            ],
            [
                'work_item_id' => 12,
                'project_area_id' => 2,
                'room_label' => '1.64 cabine',
                'description' => 'Primen & Egaliseren',
                'quantity' => 0,
                'unit' => WorkUnit::Hours,
                'unit_price' => 43,
                'amount' => 0,
                'worked_on' => '2026-09-13',
            ],
        ]);

        $this->assertSame('wo 02-09-2026 – zo 13-09-2026 · week 36–37', VoucherActivityGroups::periodLabel($groups[0]));
        $this->assertSame('wo 02-09-2026 · week 36', VoucherActivityGroups::roomPeriodLabel($groups[0]['entries'][0]));
        $this->assertSame('zo 13-09-2026 · week 37', VoucherActivityGroups::roomPeriodLabel($groups[0]['entries'][1]));
    }
}
