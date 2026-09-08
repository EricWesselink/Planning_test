<?php

namespace App\Support;

class WorkColor
{
    public static function key(string $groupKey, ?string $typeLabel = null, ?string $label = null): string
    {
        $hay = mb_strtolower(trim($groupKey.' '.$typeLabel.' '.$label));

        return match (true) {
            str_starts_with($groupKey, 'ondergrond') || str_contains($hay, 'primen') || str_contains($hay, 'egal') => 'ondergrond',
            str_contains($hay, 'plint') => 'plinten',
            str_contains($hay, 'pvc') => 'pvc',
            str_contains($hay, 'entreemat') || str_contains($hay, 'coral') => 'entreemat',
            str_contains($hay, 'coating') => 'coating',
            str_contains($hay, 'gietvloer') => 'gietvloer',
            str_contains($hay, 'tapijt') => 'tapijt',
            str_contains($hay, 'vinyl') => 'vinyl',
            str_contains($hay, 'linoleum') || str_contains($hay, 'marmoleum') => 'linoleum',
            str_starts_with($groupKey, 'vloer') => 'vloer',
            default => 'overige',
        };
    }

    public static function legendLabel(string $key): string
    {
        return match ($key) {
            'ondergrond' => 'Primen & egaliseren',
            'linoleum', 'vloer' => 'Linoleum / vloer',
            'pvc' => 'PVC',
            'plinten' => 'Plinten',
            'entreemat' => 'Entreemat',
            'coating' => 'Coating',
            'gietvloer' => 'Gietvloer',
            'tapijt' => 'Tapijt',
            'vinyl' => 'Vinyl',
            default => 'Overige',
        };
    }
}
