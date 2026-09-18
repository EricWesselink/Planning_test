<?php

namespace App\Services;

use App\Models\Project;
use App\Support\Format;

class ShopOrderFinance
{
    /**
     * @param  array<string, mixed>  $labor
     * @return array{
     *     has_order: bool,
     *     order_is_zero: bool,
     *     order_amount: ?string,
     *     order_label: string,
     *     budget_cost: string,
     *     budget_cost_label: string,
     *     actual_cost: string,
     *     actual_cost_label: string,
     *     forecast_cost: string,
     *     budget_result: ?string,
     *     actual_result: ?string,
     *     actual_result_label: string,
     *     actual_margin_percent: ?float,
     *     actual_margin_label: string,
     *     cost_delta: string,
     *     cost_delta_percent: ?float,
     *     result_tone: 'none'|'ok'|'over',
     *     budget_tone: 'none'|'ok'|'over',
     *     compact: string,
     *     overrun_line: string,
     * }
     */
    public function for(Project $project, array $labor): array
    {
        $orderCents = $this->cents($project->order_amount);
        $hasOrder = $project->order_amount !== null && $project->order_amount !== '';
        $orderIsZero = $hasOrder && $orderCents === 0;
        $budgetCents = $this->cents($labor['budget_labor_cost'] ?? 0);
        $actualCents = $this->cents($labor['actual_labor_cost'] ?? 0) + $this->extraActualCents($labor);
        $rate = $labor['hourly_rate'] ?? null;
        $forecastCents = $rate === null
            ? 0
            : $this->cents(round(((float) ($labor['planned_hours'] ?? 0)) * (float) $rate, 2));

        $budgetResultCents = $hasOrder ? $orderCents - $budgetCents : null;
        $actualResultCents = $hasOrder ? $orderCents - $actualCents : null;
        $marginPercent = $hasOrder && $orderCents !== 0 && $actualResultCents !== null
            ? $this->percent($actualResultCents, $orderCents)
            : null;
        $costDeltaCents = $actualCents - $budgetCents;
        $costDeltaPercent = $budgetCents !== 0 ? $this->percent($costDeltaCents, $budgetCents) : null;

        $orderLabel = $hasOrder ? $this->euro($orderCents) : '—';
        $actualResultLabel = $actualResultCents === null
            ? '—'
            : $this->signedEuro($actualResultCents).($marginPercent === null ? '' : ' / '.$this->percentLabel($marginPercent));
        $resultTone = $actualResultCents === null
            ? 'none'
            : ($actualResultCents < 0 ? 'over' : 'ok');
        $budgetTone = $costDeltaCents > 0 ? 'over' : 'ok';

        return [
            'has_order' => $hasOrder,
            'order_is_zero' => $orderIsZero,
            'order_amount' => $hasOrder ? $this->fromCents($orderCents) : null,
            'order_label' => $orderLabel,
            'budget_cost' => $this->fromCents($budgetCents),
            'budget_cost_label' => $this->euro($budgetCents),
            'actual_cost' => $this->fromCents($actualCents),
            'actual_cost_label' => $this->euro($actualCents),
            'forecast_cost' => $this->fromCents($forecastCents),
            'budget_result' => $budgetResultCents === null ? null : $this->fromCents($budgetResultCents),
            'actual_result' => $actualResultCents === null ? null : $this->fromCents($actualResultCents),
            'actual_result_label' => $actualResultLabel,
            'actual_margin_percent' => $marginPercent,
            'actual_margin_label' => $this->percentLabel($marginPercent),
            'cost_delta' => $this->fromCents($costDeltaCents),
            'cost_delta_percent' => $costDeltaPercent,
            'result_tone' => $resultTone,
            'budget_tone' => $budgetTone,
            'compact' => implode(' | ', [
                'Order '.$orderLabel,
                'Kosten '.$this->euro($actualCents),
                'Resultaat '.($actualResultCents === null
                    ? '—'
                    : $this->signedEuro($actualResultCents).($marginPercent === null ? '' : ' ('.$this->percentLabel($marginPercent).')')),
                'Begroting '.$this->budgetCompact($costDeltaCents, $costDeltaPercent),
            ]),
            'overrun_line' => $this->overrunLine($costDeltaCents, $costDeltaPercent),
        ];
    }

    /**
     * @param  array<string, mixed>  $labor
     */
    private function extraActualCents(array $labor): int
    {
        $extra = 0;
        foreach ($labor['items'] ?? [] as $item) {
            if (empty($item['is_extra'])) {
                continue;
            }
            $extra += $this->cents($item['actual_labor_cost'] ?? 0);
        }

        return $extra;
    }

    private function cents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $text = number_format(round((float) $value, 2), 2, '.', '');
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '00');
        $cents = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function euro(int $cents): string
    {
        return Format::euroWhole($this->fromCents($cents));
    }

    private function signedEuro(int $cents): string
    {
        $label = $this->euro($cents);
        if ($cents > 0) {
            return '+'.$label;
        }

        return $label;
    }

    private function percent(int $partCents, int $ofCents): float
    {
        return round($partCents / $ofCents * 100, 1);
    }

    private function percentLabel(?float $percent): string
    {
        if ($percent === null) {
            return '—';
        }

        $text = fmod($percent, 1.0) === 0.0
            ? (string) (int) $percent
            : rtrim(rtrim(number_format($percent, 1, ',', ''), '0'), ',');

        return $text.'%';
    }

    private function budgetCompact(int $deltaCents, ?float $percent): string
    {
        if ($deltaCents === 0) {
            return '€0';
        }

        $label = $this->signedEuro($deltaCents);

        return $percent === null ? $label : $label.' ('.$this->percentLabel($percent).')';
    }

    private function overrunLine(int $deltaCents, ?float $percent): string
    {
        if ($deltaCents === 0) {
            return 'Op begroting';
        }

        $amount = $this->euro(abs($deltaCents));
        $direction = $deltaCents > 0 ? 'boven begroting' : 'onder begroting';
        $line = $amount.' '.$direction;

        return $percent === null ? $line : $line.' · '.$this->percentLabel($percent);
    }
}
