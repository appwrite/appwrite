<?php

namespace Appwrite\Alert;

/**
 * A metric value in the unit its rule is written in, shown the way the console
 * shows it: bytes in decimal units, MB-seconds as GB-hours, percentages and
 * currency with their sign, anything else as a count followed by its unit,
 * singular for one.
 */
readonly class Quantity
{
    private const array BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * MB-seconds in a GB-hour, in the decimal units the console's usage pages use.
     */
    private const int MB_SECONDS_PER_GB_HOUR = 1000 * 3600;

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
            'mbSeconds' => self::number($this->amount / self::MB_SECONDS_PER_GB_HOUR, 4) . ' GB-hours',
            default => self::number($this->amount) . ' ' . $this->count(),
        };
    }

    private function count(): string
    {
        if ($this->amount !== 1.0 || !\str_ends_with($this->unit, 's')) {
            return $this->unit;
        }

        return \substr($this->unit, 0, -1);
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

    private static function number(float $value, int $decimals = 2): string
    {
        return \rtrim(\rtrim(\number_format($value, $decimals, '.', ','), '0'), '.');
    }
}
