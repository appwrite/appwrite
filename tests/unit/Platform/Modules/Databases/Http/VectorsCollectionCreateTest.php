<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class VectorsCollectionCreateTest extends TestCase
{
    private const string DATABASE_ID = 'vectors';
    private const int DIMENSION = 384;

    /**
     * @var array<string, mixed>
     */
    private array $collections;

    #[\Override]
    protected function setUp(): void
    {
        $this->collections = Config::getParam('collections', []);

        $collections = $this->collections;
        $collections['vectorsdb']['collections']['defaultAttributes'] = [Attribute::object(key: 'metadata', default: [])];
        Config::setParam('collections', $collections);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::setParam('collections', $this->collections);
    }

    public function testEmbeddingsTakeTheRequestedDimension(): void
    {
        $created = null;
        $metadata = [];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturn(new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => '9',
            'type' => DATABASE_TYPE_VECTORSDB,
        ]));
        $dbForProject->method('createDocument')->willReturnCallback(
            static fn (string $collection, Document $document): Document => $document->setAttribute('$sequence', '2')
        );
        $dbForProject->method('createDocuments')->willReturnCallback(
            static function (string $collection, array $documents) use (&$metadata): int {
                $metadata[$collection] = $documents;

                return \count($documents);
            }
        );

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('createCollection')->willReturnCallback(
            static function (Collection $collection) use (&$created): Collection {
                $created = $collection;

                return $collection;
            }
        );

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        (new Create())->action(
            self::DATABASE_ID,
            'documents',
            'Documents',
            self::DIMENSION,
            null,
            false,
            true,
            $this->createStub(Response::class),
            $dbForProject,
            static fn (): Database => $dbForDatabases,
            $this->createStub(Event::class),
            $authorization,
        );

        $this->assertInstanceOf(Collection::class, $created);
        $this->assertSame('database_9_collection_2', $created->getId());

        $attributes = [];
        foreach ($created->attributes() as $attribute) {
            $attributes[$attribute->key] = $attribute;
        }
        $this->assertSame(ColumnType::Vector, $attributes['embeddings']->type);
        $this->assertSame(self::DIMENSION, $attributes['embeddings']->size);
        $this->assertTrue($attributes['embeddings']->required);
        $this->assertSame(ColumnType::Object, $attributes['metadata']->type);

        $this->assertCount(2, $created->attributes());

        $this->assertSame(
            [['embeddings', ColumnType::Vector->value, self::DIMENSION, []], ['metadata', ColumnType::Object->value, 0, []]],
            \array_map(
                static fn (Document $document): array => [$document->getAttribute('key'), $document->getAttribute('type'), $document->getAttribute('size'), $document->getAttribute('options')],
                $metadata['attributes'],
            ),
        );
        $this->assertSame(
            [['_key_metadata', ['metadata'], [], []]],
            \array_map(
                static fn (Document $document): array => [$document->getAttribute('key'), $document->getAttribute('attributes'), $document->getAttribute('lengths'), $document->getAttribute('orders')],
                $metadata['indexes'],
            ),
        );
    }
}
