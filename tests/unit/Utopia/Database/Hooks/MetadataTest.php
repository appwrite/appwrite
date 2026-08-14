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
    public function testRelatedDocumentUsesExternalCollectionId(): void
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
            'externalId' => 'movies',
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
            resolveExternalId: static fn (string $internalId): string => match ($internalId) {
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
}
