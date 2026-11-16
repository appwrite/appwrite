<?php

declare(strict_types=1);

namespace Tests\Unit\Presences\Mock;

use Utopia\Database\Adapter\Memory;
use Utopia\Database\Document;

/**
 * Memory database whose bulk deletes fail from the given call on, so a delete stops partway.
 */
final class FailingDeleteMemory extends Memory
{
    private int $calls = 0;

    public function __construct(private readonly int $failFromCall)
    {
        parent::__construct();
    }

    #[\Override]
    public function deleteDocuments(Document $collection, array $sequences, array $permissionIds): int
    {
        if (++$this->calls >= $this->failFromCall) {
            throw new \RuntimeException('Bulk delete failed');
        }

        return parent::deleteDocuments($collection, $sequences, $permissionIds);
    }
}
