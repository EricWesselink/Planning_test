<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\ShopOrderFinance;
use Tests\TestCase;

class ShopOrderFinanceTest extends TestCase
{
    public function test_profit_uses_actual_costs_and_keeps_planned_hours_out_of_the_result(): void
    {
        $finance = $this->finance(
            order: '10000.00',
            budget: 6000,
            actual: 7500,
            plannedHours: 40,
            actualHours: 156.25,
        );

        $this->assertSame('10000.00', $finance['order_amount']);
        $this->assertSame('€10.000', $finance['order_label']);
        $this->assertSame('6000.00', $finance['budget_cost']);
        $this->assertSame('7500.00', $finance['actual_cost']);
        $this->assertSame('4000.00', $finance['budget_result']);
        $this->assertSame('2500.00', $finance['actual_result']);
        $this->assertSame(25.0, $finance['actual_margin_percent']);
        $this->assertSame('1500.00', $finance['cost_delta']);
        $this->assertSame(25.0, $finance['cost_delta_percent']);
        $this->assertSame('over', $finance['budget_tone']);
        $this->assertSame('ok', $finance['result_tone']);
        $this->assertSame(
            'Order €10.000 | Kosten €7.500 | Resultaat +€2.500 (25%) | Begroting +€1.500 (25%)',
            $finance['compact'],
        );
        $this->assertSame('€1.500 boven begroting · 25%', $finance['overrun_line']);
        $this->assertSame('1920.00', $finance['forecast_cost']);
    }

    public function test_planned_hours_do_not_count_as_actual_cost_or_loss(): void
    {
        $finance = $this->finance(
            order: '10000.00',
            budget: 384,
            actual: 0,
            plannedHours: 40,
            actualHours: 0,
            rate: 48,
        );

        $this->assertSame('0.00', $finance['actual_cost']);
        $this->assertSame('1920.00', $finance['forecast_cost']);
        $this->assertSame('10000.00', $finance['actual_result']);
        $this->assertSame(100.0, $finance['actual_margin_percent']);
        $this->assertSame('ok', $finance['result_tone']);
        $this->assertStringContainsString('Kosten €0', $finance['compact']);
        $this->assertStringNotContainsString('€1.920', $finance['compact']);
    }

    public function test_loss_when_actual_costs_exceed_the_order(): void
    {
        $finance = $this->finance(
            order: '10000.00',
            budget: 6000,
            actual: 12000,
        );

        $this->assertSame('-2000.00', $finance['actual_result']);
        $this->assertSame(-20.0, $finance['actual_margin_percent']);
        $this->assertSame('over', $finance['result_tone']);
        $this->assertSame('€6.000 boven begroting · 100%', $finance['overrun_line']);
        $this->assertSame(
            'Order €10.000 | Kosten €12.000 | Resultaat €-2.000 (-20%) | Begroting +€6.000 (100%)',
            $finance['compact'],
        );
    }

    public function test_stays_on_budget_when_actual_costs_match_the_estimate(): void
    {
        $finance = $this->finance(
            order: '10000.00',
            budget: 6000,
            actual: 6000,
        );

        $this->assertSame('0.00', $finance['cost_delta']);
        $this->assertSame(0.0, $finance['cost_delta_percent']);
        $this->assertSame('ok', $finance['budget_tone']);
        $this->assertSame('Op begroting', $finance['overrun_line']);
        $this->assertSame('4000.00', $finance['actual_result']);
        $this->assertSame(40.0, $finance['actual_margin_percent']);
    }

    public function test_zero_order_amount_has_no_margin_percent(): void
    {
        $finance = $this->finance(
            order: '0.00',
            budget: 6000,
            actual: 7500,
        );

        $this->assertTrue($finance['has_order']);
        $this->assertTrue($finance['order_is_zero']);
        $this->assertSame('€0', $finance['order_label']);
        $this->assertSame('-7500.00', $finance['actual_result']);
        $this->assertNull($finance['actual_margin_percent']);
        $this->assertSame('—', $finance['actual_margin_label']);
        $this->assertSame('€-7.500', $finance['actual_result_label']);
        $this->assertSame('over', $finance['result_tone']);
    }

    public function test_missing_order_amount_shows_placeholders(): void
    {
        $finance = $this->finance(
            order: null,
            budget: 6000,
            actual: 7500,
        );

        $this->assertFalse($finance['has_order']);
        $this->assertSame('—', $finance['order_label']);
        $this->assertNull($finance['actual_result']);
        $this->assertNull($finance['budget_result']);
        $this->assertNull($finance['actual_margin_percent']);
        $this->assertSame('—', $finance['actual_result_label']);
        $this->assertSame('none', $finance['result_tone']);
        $this->assertSame(
            'Order — | Kosten €7.500 | Resultaat — | Begroting +€1.500 (25%)',
            $finance['compact'],
        );
    }

    public function test_extra_work_actual_labor_is_added_once_to_actual_cost(): void
    {
        $finance = $this->finance(
            order: '10000.00',
            budget: 6000,
            actual: 7500,
            extraActual: 500,
        );

        $this->assertSame('8000.00', $finance['actual_cost']);
        $this->assertSame('2000.00', $finance['actual_result']);
        $this->assertSame('2000.00', $finance['cost_delta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function finance(
        ?string $order,
        float $budget,
        float $actual,
        float $plannedHours = 0,
        float $actualHours = 0,
        float $rate = 48,
        float $extraActual = 0,
    ): array {
        $project = new Project;
        $project->forceFill(['order_amount' => $order]);

        $labor = [
            'hourly_rate' => $rate,
            'budget_labor_cost' => $budget,
            'actual_labor_cost' => $actual,
            'planned_hours' => $plannedHours,
            'actual_hours' => $actualHours,
            'budget_hours' => $budget > 0 && $rate > 0 ? round($budget / $rate, 2) : 0,
            'items' => $extraActual > 0
                ? [['is_extra' => true, 'actual_labor_cost' => $extraActual]]
                : [],
        ];

        return (new ShopOrderFinance)->for($project, $labor);
    }
}
