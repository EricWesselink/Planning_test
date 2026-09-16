<?php

namespace Tests\Unit;

use App\Enums\QuantitySource;
use App\Services\QuoteCalculation\PlinthLengthCalculator;
use Tests\TestCase;

class PlinthLengthCalculatorTest extends TestCase
{
    public function test_uses_fill_geometry_and_subtracts_the_nearby_door(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
            'square_meters' => 3.7,
        ], $this->pages(doorTexts: [
            ['text' => '920', 'x' => 25.0, 'y' => 8.0, 'page' => 1],
        ]));

        $this->assertSame(QuantitySource::Calculated->value, $result['source']);
        $this->assertSame(PlinthLengthCalculator::STATUS_NET, $result['status']);
        $this->assertEqualsWithDelta(6.932, (float) $result['meters'], 0.001);
        $this->assertEqualsWithDelta(7.852, (float) $result['gross'], 0.001);
        $this->assertSame([0.92], $result['doors']);
        $this->assertStringContainsString('deur', (string) $result['trace']);
        $this->assertStringNotContainsString('niet afgetrokken', (string) $result['trace']);
    }

    public function test_keeps_the_full_perimeter_when_the_door_is_not_reliable(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
            'square_meters' => 3.7,
        ], $this->pages(doorTexts: [
            ['text' => '400', 'x' => 25.0, 'y' => 8.0, 'page' => 1],
        ]));

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
        $this->assertSame([], $result['doors']);
        $this->assertSame('7,85 m¹ – volledige omtrek; deuropening niet afgetrokken.', $result['trace']);
    }

    public function test_subtracts_only_the_reliable_doors_of_a_mixed_set(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
            'square_meters' => 3.7,
        ], $this->pages(doorTexts: [
            ['text' => '920', 'x' => 25.0, 'y' => 8.0, 'page' => 1],
            ['text' => '400', 'x' => 28.0, 'y' => 10.0, 'page' => 1],
        ]));

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(6.932, (float) $result['meters'], 0.001);
        $this->assertSame([0.92], $result['doors']);
        $this->assertStringContainsString('alleen betrouwbaar herkende deuropeningen', (string) $result['trace']);
    }

    public function test_uses_a_labelled_perimeter_without_inventing_a_door_deduction(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-13', 'x' => 20.0, 'y' => 15.0, 'page' => 1],
                ['text' => 'omtrek 12,84', 'x' => 22.0, 'y' => 18.0, 'page' => 1],
            ],
            'fills' => [],
        ]]);

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(12.84, (float) $result['meters'], 0.001);
        $this->assertSame('12,84 m¹ – volledige omtrek; deuropening niet afgetrokken.', $result['trace']);
    }

    public function test_uses_a_nearby_fill_when_the_room_number_sits_just_outside(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-13', 'x' => 26.0, 'y' => 15.0, 'page' => 1],
            ],
            'fills' => [[
                'x' => 0.0,
                'y' => 0.0,
                'width' => 20.0,
                'height' => 30.0,
                'area' => 600.0,
            ]],
        ]]);

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
    }

    public function test_ceils_a_perimeter_instead_of_rounding_down(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
            'square_meters' => 3.7,
        ], $this->pages());

        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
        $this->assertGreaterThan(7.851, (float) $result['meters']);
    }

    public function test_uses_surrounding_walls_when_the_fill_is_missing(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-15',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-15', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 30.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
    }

    public function test_closes_a_wall_gap_at_a_door_opening(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-15',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-15', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 8.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 14.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 30.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
    }

    public function test_uses_an_l_shaped_wall_contour_instead_of_the_bounding_box(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-21',
            'square_meters' => 12.0,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-21', 'x' => 10.0, 'y' => 990.0, 'page' => 1],
                ['text' => '12,0', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
                ['text' => 'm²', 'x' => 18.0, 'y' => 985.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 40.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 20.0, 'x2' => 20.0, 'y2' => 40.0, 'axis' => 'v'],
                ['x1' => 40.0, 'y1' => 0.0, 'x2' => 40.0, 'y2' => 20.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 40.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 20.0, 'y1' => 20.0, 'x2' => 40.0, 'y2' => 20.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 40.0, 'x2' => 20.0, 'y2' => 40.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertSame(PlinthLengthCalculator::STATUS_GENEROUS, $result['status']);
        $this->assertEqualsWithDelta(16.0, (float) $result['meters'], 0.001);
    }

    public function test_uses_the_area_label_when_the_room_number_sits_outside_the_walls(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-15',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-15', 'x' => 10.0, 'y' => 940.0, 'page' => 1],
                ['text' => '3,7', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
                ['text' => 'm²', 'x' => 18.0, 'y' => 985.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 30.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
    }

    public function test_stops_an_open_door_at_the_neighbouring_room_label(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-15',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-15', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
                ['text' => 'A-00-16', 'x' => 30.0, 'y' => 985.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 80.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 5.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 72.0, 'x2' => 20.0, 'y2' => 80.0, 'axis' => 'v'],
                ['x1' => 40.0, 'y1' => 0.0, 'x2' => 40.0, 'y2' => 80.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 40.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 80.0, 'x2' => 40.0, 'y2' => 80.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertEqualsWithDelta(9.618, (float) $result['meters'], 0.001);
    }

    public function test_uses_the_floorplan_label_instead_of_a_legend_copy(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-15',
            'square_meters' => 3.7,
        ], [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'geometry_width' => 1000.0,
            'geometry_height' => 1000.0,
            'texts' => [
                ['text' => 'A-00-15', 'x' => 800.0, 'y' => 40.0, 'page' => 1],
                ['text' => 'renvooi', 'x' => 810.0, 'y' => 40.0, 'page' => 1],
                ['text' => 'A-00-15', 'x' => 10.0, 'y' => 985.0, 'page' => 1],
                ['text' => '3,7', 'x' => 10.0, 'y' => 980.0, 'page' => 1],
                ['text' => 'm²', 'x' => 18.0, 'y' => 980.0, 'page' => 1],
            ],
            'fills' => [],
            'walls' => [
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 20.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'v'],
                ['x1' => 0.0, 'y1' => 0.0, 'x2' => 20.0, 'y2' => 0.0, 'axis' => 'h'],
                ['x1' => 0.0, 'y1' => 30.0, 'x2' => 20.0, 'y2' => 30.0, 'axis' => 'h'],
            ],
        ]]);

        $this->assertEqualsWithDelta(7.852, (float) $result['meters'], 0.001);
    }

    public function test_does_not_invent_a_length_without_geometry_or_area(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-13',
        ], []);

        $this->assertNull($result['meters']);
        $this->assertSame(QuantitySource::Review->value, $result['source']);
        $this->assertSame(PlinthLengthCalculator::STATUS_MISSING, $result['status']);
        $this->assertStringContainsString('Ruimtecontour/omtrek niet betrouwbaar', (string) $result['trace']);
    }

    public function test_estimates_a_generous_length_from_area_when_the_contour_is_missing(): void
    {
        $result = (new PlinthLengthCalculator)->forRoom([
            'room_number' => 'A-00-01',
            'square_meters' => 36.0,
        ], []);

        $squareMinimum = 4 * sqrt(36.0);
        $this->assertSame(PlinthLengthCalculator::STATUS_ESTIMATED, $result['status']);
        $this->assertSame(QuantitySource::Calculated->value, $result['source']);
        $this->assertGreaterThan($squareMinimum, (float) $result['meters']);
        $this->assertEqualsWithDelta(26.564, (float) $result['meters'], 0.001);
        $this->assertStringContainsString('ruime calculatieschatting', (string) $result['trace']);
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $doorTexts
     * @return list<array<string, mixed>>
     */
    private function pages(array $doorTexts = []): array
    {
        return [[
            'page' => 1,
            'width' => 1000.0,
            'height' => 1000.0,
            'texts' => array_merge([
                ['text' => 'A-00-13', 'x' => 20.0, 'y' => 15.0, 'page' => 1],
            ], $doorTexts),
            'fills' => [[
                'x' => 0.0,
                'y' => 0.0,
                'width' => 20.0,
                'height' => 30.0,
                'area' => 600.0,
            ]],
        ]];
    }
}
