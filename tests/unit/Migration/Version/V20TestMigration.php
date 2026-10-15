<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V20;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Index;

final class V20TestMigration extends V20
{
    /**
     * @param list<Document> $indexes
     */
    public function __construct(Database $database, private readonly array $indexes = [])
    {
        $this->dbForProject = $database;
    }

    /**
     * @param list<Attribute> $attributes
     * @param list<Index> $indexes
     */
    public function configured(string $collectionId, array $attributes, array $indexes): void
    {
        $this->migrateConfiguredArrayAttributes($collectionId, $attributes, $indexes);
    }

    public function user(Document $attribute): void
    {
        $this->migrateArrayAttribute($attribute);
    }

    #[\Override]
    protected function documentsIterator(string $collection, array $queries = []): \Generator
    {
        yield from $collection === 'indexes' ? $this->indexes : [];
    }
}
