<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Query\Schema\ColumnType;

final class MetadataTest extends TestCase
{
    public function testRelatedDocumentUsesPublicCollectionId(): void
    {
        $related = new Document([
            '$id' => 'lib1',
            '$collection' => 'database_2_collection_17',
        ]);

        $document = new Document([
            '$id' => 'movie1',
            '$collection' => 'database_2_collection_3',
            'library' => $related,
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
            '$collection' => '_metadata',
            'attributes' => [
                new Document([
                    '$id' => 'library',
                    'key' => 'library',
                    'type' => ColumnType::Relationship->value,
                    'options' => [
                        'relatedCollection' => 'database_2_collection_17',
                    ],
                ]),
            ],
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
            resolvePublicId: static fn (string $internalId): string => match ($internalId) {
                'database_2_collection_17' => 'libraries',
                'database_2_collection_3' => 'movies',
                default => $internalId,
            },
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame('movies', $result->getAttribute('$collectionId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
        $this->assertSame('libraries', $result->getAttribute('library')->getAttribute('$collectionId'));
        $this->assertSame('db1', $result->getAttribute('library')->getAttribute('$databaseId'));
    }

    public function testParentUsesResolverNotMetadataAttribute(): void
    {
        $document = new Document([
            '$id' => 'movie1',
            '$collection' => 'database_2_collection_3',
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
            'externalId' => 'WRONG',
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
            resolvePublicId: static fn (string $internalId): string => match ($internalId) {
                'database_2_collection_3' => 'movies',
                default => $internalId,
            },
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame('movies', $result->getAttribute('$collectionId'));
    }

    public function testParentFallsBackToInternalIdWhenResolverMissing(): void
    {
        $document = new Document([
            '$id' => 'movie1',
            '$collection' => 'database_2_collection_3',
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame($collection->getId(), $result->getAttribute('$collectionId'));
    }

    public function testRelatedFallsBackToInternalIdWhenResolverMissing(): void
    {
        $related = new Document([
            '$id' => 'lib1',
            '$collection' => 'database_2_collection_17',
        ]);

        $document = new Document([
            '$id' => 'movie1',
            '$collection' => 'database_2_collection_3',
            'library' => $related,
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
            'attributes' => [
                new Document([
                    '$id' => 'library',
                    'key' => 'library',
                    'type' => ColumnType::Relationship->value,
                    'options' => [
                        'relatedCollection' => 'database_2_collection_17',
                    ],
                ]),
            ],
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame('database_2_collection_3', $result->getAttribute('$collectionId'));
        $this->assertSame('database_2_collection_17', $result->getAttribute('library')->getAttribute('$collectionId'));
    }

    public function testPublicIdCacheHitsResolverOnce(): void
    {
        $related = new Document([
            '$id' => 'movie2',
            '$collection' => 'database_2_collection_3',
        ]);

        $document = new Document([
            '$id' => 'movie1',
            '$collection' => 'database_2_collection_3',
            'sequel' => $related,
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
            'attributes' => [
                new Document([
                    '$id' => 'sequel',
                    'key' => 'sequel',
                    'type' => ColumnType::Relationship->value,
                    'options' => [
                        'relatedCollection' => 'database_2_collection_3',
                    ],
                ]),
            ],
        ]);

        $calls = 0;
        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
            resolvePublicId: function (string $internalId) use (&$calls): string {
                $calls++;
                $this->assertSame('database_2_collection_3', $internalId);

                return 'movies';
            },
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame(1, $calls);
        $this->assertSame('movies', $result->getAttribute('$collectionId'));
        $this->assertSame('movies', $result->getAttribute('sequel')->getAttribute('$collectionId'));
    }

    public function testTableContextStampsTableId(): void
    {
        $document = new Document([
            '$id' => 'row1',
            '$collection' => 'database_2_collection_3',
        ]);

        $collection = new Document([
            '$id' => 'database_2_collection_3',
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'table',
            resolvePublicId: static fn (string $internalId): string => match ($internalId) {
                'database_2_collection_3' => 'movies',
                default => $internalId,
            },
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame('movies', $result->getAttribute('$tableId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
        $this->assertNull($result->getAttribute('$collectionId'));
    }

    public function testEmptyDocumentIsUnchanged(): void
    {
        $document = new Document();
        $collection = new Document([
            '$id' => 'database_2_collection_3',
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame($document, $result);
        $this->assertTrue($result->isEmpty());
        $this->assertNull($result->getAttribute('$collectionId'));
        $this->assertNull($result->getAttribute('$databaseId'));
    }

    public function testMetadataCollectionIsSkipped(): void
    {
        $document = new Document([
            '$id' => 'doc1',
            'title' => 'kept',
        ]);
        $attributes = $document->getArrayCopy();

        $collection = new Document([
            '$id' => '_metadata',
        ]);

        $hook = new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'collection',
        );

        $result = $hook->decorate(Event::DocumentRead, $collection, $document);

        $this->assertSame($document, $result);
        $this->assertSame($attributes, $result->getArrayCopy());
        $this->assertNull($result->getAttribute('$collectionId'));
        $this->assertNull($result->getAttribute('$databaseId'));
    }
}
