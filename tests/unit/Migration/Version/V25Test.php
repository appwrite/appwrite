<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
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
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
            {
                return [];
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceInternalId' => null,
            'resourceType' => Resource::TYPE_DATABASE,
            'parentResourceInternalId' => null,
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
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
            {
                return $this->internalIds;
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceInternalId' => null,
            'resourceType' => Resource::TYPE_DATABASE,
            'parentResourceInternalId' => null,
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

    public function testAddsPushBrokerSchemaToProjectFromPreviousRelease(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A project created on 2.2.0 has no pushLedger and no topic sequence, qos or expiry.
        $added = ['sequence', 'qos', 'expiry'];
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if ($id === 'pushLedger' || ($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $id === 'topics'
                ? \array_filter($collection['attributes'], fn (Document $attribute) => !\in_array($attribute->getId(), $added, true))
                : $collection['attributes'];
            $database->createCollection(new Collection(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(new Collection(id: 'audit'));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $this->assertFalse($database->getCollection('pushLedger')->isEmpty());
        $topics = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('topics')->getAttribute('attributes', [])
        );
        foreach ($added as $attribute) {
            $this->assertContains($attribute, $topics);
        }
    }

    public function testRejectsResourcesCreatedAfterMigration(): void
    {
        $migration = new class () extends V25 {
            public function __construct()
            {
            }

            public function isCandidate(Document $resource, Document $migration): bool
            {
                return $this->predatesMigration($resource, $migration);
            }
        };
        $document = new Document([
            '$createdAt' => '2026-01-02T00:00:00.000+00:00',
        ]);

        $this->assertTrue($migration->isCandidate(new Document([
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
        ]), $document));
        $this->assertFalse($migration->isCandidate(new Document([
            '$createdAt' => '2026-01-03T00:00:00.000+00:00',
        ]), $document));
        $this->assertFalse($migration->isCandidate(new Document(), $document));
        $this->assertFalse($migration->isCandidate($document, new Document()));
    }
}
