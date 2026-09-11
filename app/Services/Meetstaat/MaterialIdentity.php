<?php

namespace App\Services\Meetstaat;

/**
 * Generieke materiaalidentiteit: merk/familie + productcode + uitvoering/formaat + type.
 * Productcodes (RF 7133080, F2.10.60, 43.20.02) wegen het zwaarst wanneer aanwezig.
 */
class MaterialIdentity
{
    /**
     * @return list<string>
     */
    public function productCodes(string $name): array
    {
        $codes = [];
        if (preg_match_all('/\b(?:rf\s*)?(\d{6,})\b/iu', $name, $match)) {
            foreach ($match[1] as $code) {
                $codes[] = $code;
            }
        }
        if (preg_match_all('/\b([a-z]\d\.\d{2}\.\d{2})\b/iu', $name, $match)) {
            foreach ($match[1] as $code) {
                $codes[] = mb_strtolower($code);
            }
        }
        if (preg_match_all('/\b(\d{2,}\.\d{2}\.\d{2})\b/u', $name, $match)) {
            foreach ($match[1] as $code) {
                $codes[] = $code;
            }
        }
        $withoutRal = preg_replace('/\bral\s*\d{3,5}\b/iu', ' ', $name) ?? $name;
        if (preg_match_all('/\b(\d{4,5})\b/u', $withoutRal, $match)) {
            foreach ($match[1] as $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public function isGenericTypeLabel(string $name): bool
    {
        $flat = mb_strtolower(trim($name));

        return (bool) preg_match(
            '/^(tapijttegels?|tapijt|coating|linoleum|vinyl|pvc|lvt|plinten?|gietvloer|entreemat)(\s|\(|$)/u',
            $flat
        );
    }

    /**
     * STABU-categorie zonder product: nooit reden voor handmatige controle op zichzelf.
     */
    public function isGenericCoveringLabel(string $name): bool
    {
        $flat = mb_strtolower(trim($name));
        if ($flat === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(elastische|zachte|harde|textiele)?\s*vloerbedekking\b/u',
            $flat
        );
    }

    public function looksLikeProductTypeContinuation(string $line): bool
    {
        $flat = trim($line);

        return (bool) preg_match(
            '/^(tapijttegels?|tapijt|coating|linoleum|vinyl|pvc|lvt|plinten?|entreemat)(\b|\s|\(|$)/iu',
            $flat
        );
    }

    public function looksLikeStrongProductHeader(string $line): bool
    {
        $flat = trim($line);
        if ($flat === '') {
            return false;
        }

        $hasBrand = (bool) preg_match(
            '/\b(ege|tarkett|desso|forbo|sikkens|interface|modulyss|nora|objectcarpet|coral)\b/iu',
            $flat
        );
        $codes = $this->productCodes($flat);
        if ($hasBrand && $codes !== []) {
            return true;
        }
        if ($codes !== [] && str_contains($flat, ',')) {
            return true;
        }
        if (preg_match('/\b[a-z]\d\.\d{2}\.\d{2}\b/iu', $flat)
            && preg_match('/\b(pu|gietvloer|coating|sikkens)\b/iu', $flat)) {
            return true;
        }

        return false;
    }

    public function sharesProductCode(string $left, string $right): bool
    {
        $leftCodes = $this->productCodes($left);
        $rightCodes = $this->productCodes($right);
        if ($leftCodes === [] || $rightCodes === []) {
            return false;
        }

        return array_intersect($leftCodes, $rightCodes) !== [];
    }

    /**
     * Dezelfde productvariant, ook bij afgekorte of licht vervormde brontekst.
     * Geen gok op alleen een merktoken of een generiek type.
     */
    public function sharesIdentity(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($this->sharesProductCode($left, $right)) {
            return $this->sameExecutionVariant($left, $right);
        }

        $leftCodes = $this->productCodes($left);
        $rightCodes = $this->productCodes($right);
        if ($leftCodes !== [] && $rightCodes !== [] && array_intersect($leftCodes, $rightCodes) === []) {
            return false;
        }

        $a = $this->normalizedKey($left);
        $b = $this->normalizedKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorterKey = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
            $longerKey = mb_strlen($a) <= mb_strlen($b) ? $b : $a;
            $shorterName = mb_strlen($a) <= mb_strlen($b) ? $left : $right;
            if (mb_strlen($shorterKey) >= 12 && str_starts_with($longerKey, $shorterKey)) {
                if (count($this->distinctiveTokens($shorterName)) >= 3
                    || $this->longerAddsNoDistinctiveTokens($left, $right)) {
                    return true;
                }
            }
        }

        return $this->sharesStrongTokens($left, $right);
    }

    /**
     * Koppelt een korte of vervormde naam aan precies één canonieke variant wanneer uniek bewezen.
     * Bij meerdere kandidaten: null (geen gok → BLOCKED_CONFLICT elders).
     *
     * @param  list<string>  $canonicalCandidates
     */
    public function resolveUniqueCanonical(string $shortOrPartial, array $canonicalCandidates): ?string
    {
        $needle = trim($shortOrPartial);
        if ($needle === '' || $canonicalCandidates === []) {
            return null;
        }

        $canonicalCandidates = array_values(array_unique(array_filter(
            $canonicalCandidates,
            fn ($candidate) => is_string($candidate) && trim($candidate) !== ''
        )));
        if ($canonicalCandidates === []) {
            return null;
        }

        foreach ($canonicalCandidates as $candidate) {
            if ($this->normalizedKey($needle) === $this->normalizedKey($candidate)) {
                return $candidate;
            }
        }

        $needleKey = $this->normalizedKey($needle);
        if (mb_strlen($needleKey) >= 12) {
            $prefixHits = [];
            foreach ($canonicalCandidates as $candidate) {
                $candidateKey = $this->normalizedKey($candidate);
                if ($candidateKey === $needleKey) {
                    continue;
                }
                if ((str_starts_with($candidateKey, $needleKey) || str_starts_with($needleKey, $candidateKey))
                    && $this->sameExecutionVariant($needle, $candidate)) {
                    $prefixHits[] = $candidate;
                }
            }
            $prefixHits = array_values(array_unique($prefixHits));
            if (count($prefixHits) === 1) {
                return $prefixHits[0];
            }
            if (count($prefixHits) > 1) {
                return null;
            }
        }

        $codeHits = [];
        foreach ($canonicalCandidates as $candidate) {
            if ($this->sharesProductCode($needle, $candidate) && $this->sameExecutionVariant($needle, $candidate)) {
                $codeHits[] = $candidate;
            }
        }
        $codeHits = array_values(array_unique($codeHits));
        if (count($codeHits) === 1) {
            return $codeHits[0];
        }
        if (count($codeHits) > 1) {
            return null;
        }

        $tokenHits = [];
        foreach ($canonicalCandidates as $candidate) {
            if ($this->sharesStrongTokens($needle, $candidate)) {
                $tokenHits[] = $candidate;
            }
        }
        $tokenHits = array_values(array_unique($tokenHits));
        if (count($tokenHits) === 1) {
            return $tokenHits[0];
        }
        if (count($tokenHits) > 1) {
            return null;
        }

        if (! $this->isGenericTypeLabel($needle)) {
            return null;
        }

        $type = mb_strtolower(preg_replace('/\s+/', ' ', $needle) ?? $needle);
        $typeHits = [];
        foreach ($canonicalCandidates as $candidate) {
            if ($this->isGenericTypeLabel($candidate)) {
                continue;
            }
            if ($this->candidateHasMaterialType($candidate, $type)) {
                $typeHits[] = $candidate;
            }
        }
        $typeHits = array_values(array_unique($typeHits));

        return count($typeHits) === 1 ? $typeHits[0] : null;
    }

    public function sharesStrongTokens(string $left, string $right): bool
    {
        $leftTokens = $this->distinctiveTokens($left);
        $rightTokens = $this->distinctiveTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $shared = $this->alignedTokens($leftTokens, $rightTokens);
        if (count($shared) >= 3) {
            return ! $this->bothSidesHaveUnalignedTokens($left, $right)
                && $this->sameExecutionVariant($left, $right);
        }

        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;
        if (count($shorter) < 2) {
            return false;
        }
        if ($this->alignedTokens($shorter, $longer) !== $shorter) {
            return false;
        }

        return $this->longerAddsNoDistinctiveTokens($left, $right);
    }

    /**
     * Gedeelde productcode is niet genoeg wanneer beide namen al een complete variant zijn
     * en de ene een extra uitvoering toevoegt (bijv. "op kurk" vs dezelfde Lino Art zonder kurk).
     * Korte/afgekorte labels blijven via de productcode koppelen.
     */
    public function sameExecutionVariant(string $left, string $right): bool
    {
        $leftTokens = $this->distinctiveTokens($left);
        $rightTokens = $this->distinctiveTokens($right);
        if (count($leftTokens) >= 5 && count($rightTokens) >= 5) {
            return $this->longerAddsNoDistinctiveTokens($left, $right);
        }

        return true;
    }

    /**
     * Langere naam is alleen dezelfde variant wanneer die geen extra kleur/uitvoering toevoegt.
     */
    private function longerAddsNoDistinctiveTokens(string $left, string $right): bool
    {
        $leftTokens = $this->distinctiveTokens($left);
        $rightTokens = $this->distinctiveTokens($right);
        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;
        foreach ($longer as $token) {
            $aligned = false;
            foreach ($shorter as $needle) {
                if ($this->tokensAlign($token, $needle)) {
                    $aligned = true;
                    break;
                }
            }
            if (! $aligned) {
                return false;
            }
        }

        return true;
    }

    private function bothSidesHaveUnalignedTokens(string $left, string $right): bool
    {
        $leftTokens = $this->distinctiveTokens($left);
        $rightTokens = $this->distinctiveTokens($right);
        $leftOnly = false;
        foreach ($leftTokens as $token) {
            $aligned = false;
            foreach ($rightTokens as $other) {
                if ($this->tokensAlign($token, $other)) {
                    $aligned = true;
                    break;
                }
            }
            if (! $aligned) {
                $leftOnly = true;
                break;
            }
        }
        if (! $leftOnly) {
            return false;
        }
        foreach ($rightTokens as $token) {
            $aligned = false;
            foreach ($leftTokens as $other) {
                if ($this->tokensAlign($token, $other)) {
                    $aligned = true;
                    break;
                }
            }
            if (! $aligned) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function distinctiveTokens(string $name): array
    {
        $stop = [
            'vinyl' => true, 'pvc' => true, 'banen' => true, 'natural' => true,
            'coating' => true, 'linoleum' => true, 'tapijt' => true, 'tapijttegels' => true,
            'lvt' => true, 'ral' => true, 'met' => true, 'vlok' => true, 'cm' => true, 'mm' => true,
            'the' => true, 'and' => true, 'voor' => true, 'van' => true, 'op' => true, 'met' => true,
        ];
        $tokens = [];
        foreach ($this->productCodes($name) as $code) {
            $tokens[] = mb_strtolower($code);
        }
        $flat = preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($name)) ?? mb_strtolower($name);
        foreach (preg_split('/\s+/', trim($flat)) ?: [] as $token) {
            if ($token === '' || mb_strlen($token) < 4 || isset($stop[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function candidateHasMaterialType(string $candidate, string $type): bool
    {
        $flat = mb_strtolower($candidate);
        if (preg_match('/\b'.preg_quote($type, '/').'\b/u', $flat)) {
            return true;
        }

        return (bool) preg_match('/,\s*'.preg_quote($type, '/').'(\s|\(|$)/u', $flat);
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     * @return list<string>
     */
    private function alignedTokens(array $needles, array $haystack): array
    {
        $matched = [];
        foreach ($needles as $needle) {
            foreach ($haystack as $candidate) {
                if ($this->tokensAlign($needle, $candidate)) {
                    $matched[] = $needle;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    private function tokensAlign(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = mb_strlen($left) <= mb_strlen($right) ? $right : $left;

        return mb_strlen($shorter) >= 5 && str_starts_with($longer, $shorter);
    }

    private function normalizedKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\b(banen|vinyl|pvc|tapijttegels?|coating|lvt)\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
