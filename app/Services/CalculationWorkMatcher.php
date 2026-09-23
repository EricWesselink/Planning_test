<?php

namespace App\Services;

use App\Services\Meetstaat\MaterialIdentity;
use App\Support\WorkType;

class CalculationWorkMatcher
{
    public function __construct(private MaterialIdentity $identity) {}

    /**
     * @param  list<string|array{name?: string}>  $availableWorks
     * @param  array{
     *     line?: array<string, mixed>,
     *     materials?: list<array{label?: string}>,
     *     source_products?: list<string|array{name?: string}>
     * }  $context
     * @return array{status: string, work_name: ?string, work_key: ?string, candidates: list<string>, warning: ?string}
     */
    public function match(string $description, array $availableWorks = [], array $context = []): array
    {
        $names = $this->workNames($availableWorks);
        $catalog = $this->catalogNames($names, $context['source_products'] ?? []);
        $materials = $this->materialLabels($context['materials'] ?? $context['line']['context_materials'] ?? []);
        $line = $context['line'] ?? [];
        $needles = $this->explicitNeedles($description, $line, $materials);
        $combined = mb_strtolower(trim($description.' '.implode(' ', $needles).' '.implode(' ', $materials)));

        if ($this->isPreparation($combined) && ! $this->isFloorCovering($combined)) {
            return $this->result('matched', RoomWorkSetup::PRIMEN_EGALISEREN, [RoomWorkSetup::PRIMEN_EGALISEREN]);
        }

        if (str_contains($combined, 'overgangsprofiel')) {
            return $this->result('matched', 'Overgangsprofielen', ['Overgangsprofielen']);
        }

        $fromNeedles = $this->uniqueFromNeedles($needles, $this->identityCatalog($catalog));
        if ($fromNeedles !== null) {
            return $this->result('matched', $fromNeedles, [$fromNeedles]);
        }

        $fromMaterials = $this->uniqueFromNeedles($materials, $this->identityCatalog($catalog));
        if ($fromMaterials !== null) {
            return $this->result('matched', $fromMaterials, [$fromMaterials]);
        }

        if (count($materials) === 1) {
            $label = $this->preferCatalogName($materials[0], $this->identityCatalog($catalog))
                ?? $this->uniqueTypeWork($materials[0], $names)
                ?? $materials[0];

            return $this->result('matched', $label, [$label]);
        }

        if (count($materials) > 1) {
            $resolved = [];
            foreach ($materials as $label) {
                $hit = $this->preferCatalogName($label, $this->identityCatalog($catalog)) ?? $label;
                if ($hit !== '') {
                    $resolved[] = $hit;
                }
            }
            $resolved = array_values(array_unique($resolved));
            if (count($resolved) === 1) {
                return $this->result('matched', $resolved[0], $resolved);
            }
            $types = array_values(array_unique(array_filter(
                array_map(fn (string $name): ?string => WorkType::knownType($name), $resolved)
            )));
            if (count($types) === 1) {
                return $this->result(
                    'warning',
                    $resolved[0],
                    $resolved,
                    'Meerdere kleuren of varianten horen bij deze arbeidsregel; uren blijven op de eerste bevestigde variant.'
                );
            }
        }

        if ($this->identity->isGenericCoveringLabel($description)) {
            $compatible = $this->compatibleWorks($description, $catalog);
            if (count($compatible) === 1) {
                return $this->result('matched', $compatible[0], $compatible);
            }

            $inferred = $this->inferTypeFromMaterials($materials);
            if ($inferred !== null) {
                $typed = array_values(array_filter(
                    $catalog,
                    fn (string $name): bool => WorkType::knownType($name) === $inferred
                ));
                if (count($typed) === 1) {
                    return $this->result('matched', $typed[0], $typed);
                }

                return $this->result('matched', $inferred, [$inferred]);
            }
        }

        $suggested = $this->suggestType($description);
        if ($suggested !== null && ! $this->identity->isGenericCoveringLabel($description) && ! $this->looksUncertain($description)) {
            $candidates = $this->candidates($description, $suggested, $names);
            if (count($candidates) === 1) {
                return $this->result('matched', $candidates[0], $candidates);
            }
            if ($candidates === []) {
                return $this->result('matched', $suggested, [$suggested]);
            }
            if (count($candidates) > 1) {
                return $this->result('review', null, $candidates);
            }
        }

        if ($this->looksUncertain($description)) {
            $fromCost = $this->suggestType($description);
            if ($fromCost !== null) {
                return $this->result('warning', $fromCost, [$fromCost], 'Kosten- of toeslagregel automatisch gekoppeld op type.');
            }

            return $this->result('matched', 'Overige', ['Overige']);
        }

        $candidates = $this->candidates($description, $suggested, $names);
        if (count($candidates) === 1) {
            return $this->result('matched', $candidates[0], $candidates);
        }
        if (count($candidates) > 1) {
            return $this->result('review', null, $candidates);
        }

        return $this->result('review', null, []);
    }

    public function suggestType(string $description): ?string
    {
        $flat = mb_strtolower($description);
        if (str_contains($flat, 'overgangsprofiel')) {
            return 'Overgangsprofielen';
        }

        $known = WorkType::knownType($description);

        if ($this->isPreparation($flat) && ! $this->isFloorCovering($flat)) {
            return RoomWorkSetup::PRIMEN_EGALISEREN;
        }

        return $known;
    }

    /**
     * @return list<string>
     */
    public function optionLabels(array $availableWorks = []): array
    {
        $labels = $this->workNames($availableWorks);
        foreach ([
            RoomWorkSetup::PRIMEN_EGALISEREN,
            'PVC',
            'Tapijt',
            'Plinten',
            'Linoleum',
            'Vinyl',
            'Coating',
            'Gietvloer',
            'Entreemat',
            'Overige',
        ] as $label) {
            $labels[] = $label;
        }

        $unique = [];
        foreach ($labels as $label) {
            $key = mb_strtolower($label);
            if ($label === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $label;
        }

        return array_values($unique);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function candidates(string $description, ?string $suggested, array $names): array
    {
        $identityMatches = [];
        $typeMatches = [];

        foreach ($names as $name) {
            if ($this->identity->sharesIdentity($description, $name) || $this->identity->sharesIdentity($name, $description)) {
                $identityMatches[] = $name;

                continue;
            }

            if ($suggested !== null && $this->sameWork($name, $suggested)) {
                $typeMatches[] = $name;
            }
        }

        if ($identityMatches !== []) {
            return array_values(array_unique($identityMatches));
        }

        return array_values(array_unique($typeMatches));
    }

    private function sameWork(string $name, string $suggested): bool
    {
        if (strcasecmp(trim($name), trim($suggested)) === 0) {
            return true;
        }

        if ($suggested === RoomWorkSetup::PRIMEN_EGALISEREN) {
            return $this->isPreparation(mb_strtolower($name));
        }

        $known = WorkType::knownType($name);

        return $known !== null && strcasecmp($known, $suggested) === 0;
    }

    private function isPreparation(string $flat): bool
    {
        return (bool) preg_match('/schuur|primer|primen|egalis|voorber/u', $flat);
    }

    private function isFloorCovering(string $flat): bool
    {
        return (bool) preg_match(
            '/\b(pvc|tapijt|linoleum|marmoleum|vinyl|lvt|gietvloer|coating|vloerbedekking|entreemat|coral)\b/u',
            $flat
        );
    }

    private function looksUncertain(string $description): bool
    {
        $flat = mb_strtolower($description);

        return (bool) preg_match('/\b(toeslag|kosten|kilometer|kilometers|opslag|transport|voorrij|proefkamer)\b/u', $flat);
    }

    /**
     * @param  list<string|array{name?: string}>  $availableWorks
     * @return list<string>
     */
    private function workNames(array $availableWorks): array
    {
        $names = [];
        foreach ($availableWorks as $work) {
            $name = is_array($work) ? trim((string) ($work['name'] ?? '')) : trim((string) $work);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     * @param  list<string|array{name?: string}>  $sourceProducts
     * @return list<string>
     */
    private function catalogNames(array $names, array $sourceProducts): array
    {
        return $this->workNames(array_merge($sourceProducts, $names));
    }

    /**
     * @param  list<array{label?: string}|string>  $materials
     * @return list<string>
     */
    private function materialLabels(array $materials): array
    {
        $labels = [];
        foreach ($materials as $material) {
            $label = is_array($material)
                ? trim((string) ($material['label'] ?? $material['name'] ?? ''))
                : trim((string) $material);
            if ($label === '' || $this->identity->isGenericCoveringLabel($label)) {
                continue;
            }
            $labels[] = $label;
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<string>  $materials
     * @return list<string>
     */
    private function explicitNeedles(string $description, array $line, array $materials): array
    {
        $raw = [
            $description,
            (string) ($line['production_description'] ?? ''),
            (string) ($line['article_description'] ?? ''),
            (string) ($line['article_number'] ?? ''),
            ...$materials,
        ];
        $needles = [];
        foreach ($raw as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '' || $this->identity->isGenericCoveringLabel($needle)) {
                continue;
            }
            $needles[] = $needle;
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $catalog
     */
    private function uniqueFromNeedles(array $needles, array $catalog): ?string
    {
        if ($catalog === []) {
            return null;
        }

        $hits = [];
        foreach ($needles as $needle) {
            $resolved = $this->identity->resolveUniqueCanonical($needle, $catalog);
            if ($resolved !== null) {
                $hits[] = $resolved;

                continue;
            }
            foreach ($catalog as $candidate) {
                if ($this->identity->sharesIdentity($needle, $candidate) || $this->identity->sharesIdentity($candidate, $needle)) {
                    $hits[] = $candidate;
                }
            }
        }
        $hits = array_values(array_unique($hits));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param  list<string>  $catalog
     */
    private function preferCatalogName(string $label, array $catalog): ?string
    {
        if ($catalog === []) {
            return null;
        }

        $resolved = $this->identity->resolveUniqueCanonical($label, $catalog);
        if ($resolved !== null) {
            return $resolved;
        }

        $hits = [];
        foreach ($catalog as $candidate) {
            if ($this->identity->sharesIdentity($label, $candidate) || $this->identity->sharesIdentity($candidate, $label)) {
                $hits[] = $candidate;
            }
        }
        $hits = array_values(array_unique($hits));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param  list<string>  $catalog
     * @return list<string>
     */
    private function compatibleWorks(string $description, array $catalog): array
    {
        $types = $this->coveringTypes($description);
        if ($types === []) {
            return [];
        }

        $hits = [];
        foreach ($catalog as $name) {
            $known = WorkType::knownType($name);
            if ($known !== null && in_array($known, $types, true)) {
                $hits[] = $name;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @return list<string>
     */
    private function coveringTypes(string $description): array
    {
        $flat = mb_strtolower($description);
        if (str_contains($flat, 'zachte') && str_contains($flat, 'vloerbedekking')) {
            return ['Tapijt', 'Entreemat'];
        }
        if (str_contains($flat, 'elastische') && str_contains($flat, 'vloerbedekking')) {
            return ['Linoleum', 'PVC', 'Vinyl', 'Gietvloer', 'Coating'];
        }

        return [];
    }

    /**
     * @param  list<string>  $materials
     */
    private function inferTypeFromMaterials(array $materials): ?string
    {
        $types = [];
        foreach ($materials as $label) {
            $type = WorkType::knownType($label);
            if ($type !== null) {
                $types[] = $type;
            }
        }
        $types = array_values(array_unique($types));

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * @param  list<string>  $candidates
     * @return array{status: string, work_name: ?string, work_key: ?string, candidates: list<string>, warning: ?string}
     */
    private function result(string $status, ?string $workName, array $candidates, ?string $warning = null): array
    {
        return [
            'status' => $status,
            'work_name' => $workName,
            'work_key' => $this->key($workName),
            'candidates' => $candidates,
            'warning' => $warning,
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function uniqueTypeWork(string $label, array $names): ?string
    {
        $type = WorkType::knownType($label);
        if ($type === null) {
            return null;
        }

        $hits = [];
        foreach ($names as $name) {
            if ($this->sameWork($name, $type)) {
                $hits[] = $name;
            }
        }
        $hits = array_values(array_unique($hits));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Generieke typenamen (PVC, Tapijt) mogen geen productidentiteit winnen.
     *
     * @param  list<string>  $catalog
     * @return list<string>
     */
    private function identityCatalog(array $catalog): array
    {
        return array_values(array_filter(
            $catalog,
            fn (string $name): bool => ! $this->identity->isGenericTypeLabel($name)
        ));
    }

    private function key(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return mb_strtolower(trim($name));
    }
}
