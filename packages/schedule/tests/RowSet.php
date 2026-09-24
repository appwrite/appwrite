<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Schedule\Source\Row;

/**
 * Mutable row store for reconciliation tests: closures capture the set
 * by object, so replacing `$rows` between syncs keeps full type
 * information (a by-reference capture would degrade to mixed).
 */
final class RowSet
{
    /**
     * @param list<Row> $rows
     */
    public function __construct(public array $rows = []) {}

    /**
     * @return list<Row>
     */
    public function list(): array
    {
        return $this->rows;
    }
}
