<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\AttributeUpdate;
use Utopia\Database\Database;
use Utopia\Query\Schema\ColumnType;

final class V20TestDatabase extends Database
{
    /**
     * @var list<array{string, string}>
     */
    public array $deletedIndexes = [];

    /**
     * @var list<array{string, string, ?ColumnType}>
     */
    public array $updatedAttributes = [];

    public function __construct()
    {
        parent::__construct(new Memory(), new Cache(new None()));
    }

    #[\Override]
    public function deleteIndex(string $collection, string $key): void
    {
        $this->deletedIndexes[] = [$collection, $key];
    }

    #[\Override]
    public function deleteDocument(string $collection, string $id): bool
    {
        return true;
    }

    #[\Override]
    public function updateAttribute(string $collection, string $key, AttributeUpdate $update): Attribute
    {
        $this->updatedAttributes[] = [$collection, $key, $update->type];

        return Attribute::string(key: $key);
    }
}
