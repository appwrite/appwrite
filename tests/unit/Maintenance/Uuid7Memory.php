<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Utopia\Database\Adapter\Limits;
use Utopia\Database\Adapter\Memory;
use Utopia\Query\Schema\ColumnType;

/**
 * The in-memory adapter reporting a UUID7 sequence, as a document store does.
 * Memory keeps sequences as integers, so the rows still hold numeric ones:
 * only the reported type changes, which is what decides how a slot selects.
 */
final class Uuid7Memory extends Memory
{
    private ?Limits $uuid7 = null;

    #[\Override]
    public function limits(): Limits
    {
        return $this->uuid7 ??= new Limits(...[...\get_object_vars(parent::limits()), 'idType' => ColumnType::Uuid7]);
    }
}
