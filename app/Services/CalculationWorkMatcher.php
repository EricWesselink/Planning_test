<?php

namespace App\Services;

use App\Services\Meetstaat\MaterialIdentity;
use App\Support\WorkType;

class CalculationWorkMatcher
{
    public function __construct(private MaterialIdentity $identity) {}

    /**
     * @param  list<string|array{name?: string}>  $availableWorks
     * @return array{status: string, work_name: ?string, work_key: ?string, candidates: list<string>}
     */
    public function match(string $description, array $availableWorks = []): array
    {
        $names = $this->workNames($availableWorks);
        $suggested = $this->suggestType($description);
        $uncertain = $this->looksUncertain($description);
        $candidates = $this->candidates($description, $suggested, $names);

        if ($uncertain) {
            return [
                'status' => 'review',
                'work_name' => $candidates[0] ?? $suggested,
                'work_key' => $this->key($candidates[0] ?? $suggested),
                'candidates' => $candidates !== [] ? $candidates : array_values(array_filter([$suggested])),
            ];
        }

        if (count($candidates) === 1) {
            return [
                'status' => 'matched',
                'work_name' => $candidates[0],
                'work_key' => $this->key($candidates[0]),
                'candidates' => $candidates,
            ];
        }

        if (count($candidates) > 1) {
            return [
                'status' => 'review',
                'work_name' => $candidates[0],
                'work_key' => $this->key($candidates[0]),
                'candidates' => $candidates,
            ];
        }

        if ($suggested !== null) {
            return [
                'status' => 'matched',
                'work_name' => $suggested,
                'work_key' => $this->key($suggested),
                'candidates' => [$suggested],
            ];
        }

        return [
            'status' => 'review',
            'work_name' => null,
            'work_key' => null,
            'candidates' => [],
        ];
    }

    public function suggestType(string $description): ?string
    {
        $flat = mb_strtolower($description);
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
        return (bool) preg_match('/\b(pvc|tapijt|linoleum|marmoleum|vinyl|lvt|gietvloer|coating)\b/u', $flat);
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

    private function key(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return mb_strtolower(trim($name));
    }
}
