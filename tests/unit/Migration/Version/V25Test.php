<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Migration\Resource;

final class V25Test extends TestCase
{
    public function testSplitsDeletedResourceWithoutWritingEmptyInternalIds(): void
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

        $this->assertSame('collection', $result->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_COLLECTION, $result->getAttribute('resourceType'));
        $this->assertSame('database', $result->getAttribute('parentResourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $result->getAttribute('parentResourceType'));
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
        $migration->setInternalIds([
            'parentResourceInternalId' => '10',
            'resourceInternalId' => '20',
        ]);
        $migration->migrate($document);
        $afterResolution = $document->getArrayCopy();
        $migration->migrate($document);

        $this->assertSame('10', $document->getAttribute('parentResourceInternalId'));
        $this->assertSame('20', $document->getAttribute('resourceInternalId'));
        $this->assertSame($afterResolution, $document->getArrayCopy());
    }
}
