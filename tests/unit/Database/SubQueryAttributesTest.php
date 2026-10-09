<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

/**
 * A collection definition read from the catalog carries its attributes through the subQueryAttributes filter, and
 * the database library hydrates that definition into attribute models for index creation and row-width checks.
 */
final class SubQueryAttributesTest extends TestCase
{
    private const string DATABASE_ID = 'library';

    private const string COLLECTION_ID = 'movies';

    private Database $catalog;

    private Authorization $authorization;

    protected function setUp(): void
    {
        require __DIR__ . '/../../../app/init/database/filters.php';

        $this->authorization = new Authorization();
        $this->catalog = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('catalog')
            ->setAuthorization($this->authorization);

        $this->seed();
    }

    public function testRelationshipAttributesOfAFilteredCollectionHydrate(): void
    {
        $definition = $this->authorization->skip(fn (): Document => $this->catalog->getDocument('database_1', self::COLLECTION_ID));

        $attributes = Collection::fromDocument($definition)->attributes();

        $this->assertCount(2, $attributes);
        [$relationship, $title] = $attributes[0]->key === 'actors' ? $attributes : \array_reverse($attributes);
        $this->assertSame('title', $title->key);
        $this->assertSame(ColumnType::Relationship, $relationship->type);
        $this->assertNotNull($relationship->relationship);
        $this->assertSame('actors', $relationship->relationship->relatedCollection);
        $this->assertSame(RelationshipType::ManyToMany, $relationship->relationship->type);
        $this->assertTrue($relationship->relationship->twoWay);
        $this->assertSame('movies', $relationship->relationship->twoWayKey);
        $this->assertSame(RelationshipSide::Parent, $relationship->side);
    }

    public function testRelationshipOptionsStayReadableAsTopLevelKeys(): void
    {
        $definition = $this->authorization->skip(fn (): Document => $this->catalog->getDocument('database_1', self::COLLECTION_ID));

        $relationship = null;
        foreach ($definition->getAttribute('attributes', []) as $attribute) {
            if ($attribute->getAttribute('key') === 'actors') {
                $relationship = $attribute;
            }
        }

        $this->assertInstanceOf(Document::class, $relationship);
        $this->assertSame('actors', $relationship->getAttribute('relatedCollection'));
        $this->assertSame(RelationshipType::ManyToMany->value, $relationship->getAttribute('relationType'));
        $this->assertTrue($relationship->getAttribute('twoWay'));
        $this->assertSame('movies', $relationship->getAttribute('twoWayKey'));
        $this->assertSame('cascade', $relationship->getAttribute('onDelete'));
        $this->assertSame(RelationshipSide::Parent->value, $relationship->getAttribute('side'));
    }

    private function seed(): void
    {
        $collections = Config::getParam('collections', []);

        $this->authorization->skip(function () use ($collections): void {
            $this->catalog->create();
            foreach ($collections['projects'] as $id => $collection) {
                if (($collection['$collection'] ?? '') !== Database::METADATA) {
                    continue;
                }

                $this->catalog->createCollection(Collection::create(
                    id: $id,
                    attributes: $collection['attributes'],
                    indexes: $collection['indexes'],
                ));
            }

            $database = $this->catalog->createDocument('databases', new Document([
                '$id' => self::DATABASE_ID,
                'name' => 'Library',
                'enabled' => true,
                'type' => DATABASE_TYPE_LEGACY,
            ]));
            $this->catalog->createCollection(Collection::create(
                id: 'database_' . $database->getSequence(),
                attributes: $collections['databases']['collections']['attributes'],
                indexes: $collections['databases']['collections']['indexes'],
            ));

            $collection = $this->catalog->createDocument('database_' . $database->getSequence(), new Document([
                '$id' => self::COLLECTION_ID,
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => self::DATABASE_ID,
                'name' => 'Movies',
                'enabled' => true,
                'documentSecurity' => false,
            ]));

            $this->createAttribute($database, $collection, 'title', [
                'type' => ColumnType::String->value,
                'size' => 128,
                'options' => [],
            ]);
            $this->createAttribute($database, $collection, 'actors', [
                'type' => ColumnType::Relationship->value,
                'size' => 0,
                'options' => [
                    'relatedCollection' => 'actors',
                    'relationType' => RelationshipType::ManyToMany->value,
                    'twoWay' => true,
                    'twoWayKey' => 'movies',
                    'onDelete' => 'cascade',
                    'side' => RelationshipSide::Parent->value,
                ],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function createAttribute(Document $database, Document $collection, string $key, array $definition): void
    {
        $this->catalog->createDocument('attributes', new Document([
            '$id' => $database->getSequence() . '_' . $collection->getSequence() . '_' . $key,
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => self::DATABASE_ID,
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => self::COLLECTION_ID,
            'key' => $key,
            'status' => 'available',
            'required' => false,
            'array' => false,
            'filters' => [],
            ...$definition,
        ]));
    }
}
