<?php

namespace Appwrite\Alert;

/**
 * A metric value in the unit its rule is written in, shown the way the console
 * shows it: bytes in decimal units, percentages and currency with their sign,
 * anything else as a count followed by its unit.
 */
readonly class Quantity
{
    private const array BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public function __construct(
        public float $amount,
        public string $unit,
    ) {
    }

    public function format(): string
    {
        return match ($this->unit) {
            'bytes' => $this->bytes(),
            'percent' => self::number($this->amount) . '%',
            'usd' => '$' . \number_format($this->amount, 2, '.', ','),
            default => self::number($this->amount) . ' ' . $this->unit,
        };
    }

    private function bytes(): string
    {
        $amount = $this->amount;
        $index = 0;
        while (\abs($amount) >= 1000 && $index < \count(self::BYTE_UNITS) - 1) {
            $amount /= 1000;
            $index++;
        }

        return self::number($amount) . ' ' . self::BYTE_UNITS[$index];
    }

    private static function number(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ','), '0'), '.');
    }
}
