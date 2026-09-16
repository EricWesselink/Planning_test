<?php

namespace App\Services\QuoteCalculation;

class SpatialLegendAssembler
{
    /**
     * Read the renvooi from codes that have an equals-sign and product to their right.
     *
     * @param  list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return list<array{code: string, product: string, kind: string}>
     */
    public function assemble(array $pages): array
    {
        $legend = [];
        foreach ($pages as $page) {
            $items = $page['texts'] ?? [];
            foreach ($items as $item) {
                if (! $this->isFinishCode($item['text'])) {
                    continue;
                }
                $equals = $this->equalsToTheRight($item, $items);
                if ($equals === null) {
                    continue;
                }
                $product = $this->cleanProduct($this->productOnRow($equals, $items));
                if ($product === '') {
                    continue;
                }
                $code = mb_strtolower(trim($item['text']));
                $legend[$code] = [
                    'code' => $code,
                    'product' => $product,
                    'kind' => $this->codeKind($code),
                ];
            }
        }

        return array_values($legend);
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $code
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return array{text: string, x: float, y: float, page: int}|null
     */
    private function equalsToTheRight(array $code, array $items): ?array
    {
        $best = null;
        $bestDistance = null;
        foreach ($items as $item) {
            if ($item['page'] !== $code['page'] || $item['text'] !== '=') {
                continue;
            }
            if ($item['x'] <= $code['x'] || abs($item['y'] - $code['y']) > 10) {
                continue;
            }
            $distance = $item['x'] - $code['x'];
            if ($distance > 90) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $equals
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function productOnRow(array $equals, array $items): string
    {
        $row = [];
        foreach ($items as $item) {
            if ($item['page'] !== $equals['page'] || abs($item['y'] - $equals['y']) > 10) {
                continue;
            }
            if ($item['x'] <= $equals['x'] + 2 || $item['x'] > $equals['x'] + 480) {
                continue;
            }
            $row[] = $item;
        }
        usort($row, fn (array $left, array $right) => $left['x'] <=> $right['x']);

        $parts = [];
        foreach ($row as $item) {
            $text = trim((string) $item['text']);
            if ($text === '=' || $this->isFinishCode($text)) {
                break;
            }
            $parts[] = $text;
        }

        return trim(implode(' ', $parts));
    }

    private function isFinishCode(string $text): bool
    {
        return (bool) preg_match('/^(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?$/iu', $text);
    }

    private function codeKind(string $code): string
    {
        if (str_starts_with($code, 'pl')) {
            return 'plinth';
        }
        if (str_starts_with($code, 'v')) {
            return 'floor';
        }
        if (str_starts_with($code, 'w')) {
            return 'wall';
        }

        return 'ceiling';
    }

    private function cleanProduct(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value, " \t-–—:|");
        if (mb_strlen($value) < 3 || mb_strlen($value) > 120) {
            return '';
        }

        return $value;
    }
}
