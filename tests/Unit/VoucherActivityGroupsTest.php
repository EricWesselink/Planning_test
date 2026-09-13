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
}
