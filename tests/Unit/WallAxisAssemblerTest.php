<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\WallAxisAssembler;
use Tests\TestCase;

class WallAxisAssemblerTest extends TestCase
{
    public function test_collapses_parallel_edges_to_a_midline_and_labels_the_outer_facade(): void
    {
        $assembled = (new WallAxisAssembler)->assemble($this->buildingWithDoubleOuter(), 600.0, 700.0);

        $right = $this->verticalAt($assembled['walls'], 396.0);
        $left = $this->verticalAt($assembled['walls'], 100.0);

        $this->assertNotNull($right);
        $this->assertNotNull($left);
        $this->assertSame(WallAxisAssembler::KIND_PAIR, $right['kind']);
        $this->assertSame(WallAxisAssembler::ROLE_OUTER, $right['role']);
        $this->assertSame(WallAxisAssembler::ROLE_OUTER, $left['role']);
        $this->assertNull($this->verticalAt($assembled['walls'], 390.0));
        $this->assertNull($this->verticalAt($assembled['walls'], 402.0));
    }

    public function test_does_not_treat_an_isolated_dimension_line_as_an_outer_wall(): void
    {
        $assembled = (new WallAxisAssembler)->assemble($this->buildingWithOuterDimension(), 600.0, 800.0);

        $dimension = $this->horizontalAt($assembled['axes'], 720.0);

        $this->assertNotNull($dimension);
        $this->assertSame(WallAxisAssembler::ROLE_DIMENSION, $dimension['role']);
        $this->assertNotNull($this->horizontalAt($assembled['walls'], 500.0));
        $this->assertSame(WallAxisAssembler::ROLE_OUTER, $this->horizontalAt($assembled['walls'], 500.0)['role']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildingWithDoubleOuter(): array
    {
        return [
            ['x1' => 100.0, 'y1' => 100.0, 'x2' => 100.0, 'y2' => 500.0, 'axis' => 'v'],
            ['x1' => 390.0, 'y1' => 100.0, 'x2' => 390.0, 'y2' => 500.0, 'axis' => 'v'],
            ['x1' => 402.0, 'y1' => 100.0, 'x2' => 402.0, 'y2' => 500.0, 'axis' => 'v'],
            ['x1' => 100.0, 'y1' => 100.0, 'x2' => 402.0, 'y2' => 100.0, 'axis' => 'h'],
            ['x1' => 100.0, 'y1' => 500.0, 'x2' => 402.0, 'y2' => 500.0, 'axis' => 'h'],
            ['x1' => 250.0, 'y1' => 100.0, 'x2' => 250.0, 'y2' => 500.0, 'axis' => 'v'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildingWithOuterDimension(): array
    {
        return [
            ['x1' => 120.0, 'y1' => 120.0, 'x2' => 120.0, 'y2' => 500.0, 'axis' => 'v'],
            ['x1' => 420.0, 'y1' => 120.0, 'x2' => 420.0, 'y2' => 500.0, 'axis' => 'v'],
            ['x1' => 120.0, 'y1' => 120.0, 'x2' => 420.0, 'y2' => 120.0, 'axis' => 'h'],
            ['x1' => 120.0, 'y1' => 500.0, 'x2' => 420.0, 'y2' => 500.0, 'axis' => 'h'],
            ['x1' => 20.0, 'y1' => 720.0, 'x2' => 580.0, 'y2' => 720.0, 'axis' => 'h'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return array<string, mixed>|null
     */
    private function verticalAt(array $walls, float $x): ?array
    {
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'v' && abs((float) $wall['x1'] - $x) < 0.6) {
                return $wall;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return array<string, mixed>|null
     */
    private function horizontalAt(array $walls, float $y): ?array
    {
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'h' && abs((float) $wall['y1'] - $y) < 0.6) {
                return $wall;
            }
        }

        return null;
    }
}
