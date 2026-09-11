<?php

namespace App\Services;

use App\Services\Meetstaat\MaterialIdentity;
use App\Support\WorkType;

class CalculationSourceReconciler
{
    public function __construct(private MaterialIdentity $identity) {}

    /**
     * @param  list<array<string, mixed>>  $excelLines
     * @param  array<string, mixed>  $preview
     * @return array{
     *     products: list<array<string, mixed>>,
     *     checks: list<array{key: string, label: string, status: string, message: string}>,
     *     open_conflicts: int
     * }
     */
    public function reconcile(array $excelLines, array $preview): array
    {
        $meetstaat = $this->works($preview, 'meetstaat_works');
        $materials = $this->works($preview, 'material_works');
        $excelProducts = $this->excelFloorProducts($excelLines);
        $products = [];
        $seen = [];

        foreach ($excelProducts as $product) {
            $meetstaatWork = $this->findWork($product['name'], $meetstaat);
            $materialWork = $this->findWork($product['name'], $materials);
            $canonical = (string) ($materialWork['name'] ?? $meetstaatWork['name'] ?? $product['name']);
            $key = mb_strtolower($canonical);
            if (isset($seen[$key])) {
                $products[$seen[$key]]['excel_quantity'] = round(
                    (float) $products[$seen[$key]]['excel_quantity'] + $product['quantity'],
                    4
                );

                continue;
            }

            $row = $this->productRow($canonical, $product, $meetstaatWork, $materialWork);
            $seen[$key] = count($products);
            $products[] = $row;
        }

        foreach (array_merge($meetstaat, $materials) as $work) {
            $name = trim((string) ($work['name'] ?? ''));
            if ($name === '' || isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $meetstaatWork = $this->findWork($name, $meetstaat);
            $materialWork = $this->findWork($name, $materials);
            if ($meetstaatWork === null && $materialWork === null) {
                continue;
            }
            $canonical = (string) ($materialWork['name'] ?? $meetstaatWork['name'] ?? $name);
            $seen[mb_strtolower($canonical)] = count($products);
            $products[] = $this->productRow($canonical, [
                'name' => $canonical,
                'quantity' => null,
                'unit' => (string) ($work['unit'] ?? 'm2'),
            ], $meetstaatWork, $materialWork);
        }

        $checks = $this->checks($preview, $products);
        $open = count(array_filter(
            $products,
            fn (array $row): bool => ($row['status'] ?? '') === 'review'
        ));

        return [
            'products' => $products,
            'checks' => $checks,
            'open_conflicts' => $open,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<string|array{name?: string, declared_total?: float|null}>
     */
    public function sourceProducts(array $preview): array
    {
        $baselines = is_array($preview['closure_baselines'] ?? null) ? $preview['closure_baselines'] : [];

        return array_values(array_filter(array_merge(
            $baselines['material_works'] ?? [],
            $baselines['meetstaat_works'] ?? [],
            $preview['works'] ?? [],
        ), fn ($work): bool => is_array($work) || is_string($work)));
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function works(array $preview, string $baselineKey): array
    {
        $baselines = is_array($preview['closure_baselines'] ?? null) ? $preview['closure_baselines'] : [];
        $works = $baselines[$baselineKey] ?? [];

        return array_values(array_filter($works, fn ($work): bool => is_array($work)));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{name: string, quantity: float, unit: string}>
     */
    private function excelFloorProducts(array $lines): array
    {
        $products = [];
        foreach ($lines as $line) {
            if ($line['is_labor'] ?? false) {
                continue;
            }
            $unit = rtrim(mb_strtolower(trim((string) ($line['unit'] ?? ''))), '.');
            if (! in_array($unit, ['m2', 'm²', 'm1', 'm¹', 'lm'], true)) {
                continue;
            }
            $quantity = $line['quantity'] ?? null;
            if ($quantity === null || (float) $quantity <= 0.0001) {
                continue;
            }
            $name = trim((string) (($line['article_description'] ?? '') ?: ($line['production_description'] ?? '')));
            if ($name === '' || $this->identity->isGenericCoveringLabel($name)) {
                continue;
            }

            $products[] = [
                'name' => $name,
                'quantity' => (float) $quantity,
                'unit' => in_array($unit, ['m1', 'm¹', 'lm'], true) ? 'm1' : 'm2',
            ];
        }

        return $products;
    }

    /**
     * @param  list<array<string, mixed>>  $works
     * @return array<string, mixed>|null
     */
    private function findWork(string $name, array $works): ?array
    {
        $hits = [];
        foreach ($works as $work) {
            $candidate = trim((string) ($work['name'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($this->identity->sharesIdentity($name, $candidate) || $this->identity->sharesIdentity($candidate, $name)) {
                $hits[] = $work;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param  array{name: string, quantity: float|null, unit: string}  $excel
     * @param  array<string, mixed>|null  $meetstaat
     * @param  array<string, mixed>|null  $materials
     * @return array<string, mixed>
     */
    private function productRow(string $canonical, array $excel, ?array $meetstaat, ?array $materials): array
    {
        $excelQty = $excel['quantity'];
        $meetstaatQty = $this->declaredQuantity($meetstaat);
        $materialQty = $this->declaredQuantity($materials);
        $values = array_values(array_filter(
            [$excelQty, $meetstaatQty, $materialQty],
            fn ($value): bool => $value !== null
        ));
        $status = 'confirmed';
        $message = null;
        if (count($values) >= 2) {
            $max = max($values);
            $min = min($values);
            $diff = round($max - $min, 4);
            if ($diff > 0.05 && $diff > $max * 0.02) {
                $status = 'warning';
                $message = 'Hoeveelheid wijkt af tussen bronnen (Excel / meetstaat / materialenstaat).';
            }
        }

        $codes = $this->identity->productCodes($canonical);
        $type = WorkType::knownType($canonical);

        return [
            'name' => $canonical,
            'type' => $type,
            'codes' => $codes,
            'unit' => $excel['unit'] ?? (string) ($meetstaat['unit'] ?? $materials['unit'] ?? 'm2'),
            'excel_quantity' => $excelQty,
            'meetstaat_quantity' => $meetstaatQty,
            'materialenstaat_quantity' => $materialQty,
            'status' => $status,
            'status_label' => $status === 'confirmed' ? 'Automatisch bevestigd' : 'Waarschuwing',
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $work
     */
    private function declaredQuantity(?array $work): ?float
    {
        if ($work === null) {
            return null;
        }
        if (array_key_exists('declared_total', $work) && $work['declared_total'] !== null) {
            return round((float) $work['declared_total'], 4);
        }
        if (array_key_exists('calculated_total', $work) && $work['calculated_total'] !== null) {
            return round((float) $work['calculated_total'], 4);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<array<string, mixed>>  $products
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    private function checks(array $preview, array $products): array
    {
        $header = is_array($preview['header'] ?? null) ? $preview['header'] : [];
        $numbers = array_values(array_unique(array_filter([
            trim((string) ($header['project_number'] ?? '')),
        ], fn (string $value): bool => $value !== '')));

        $checks = [
            [
                'key' => 'excel_meetstaat',
                'label' => 'Excel ↔ meetstaat',
                'status' => $this->pairStatus($products, 'excel_quantity', 'meetstaat_quantity'),
                'message' => 'Producten en hoeveelheden uit Excel en meetstaat.',
            ],
            [
                'key' => 'excel_materialenstaat',
                'label' => 'Excel ↔ materialenstaat',
                'status' => $this->pairStatus($products, 'excel_quantity', 'materialenstaat_quantity'),
                'message' => 'Producten en hoeveelheden uit Excel en materialenstaat.',
            ],
            [
                'key' => 'meetstaat_materialenstaat',
                'label' => 'Meetstaat ↔ materialenstaat',
                'status' => $this->pairStatus($products, 'meetstaat_quantity', 'materialenstaat_quantity'),
                'message' => 'Producten en hoeveelheden uit meetstaat en materialenstaat.',
            ],
            [
                'key' => 'project_number',
                'label' => 'Projectnummer / werknummer',
                'status' => count($numbers) <= 1 ? 'confirmed' : 'warning',
                'message' => count($numbers) <= 1
                    ? 'Projectnummer is eenduidig of ontbreekt in één van de bronnen.'
                    : 'Bronnen noemen verschillende projectnummers.',
            ],
        ];

        return $checks;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function pairStatus(array $products, string $left, string $right): string
    {
        $compared = 0;
        $warnings = 0;
        foreach ($products as $product) {
            if ($product[$left] === null || $product[$right] === null) {
                continue;
            }
            $compared++;
            if (($product['status'] ?? '') === 'warning') {
                $warnings++;
            }
        }
        if ($compared === 0) {
            return 'skipped';
        }

        return $warnings > 0 ? 'warning' : 'confirmed';
    }
}
