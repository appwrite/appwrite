<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

/**
 * Compares rows returned by the API, as attribute maps, with what the oracle expects.
 */
trait Assertions
{
    /**
     * @param array<string, mixed> $row
     */
    protected function aggregateInteger(array $row, string $key): ?int
    {
        $this->assertArrayHasKey($key, $row, "Aggregate '{$key}' is missing from the row");
        if ($row[$key] === null) {
            return null;
        }

        $this->assertIsNumeric($row[$key], "Aggregate '{$key}' is not numeric");

        return (int) $row[$key];
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function aggregateFloat(array $row, string $key): ?float
    {
        $this->assertArrayHasKey($key, $row, "Aggregate '{$key}' is missing from the row");
        if ($row[$key] === null) {
            return null;
        }

        $this->assertIsNumeric($row[$key], "Aggregate '{$key}' is not numeric");

        return (float) $row[$key];
    }

    /**
     * The key of a grouped joined attribute in an aggregate row is the engine's column name, so the row
     * carries either `alias.attribute` or the bare attribute.
     *
     * @param array<string, mixed> $row
     */
    protected function groupedValue(array $row, string $attribute): mixed
    {
        if (\array_key_exists($attribute, $row)) {
            return $row[$attribute];
        }

        $column = \substr($attribute, (int) \strrpos($attribute, '.') + 1);
        $this->assertArrayHasKey($column, $row, "Grouped attribute '{$attribute}' is missing from the row");

        return $row[$column];
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function assertSummaryRow(Summary $expected, array $row, string $message): void
    {
        $this->assertSame($expected->rows, $this->aggregateInteger($row, 'rowCount'), "{$message}: rows");
        $this->assertSame($expected->orders, $this->aggregateInteger($row, 'orderCount'), "{$message}: joined orders");
        $this->assertSame($expected->sum, $this->aggregateInteger($row, 'amountSum'), "{$message}: sum");
        $this->assertSame($expected->minimum, $this->aggregateInteger($row, 'amountMinimum'), "{$message}: minimum");
        $this->assertSame($expected->maximum, $this->aggregateInteger($row, 'amountMaximum'), "{$message}: maximum");

        $average = $this->aggregateFloat($row, 'amountAverage');
        if ($expected->average === null) {
            $this->assertNull($average, "{$message}: average");
        } else {
            $this->assertEqualsWithDelta($expected->average, $average, 0.001, "{$message}: average");
        }
    }

    /**
     * @param list<Pair> $pairs
     * @param list<array<string, mixed>> $rows grouped by `ord.label` with `rowCount`, `orderCount` and `amountSum`
     */
    protected function assertGroupRows(array $pairs, array $rows, string $message): void
    {
        $expected = \array_map(
            static fn (Summary $summary): array => [$summary->rows, $summary->orders, $summary->sum],
            Summary::byLabel($pairs),
        );

        $groups = [];
        foreach ($rows as $row) {
            $label = (string) ($this->groupedValue($row, 'ord.label') ?? Summary::UNGROUPED);
            $groups[$label] = [
                $this->aggregateInteger($row, 'rowCount'),
                $this->aggregateInteger($row, 'orderCount'),
                $this->aggregateInteger($row, 'amountSum'),
            ];
        }
        \ksort($groups);

        $this->assertSame($expected, $groups, $message);
    }

    /**
     * @param list<string> $expected
     * @param list<array<string, mixed>> $rows grouped by `ord.label`
     */
    protected function assertGroupLabels(array $expected, array $rows, string $message): void
    {
        $labels = \array_map(fn (array $row): string => (string) $this->groupedValue($row, 'ord.label'), $rows);

        $this->assertSame($this->sortedStrings($expected), $this->sortedStrings($labels), $message);
    }

    /**
     * @param list<Pair> $pairs
     * @param list<array<string, mixed>> $rows selecting `name` and `ord.amount`
     */
    protected function assertPairRows(array $pairs, array $rows, string $message): void
    {
        $this->assertSame(
            $this->sortedStrings(\array_map(static fn (Pair $pair): string => $pair->describe(), $pairs)),
            $this->sortedStrings(\array_map(
                static fn (array $row): string => ($row['name'] ?? '-') . '|' . (isset($row['ord.amount']) ? (int) $row['ord.amount'] : '-'),
                $rows,
            )),
            $message,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function assertStatistic(int|float|null $expected, Statistic $statistic, array $row): void
    {
        $message = "{$statistic->method()->value}('{$statistic->attribute()}')";

        if ($expected === null || $statistic->isExact()) {
            $this->assertSame($expected, $this->aggregateInteger($row, $statistic->value), $message);

            return;
        }

        $this->assertEqualsWithDelta($expected, $this->aggregateFloat($row, $statistic->value), \max(0.01, \abs($expected) * 1e-6), $message);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $joinedAttributes attributes only the joined collection declares
     */
    protected function assertJoinedValuesStayAliased(array $rows, array $joinedAttributes): void
    {
        $internals = ['$tenant', '$permissions', '$sequence', '$createdAt', '$updatedAt'];

        foreach ($rows as $row) {
            foreach (\array_keys($row) as $key) {
                $separator = \strrpos((string) $key, '.');
                if ($separator !== false) {
                    $this->assertNotContains(\substr((string) $key, $separator + 1), $internals, "Joined internal '{$key}' was returned without a select");
                }
            }

            foreach ($joinedAttributes as $attribute) {
                $this->assertArrayNotHasKey($attribute, $row, "Joined attribute '{$attribute}' was returned under its bare name");
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function assertJoinedOrderValues(Order $order, array $row): void
    {
        $this->assertSame($order->amount, $row['ord.amount'] ?? null);
        $this->assertSame($order->label, $row['ord.label'] ?? null);
        $this->assertSame($order->flags, $row['ord.flags'] ?? null);
        $this->assertSame([$order->flags, $order->amount], $row['ord.scores'] ?? null);
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    protected function sortedStrings(array $values): array
    {
        $strings = \array_map(static fn (mixed $value): string => (string) $value, \array_values($values));
        \sort($strings);

        return $strings;
    }
}
