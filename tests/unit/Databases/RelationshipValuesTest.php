<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\RelationshipValues;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class RelationshipValuesTest extends TestCase
{
    private const string UNIQUE_ID = 'unique()';
    private const int GENERATED_ID_LENGTH = 20;
    private const string DATABASE_SEQUENCE = '1';
    private const string CATALOG = 'database_' . self::DATABASE_SEQUENCE;

    public function testNestedDocumentsAskingForAUniqueIdGetGeneratedIds(): void
    {
        $prepared = $this->prepare([
            'title' => 'Album',
            'artist' => [
                '$id' => self::UNIQUE_ID,
                'name' => 'Artist',
                'label' => ['$id' => self::UNIQUE_ID, 'name' => 'Label'],
            ],
            'tracks' => [
                ['$id' => self::UNIQUE_ID, 'name' => 'Track 1'],
                ['$id' => self::UNIQUE_ID, 'name' => 'Track 2'],
            ],
        ]);

        $this->assertGeneratedId($prepared['artist'], 'a nested document at depth 1');
        $this->assertGeneratedId($prepared['artist']['label'], 'a nested document at depth 2');
        [$first, $second] = $prepared['tracks'];
        $this->assertGeneratedId($first, 'the first document of a to-many relationship');
        $this->assertGeneratedId($second, 'the second document of a to-many relationship');
        $this->assertNotSame($first['$id'], $second['$id'], 'every nested document gets its own ID');
    }

    public function testNestedDocumentsWithoutAnIdGetGeneratedIds(): void
    {
        $prepared = $this->prepare([
            'artist' => [
                'name' => 'Artist',
                'label' => ['name' => 'Label'],
            ],
            'tracks' => [
                ['name' => 'Track 1'],
            ],
        ]);

        $this->assertGeneratedId($prepared['artist'], 'a nested document at depth 1');
        $this->assertGeneratedId($prepared['artist']['label'], 'a nested document at depth 2');
        $this->assertGeneratedId($prepared['tracks'][0], 'a document of a to-many relationship');
    }

    public function testExplicitNestedIdsAreKept(): void
    {
        $prepared = $this->prepare([
            'artist' => [
                '$id' => 'artist1',
                'label' => ['$id' => 'label1'],
            ],
            'tracks' => [
                ['$id' => 'track1', 'name' => 'Track 1'],
                'track2',
            ],
        ]);

        $this->assertSame('artist1', $prepared['artist']['$id']);
        $this->assertSame('label1', $prepared['artist']['label']['$id']);
        $this->assertSame('track1', $prepared['tracks'][0]['$id']);
        $this->assertSame('track2', $prepared['tracks'][1], 'a related document ID is a link, not a new document');
    }

    public function testToManyListKeepsLinksBesideNewDocuments(): void
    {
        $prepared = $this->prepare([
            'tracks' => [
                'track1',
                ['$id' => self::UNIQUE_ID, 'name' => 'Track 2'],
            ],
        ]);

        $this->assertSame('track1', $prepared['tracks'][0], 'a related document ID stays a link');
        $this->assertGeneratedId($prepared['tracks'][1], 'a nested document added to a to-many relationship');
    }

    public function testEveryPreparationGeneratesNewIds(): void
    {
        $document = ['artist' => ['$id' => self::UNIQUE_ID, 'name' => 'Artist']];

        $first = $this->prepare($document);
        $second = $this->prepare($document);

        $this->assertNotSame($first['artist']['$id'], $second['artist']['$id'], 'two requests must not link to, and overwrite, the same related document');
    }

    public function testNestedDocumentCarryingReadOnlyAttributesGetsAnId(): void
    {
        $prepared = $this->prepare([
            'artist' => [
                'name' => 'Artist',
                '$sequence' => '999',
                '$databaseId' => 'other',
            ],
        ]);

        $this->assertGeneratedId($prepared['artist'], 'a nested document the route removes read-only attributes from');
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('malformed')]
    public function testMalformedValuesAreRejected(array $document): void
    {
        $error = null;

        try {
            $this->prepare($document);
        } catch (Exception $caught) {
            $error = $caught;
        }

        $this->assertInstanceOf(Exception::class, $error, 'a malformed relationship value must be rejected');
        $this->assertSame(Exception::RELATIONSHIP_VALUE_INVALID, $error->getType());
        $this->assertSame(400, $error->getCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'a scalar' => [['artist' => 12345]];
        yield 'a malformed nested ID' => [['artist' => ['$id' => 'bad id!!', 'name' => 'Artist']]];
        yield 'a non-string nested ID' => [['artist' => ['$id' => 123, 'name' => 'Artist']]];
        yield 'a malformed related document ID' => [['artist' => 'bad id!!']];
        yield 'a scalar inside a to-many list' => [['tracks' => [12345]]];
        yield 'a list inside a to-many list' => [['tracks' => [['track1', 'track2']]]];
        yield 'a malformed nested ID at depth 2' => [['artist' => ['name' => 'Artist', 'label' => ['$id' => 'bad id!!']]]];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function prepare(array $document): array
    {
        $collections = self::collections();
        $values = new RelationshipValues(
            $this->catalog($collections),
            new Document(['$id' => 'music', '$sequence' => self::DATABASE_SEQUENCE]),
            new Authorization(),
        );

        return $values->prepare($document, $collections['albums']);
    }

    /**
     * @param array<string, Document> $collections
     */
    private function catalog(array $collections): Database
    {
        return new class ([self::CATALOG => $collections]) extends Database {
            /**
             * @param array<string, array<string, Document>> $catalog
             */
            public function __construct(private readonly array $catalog)
            {
                parent::__construct(new Memory(), new Cache(new None()));
            }

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                return $this->catalog[$collection][$id] ?? new Document();
            }
        };
    }

    private function assertGeneratedId(mixed $document, string $subject): void
    {
        $this->assertIsArray($document, $subject . ' must stay a nested document');
        $id = $document['$id'] ?? null;
        $this->assertIsString($id, $subject . ' must get an ID');
        $this->assertNotSame(self::UNIQUE_ID, $id, $subject . ' must not keep the literal unique() placeholder');
        $this->assertSame(self::GENERATED_ID_LENGTH, \strlen($id), $subject . ' must get a generated ID');
        $this->assertTrue(\ctype_xdigit($id), $subject . ' must get a generated ID');
    }

    /**
     * @return array<string, Document>
     */
    private static function collections(): array
    {
        return [
            'albums' => self::collection('albums', [
                self::attribute('title'),
                self::relationship('artist', 'artists', RelationType::ManyToOne),
                self::relationship('tracks', 'tracks', RelationType::OneToMany),
            ]),
            'artists' => self::collection('artists', [
                self::attribute('name'),
                self::relationship('label', 'labels', RelationType::ManyToOne),
            ]),
            'labels' => self::collection('labels', [self::attribute('name')]),
            'tracks' => self::collection('tracks', [self::attribute('name')]),
        ];
    }

    /**
     * @param list<Document> $attributes
     */
    private static function collection(string $id, array $attributes): Document
    {
        return new Document([
            '$id' => $id,
            'attributes' => $attributes,
        ]);
    }

    private static function attribute(string $key): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::String->value,
        ]);
    }

    private static function relationship(string $key, string $relatedCollection, RelationType $type): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'relatedCollection' => $relatedCollection,
            'relationType' => $type->value,
        ]);
    }
}
