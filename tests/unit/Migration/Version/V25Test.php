<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Migration\Resource;

final class V25Test extends TestCase
{
    public function testPreservesLegacyResourceWhenInternalIdsCannotBeResolved(): void
    {
        $migration = new class () extends V25 {
            public function __construct()
            {
            }

            public function migrate(Document $document): Document
            {
                return $this->migrateDocument($document);
            }

            #[\Override]
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, string $migrationId): array
            {
                return [];
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceType' => Resource::TYPE_DATABASE,
        ]);

        $result = $migration->migrate($document);

        $this->assertSame('database:collection', $result->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $result->getAttribute('resourceType'));
        $this->assertNull($result->getAttribute('parentResourceId'));
        $this->assertNull($result->getAttribute('parentResourceType'));
        $this->assertNull($result->getAttribute('resourceInternalId'));
        $this->assertNull($result->getAttribute('parentResourceInternalId'));
    }

    public function testRerunResolvesMissingInternalIdsIdempotently(): void
    {
        $migration = new class () extends V25 {
            /**
             * @var array{parentResourceInternalId?: string, resourceInternalId?: string}
             */
            private array $internalIds = [];

            public function __construct()
            {
            }

            public function migrate(Document $document): Document
            {
                return $this->migrateDocument($document);
            }

            /**
             * @param array{parentResourceInternalId?: string, resourceInternalId?: string} $internalIds
             */
            public function setInternalIds(array $internalIds): void
            {
                $this->internalIds = $internalIds;
            }

            /**
             * @return array{parentResourceInternalId?: string, resourceInternalId?: string}
             */
            #[\Override]
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, string $migrationId): array
            {
                return $this->internalIds;
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceType' => Resource::TYPE_DATABASE,
        ]);

        $migration->migrate($document);
        $this->assertSame('database:collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('resourceType'));
        $this->assertNull($document->getAttribute('parentResourceId'));

        $migration->setInternalIds([
            'parentResourceInternalId' => '10',
        ]);
        $migration->migrate($document);
        $this->assertSame('database:collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('resourceType'));
        $this->assertNull($document->getAttribute('parentResourceId'));

        $migration->setInternalIds([
            'parentResourceInternalId' => '10',
            'resourceInternalId' => '20',
        ]);
        $migration->migrate($document);
        $afterResolution = $document->getArrayCopy();
        $migration->migrate($document);

        $this->assertSame('10', $document->getAttribute('parentResourceInternalId'));
        $this->assertSame('20', $document->getAttribute('resourceInternalId'));
        $this->assertSame('collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_COLLECTION, $document->getAttribute('resourceType'));
        $this->assertSame('database', $document->getAttribute('parentResourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('parentResourceType'));
        $this->assertSame($afterResolution, $document->getArrayCopy());
    }
}
