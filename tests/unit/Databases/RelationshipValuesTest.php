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
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class RelationshipValuesTest extends TestCase
{
    private const string UNIQUE_ID = 'unique()';
    private const int GENERATED_ID_LENGTH = 20;
    private const string DATABASE_SEQUENCE = '1';
    private const string CATALOG = 'database_' . self::DATABASE_SEQUENCE;
    private const string CALLER = 'caller';

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

    public function testNestedDocumentCannotGrantARoleTheCallerLacks(): void
    {
        $this->assertUnauthorized([
            'artist' => [
                '$id' => 'artist1',
                '$permissions' => [Permission::update(Role::user('other'))],
            ],
        ], 'a nested document granting a role the caller lacks');
    }

    public function testNestedDocumentDeeperDownCannotGrantARoleTheCallerLacks(): void
    {
        $this->assertUnauthorized([
            'artist' => [
                '$id' => 'artist1',
                'label' => ['$id' => 'label1', '$permissions' => [Permission::read(Role::any()), Permission::delete(Role::user('other'))]],
            ],
        ], 'a nested document at depth 2 granting a role the caller lacks');
    }

    public function testNewNestedDocumentInAToManyListCannotGrantARoleTheCallerLacks(): void
    {
        $this->assertUnauthorized([
            'tracks' => [
                'track1',
                ['name' => 'Track 2', '$permissions' => [Permission::write(Role::users())]],
            ],
        ], 'a new nested document granting a role the caller lacks');
    }

    public function testNestedDocumentMayGrantRolesTheCallerHolds(): void
    {
        $permissions = [Permission::read(Role::any()), Permission::update(Role::user(self::CALLER))];

        $prepared = $this->prepareAsCaller([
            'artist' => ['$id' => 'artist1', '$permissions' => $permissions],
        ]);

        $this->assertSame($permissions, $prepared['artist']['$permissions']);
    }

    public function testNestedDocumentMaySendBackPermissionsItAlreadyHas(): void
    {
        $permissions = [Permission::read(Role::any()), Permission::update(Role::user('other'))];

        $prepared = $this->prepareAsCaller(
            ['artist' => ['$id' => 'artist1', 'name' => 'Renamed', '$permissions' => $permissions]],
            ['artists' => [new Document(['$id' => 'artist1', '$permissions' => $permissions])]],
        );

        $this->assertSame('Renamed', $prepared['artist']['name']);
    }

    public function testNestedDocumentWithoutPermissionsIsNotChecked(): void
    {
        $prepared = $this->prepareAsCaller([
            'artist' => ['$id' => 'artist1', 'name' => 'Artist'],
        ]);

        $this->assertSame('artist1', $prepared['artist']['$id']);
    }

    public function testNestedDocumentWithMalformedPermissionsIsRejected(): void
    {
        $error = null;

        try {
            $this->prepareAsCaller(['artist' => ['$id' => 'artist1', '$permissions' => ['not a permission']]]);
        } catch (Exception $caught) {
            $error = $caught;
        }

        $this->assertInstanceOf(Exception::class, $error, 'malformed nested permissions must be rejected');
        $this->assertSame(Exception::GENERAL_BAD_REQUEST, $error->getType());
    }

    public function testWithoutTheRelatedDocumentsAnyRoleMayBeGranted(): void
    {
        $permissions = [Permission::update(Role::user('other'))];

        $prepared = $this->prepare(['artist' => ['$id' => 'artist1', '$permissions' => $permissions]]);

        $this->assertSame($permissions, $prepared['artist']['$permissions'], 'API keys and privileged users are not checked');
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertUnauthorized(array $document, string $subject): void
    {
        $error = null;

        try {
            $this->prepareAsCaller($document);
        } catch (Exception $caught) {
            $error = $caught;
        }

        $this->assertInstanceOf(Exception::class, $error, $subject . ' must be refused');
        $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        $this->assertSame(401, $error->getCode());
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, list<Document>> $stored
     * @return array<string, mixed>
     */
    private function prepareAsCaller(array $document, array $stored = []): array
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::user(self::CALLER)->toString());

        $tables = [];
        foreach ($stored as $collection => $documents) {
            foreach ($documents as $related) {
                $tables[self::CATALOG . '_collection_' . $collection][$related->getId()] = $related;
            }
        }

        $collections = self::collections();
        $values = new RelationshipValues(
            $this->catalog($collections),
            new Document(['$id' => 'music', '$sequence' => self::DATABASE_SEQUENCE]),
            $authorization,
            $this->tables($tables),
        );

        return $values->prepare($document, $collections['albums']);
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
        return $this->tables([self::CATALOG => $collections]);
    }

    /**
     * @param array<string, array<string, Document>> $tables
     */
    private function tables(array $tables): Database
    {
        return new class ($tables) extends Database {
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
            '$sequence' => $id,
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
