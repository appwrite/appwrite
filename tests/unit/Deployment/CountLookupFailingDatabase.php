<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Utopia\Database\Database;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Query\Method;

/**
 * Fails the count cap's cutoff lookup, as an engine timeout would, and serves every other read.
 */
final class CountLookupFailingDatabase extends Database
{
    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        if (\array_any($queries, fn (Query $query): bool => $query->getMethod() === Method::Offset)) {
            throw new \RuntimeException('Deployment count cutoff lookup timed out');
        }

        return parent::find($collection, $queries, $forPermission);
    }
}
