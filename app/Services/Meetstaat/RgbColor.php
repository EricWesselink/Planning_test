<?php

namespace App\Services\Meetstaat;

class RgbColor
{
    public function __construct(
        public readonly int $red,
        public readonly int $green,
        public readonly int $blue,
    ) {}

    public static function fromRgb(float $red, float $green, float $blue): self
    {
        return new self(
            (int) round(max(0, min(1, $red)) * 255),
            (int) round(max(0, min(1, $green)) * 255),
            (int) round(max(0, min(1, $blue)) * 255),
        );
    }

    public static function fromCmyk(float $cyan, float $magenta, float $yellow, float $black): self
    {
        return self::fromRgb(
            (1 - $cyan) * (1 - $black),
            (1 - $magenta) * (1 - $black),
            (1 - $yellow) * (1 - $black),
        );
    }

    public static function fromGray(float $gray): self
    {
        return self::fromRgb($gray, $gray, $gray);
    }

    public static function fromHex(string $hex): ?self
    {
        $hex = strtolower(trim($hex));
        if (! preg_match('/^#?[0-9a-f]{6}$/', $hex)) {
            return null;
        }
        $hex = ltrim($hex, '#');

        return new self(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    public function hex(): string
    {
        return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
    }

    public function distance(self $other): float
    {
        return sqrt(
            (($this->red - $other->red) ** 2)
            + (($this->green - $other->green) ** 2)
            + (($this->blue - $other->blue) ** 2)
        );
    }

    public function matches(self $other, float $tolerance = 48.0): bool
    {
        return $this->distance($other) <= $tolerance;
    }

    public function isPaper(): bool
    {
        return $this->red > 235 && $this->green > 235 && $this->blue > 235;
    }

    public function isInk(): bool
    {
        return $this->red < 35 && $this->green < 35 && $this->blue < 35;
    }

    public function isIgnored(): bool
    {
        return $this->isPaper() || $this->isInk();
    }
}
