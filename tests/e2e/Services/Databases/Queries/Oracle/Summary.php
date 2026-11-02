<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Summary
{
    public const string UNGROUPED = '';

    public function __construct(
        public int $rows,
        public int $orders,
        public ?int $sum,
        public ?int $minimum,
        public ?int $maximum,
        public ?float $average,
    ) {
    }

    /**
     * @param list<Pair> $pairs
     */
    public static function of(array $pairs): self
    {
        $amounts = [];
        foreach ($pairs as $pair) {
            if ($pair->order !== null) {
                $amounts[] = $pair->order->amount;
            }
        }

        if ($amounts === []) {
            return new self(\count($pairs), 0, null, null, null, null);
        }

        return new self(
            rows: \count($pairs),
            orders: \count($amounts),
            sum: \array_sum($amounts),
            minimum: \min($amounts),
            maximum: \max($amounts),
            average: \array_sum($amounts) / \count($amounts),
        );
    }

    /**
     * Rows without an order form the group keyed by {@see self::UNGROUPED}, the NULL label group.
     *
     * @param list<Pair> $pairs
     * @return array<string, self>
     */
    public static function byLabel(array $pairs): array
    {
        $groups = [];
        foreach ($pairs as $pair) {
            $groups[$pair->order->label ?? self::UNGROUPED][] = $pair;
        }

        \ksort($groups);

        return \array_map(self::of(...), $groups);
    }

    /**
     * @param list<Pair> $pairs
     * @return list<string>
     */
    public static function labelsAbove(array $pairs, int $threshold): array
    {
        $labels = [];
        foreach (self::byLabel($pairs) as $label => $group) {
            if ($group->sum !== null && $group->sum > $threshold) {
                $labels[] = (string) $label;
            }
        }

        return $labels;
    }
}
