<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Create;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Upsert;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * Appwrite SDKs send `ID.unique()` as the literal `unique()`, so the request layer has to turn it into a
 * generated ID for nested related documents as well as for the document itself: the database library stores
 * a literal `unique()` verbatim, which makes every later request link to, and overwrite, that one document.
 * The same walk rejects relationship values the library cannot link with a 400 `relationship_value_invalid`.
 */
final class RelationshipPayloadTest extends TestCase
{
    private const string DATABASE_ID = 'music';
    private const string ALBUM_ID = 'album1';
    private const string USER_ID = 'user1';
    private const string TRANSACTION_ID = 'transaction1';
    private const string GENERATED_ID = '/^[a-f0-9]{20}$/';

    private Authorization $authorization;

    /**
     * @var array<string, array<string, Document>>
     */
    private array $metadata;

    /**
     * @var list<Document>
     */
    private array $written = [];

    private ?Document $staged = null;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::user(self::USER_ID)->toString());
        $this->authorization->addRole(Role::users()->toString());

        $this->metadata = [
            'databases' => [
                self::DATABASE_ID => new Document([
                    '$id' => self::DATABASE_ID,
                    '$sequence' => '1',
                    'type' => DATABASE_TYPE_LEGACY,
                    'enabled' => true,
                ]),
            ],
            'database_1' => [
                'albums' => self::collection('albums', '1', [
                    self::attribute('title'),
                    self::relationship('artist', 'artists', RelationType::ManyToOne),
                    self::relationship('tracks', 'tracks', RelationType::OneToMany),
                ]),
                'artists' => self::collection('artists', '2', [
                    self::attribute('name'),
                    self::relationship('label', 'labels', RelationType::ManyToOne),
                ]),
                'labels' => self::collection('labels', '3', [self::attribute('name')]),
                'tracks' => self::collection('tracks', '4', [self::attribute('name')]),
            ],
            'transactions' => [
                self::TRANSACTION_ID => new Document([
                    '$id' => self::TRANSACTION_ID,
                    '$sequence' => '1',
                    'status' => 'pending',
                    'operations' => 0,
                    'expiresAt' => '2999-01-01T00:00:00.000+00:00',
                ]),
            ],
        ];
    }

    public function testCreateGeneratesIdsForNestedDocumentsThatAskForOne(): void
    {
        $this->create([
            'title' => 'Album',
            'artist' => [
                '$id' => 'unique()',
                'name' => 'Artist',
                'label' => ['$id' => 'unique()', 'name' => 'Label'],
            ],
            'tracks' => [
                ['$id' => 'unique()', 'name' => 'Track 1'],
                ['$id' => 'unique()', 'name' => 'Track 2'],
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertGenerated($artist->getId(), 'a nested document at depth 1');
        $this->assertGenerated($artist->getAttribute('label')->getId(), 'a nested document at depth 2');

        [$first, $second] = $album->getAttribute('tracks');
        $this->assertGenerated($first->getId(), 'the first document of a to-many relationship');
        $this->assertGenerated($second->getId(), 'the second document of a to-many relationship');
        $this->assertNotSame($first->getId(), $second->getId(), 'every nested document gets its own ID');
    }

    public function testCreateGeneratesIdsForNestedDocumentsWithoutOne(): void
    {
        $this->create([
            'artist' => [
                'name' => 'Artist',
                'label' => ['name' => 'Label'],
            ],
            'tracks' => [
                ['name' => 'Track 1'],
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertGenerated($artist->getId(), 'a nested document at depth 1');
        $this->assertGenerated($artist->getAttribute('label')->getId(), 'a nested document at depth 2');
        $this->assertGenerated($album->getAttribute('tracks')[0]->getId(), 'a document of a to-many relationship');
    }

    public function testCreateKeepsExplicitNestedIds(): void
    {
        $this->create([
            'artist' => [
                '$id' => 'artist1',
                'label' => ['$id' => 'label1'],
            ],
            'tracks' => [
                ['$id' => 'track1', 'name' => 'Track 1'],
                'track2',
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertSame('artist1', $artist->getId());
        $this->assertSame('label1', $artist->getAttribute('label')->getId());
        $this->assertSame('track1', $album->getAttribute('tracks')[0]->getId());
        $this->assertSame('track2', $album->getAttribute('tracks')[1], 'a related document ID is a link, not a new document');
    }

    public function testCreateGeneratesDistinctIdsAcrossRequests(): void
    {
        $payload = ['artist' => ['$id' => 'unique()', 'name' => 'Artist']];

        $this->create($payload);
        $first = $this->written()->getAttribute('artist')->getId();
        $this->create($payload);
        $second = $this->written()->getAttribute('artist')->getId();

        $this->assertNotSame($first, $second, 'two requests must not link to, and overwrite, the same related document');
    }

    public function testCreateStripsReadonlyAttributesFromNestedDocumentsWithoutId(): void
    {
        $this->create([
            'artist' => [
                'name' => 'Artist',
                '$sequence' => '999',
                '$databaseId' => 'other',
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertFalse($artist->isSet('$sequence'), 'a client must not choose the internal sequence of a related document');
        $this->assertFalse($artist->isSet('$databaseId'));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedRelationships')]
    public function testCreateRejectsMalformedRelationshipValues(array $data): void
    {
        $this->assertRejected(fn () => $this->create($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedRelationships')]
    public function testUpdateRejectsMalformedRelationshipValues(array $data): void
    {
        $this->assertRejected(fn () => $this->update($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedRelationships')]
    public function testUpsertRejectsMalformedRelationshipValues(array $data): void
    {
        $this->assertRejected(fn () => $this->upsert($data));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedRelationships(): iterable
    {
        yield 'a scalar' => [['artist' => 12345]];
        yield 'a malformed nested ID' => [['artist' => ['$id' => 'bad id!!', 'name' => 'Artist']]];
        yield 'a non-string nested ID' => [['artist' => ['$id' => 123, 'name' => 'Artist']]];
        yield 'a malformed related document ID' => [['artist' => 'bad id!!']];
        yield 'a scalar inside a to-many list' => [['tracks' => [12345]]];
        yield 'a list inside a to-many list' => [['tracks' => [['track1', 'track2']]]];
        yield 'a malformed nested ID at depth 2' => [['artist' => ['name' => 'Artist', 'label' => ['$id' => 'bad id!!']]]];
    }

    public function testUpdateGeneratesIdsForNestedDocuments(): void
    {
        $this->update([
            'artist' => [
                '$id' => 'unique()',
                'name' => 'Artist',
                'label' => ['name' => 'Label'],
            ],
            'tracks' => [
                'track1',
                ['$id' => 'unique()', 'name' => 'Track 2'],
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertGenerated($artist->getId(), 'a nested document at depth 1');
        $this->assertGenerated($artist->getAttribute('label')->getId(), 'a nested document at depth 2');
        $this->assertSame('track1', $album->getAttribute('tracks')[0]);
        $this->assertGenerated($album->getAttribute('tracks')[1]->getId(), 'a nested document added to a to-many relationship');
    }

    public function testUpsertGeneratesIdsForNestedDocuments(): void
    {
        $this->upsert([
            'artist' => [
                '$id' => 'unique()',
                'name' => 'Artist',
                'label' => ['$id' => 'unique()', 'name' => 'Label'],
            ],
        ]);
        $album = $this->written();

        $artist = $album->getAttribute('artist');
        $this->assertGenerated($artist->getId(), 'a nested document at depth 1');
        $this->assertGenerated($artist->getAttribute('label')->getId(), 'a nested document at depth 2');
    }

    public function testStagedCreateCarriesGeneratedNestedIds(): void
    {
        $this->create(['artist' => ['$id' => 'unique()', 'name' => 'Artist']], self::TRANSACTION_ID);

        $this->assertSame([], $this->written, 'a staged create must not write');
        $this->assertGenerated($this->stagedData()->getAttribute('artist')->getId(), 'a nested document of a staged create');
    }

    public function testStagedUpdateCarriesGeneratedNestedIds(): void
    {
        $this->update(['artist' => ['$id' => 'unique()', 'name' => 'Artist']], self::TRANSACTION_ID);

        $this->assertSame([], $this->written, 'a staged update must not write');
        $this->assertGenerated($this->stagedData()->getAttribute('artist')->getId(), 'a nested document of a staged update');
    }

    public function testStagedUpsertCarriesGeneratedNestedIds(): void
    {
        $this->upsert(['artist' => ['$id' => 'unique()', 'name' => 'Artist']], self::TRANSACTION_ID);

        $this->assertSame([], $this->written, 'a staged upsert must not write');
        $this->assertGenerated($this->stagedData()->getAttribute('artist')->getId(), 'a nested document of a staged upsert');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function create(array $data, ?string $transactionId = null): void
    {
        (new Create())->action(
            databaseId: self::DATABASE_ID,
            documentId: self::ALBUM_ID,
            collectionId: 'albums',
            data: $data,
            permissions: null,
            documents: null,
            transactionId: $transactionId,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: fn (): Database => $this->documentsDatabase(new Document()),
            user: new User(['$id' => self::USER_ID]),
            queueForEvents: $this->createStub(Event::class),
            usage: new Context(),
            queueForRealtime: $this->createStub(Event::class),
            publisherForFunctions: $this->createStub(FunctionPublisher::class),
            queueForWebhooks: $this->createStub(Event::class),
            plan: [],
            authorization: $this->authorization,
            eventProcessor: $this->createStub(EventProcessor::class),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(array $data, ?string $transactionId = null): void
    {
        $existing = $this->existingAlbum();
        $transactionState = $this->createStub(TransactionState::class);
        $transactionState->method('getDocument')->willReturn($existing);

        (new Update())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            documentId: self::ALBUM_ID,
            data: $data,
            permissions: null,
            transactionId: $transactionId,
            requestTimestamp: null,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: fn (): Database => $this->documentsDatabase($existing),
            queueForEvents: $this->createStub(Event::class),
            usage: new Context(),
            transactionState: $transactionState,
            plan: [],
            authorization: $this->authorization,
            user: new User(['$id' => self::USER_ID]),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsert(array $data, ?string $transactionId = null): void
    {
        $transactionState = $this->createStub(TransactionState::class);
        $transactionState->method('getDocument')->willReturn(new Document());

        (new Upsert())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            documentId: self::ALBUM_ID,
            data: $data,
            permissions: null,
            transactionId: $transactionId,
            requestTimestamp: null,
            response: $this->createStub(Response::class),
            user: new User(['$id' => self::USER_ID]),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: fn (): Database => $this->documentsDatabase(new Document()),
            queueForEvents: $this->createStub(Event::class),
            usage: new Context(),
            transactionState: $transactionState,
            plan: [],
            authorization: $this->authorization,
        );
    }

    private function projectDatabase(): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id): Document => $this->metadata[$collection][$id] ?? new Document()
        );
        $database->method('withTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $database->method('createDocument')->willReturnCallback(function (string $collection, Document $document): Document {
            $this->staged = $document;

            return $document;
        });

        return $database;
    }

    private function documentsDatabase(Document $existing): Database
    {
        $write = function (string $collection, array $documents, int $batchSize = 0, ?callable $onNext = null): int {
            foreach ($documents as $document) {
                $this->written[] = $document;
                if ($onNext !== null) {
                    $onNext($document);
                }
            }

            return \count($documents);
        };

        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturn($existing);
        $database->method('withPreserveDates')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $database->method('withRequestTimestamp')->willReturnCallback(static fn (?\DateTime $timestamp, callable $callback): mixed => $callback());
        $database->method('createDocuments')->willReturnCallback($write);
        $database->method('upsertDocuments')->willReturnCallback($write);
        $database->method('updateDocument')->willReturnCallback(function (string $collection, string $id, Document $document): Document {
            $this->written[] = $document;

            return $document;
        });

        return $database;
    }

    private function existingAlbum(): Document
    {
        return new Document([
            '$id' => self::ALBUM_ID,
            '$sequence' => '1',
            '$permissions' => [
                Permission::read(Role::user(self::USER_ID)),
                Permission::update(Role::user(self::USER_ID)),
            ],
            'title' => 'Album',
        ]);
    }

    private function written(): Document
    {
        $this->assertNotEmpty($this->written, 'the document must reach the database');

        return $this->written[\array_key_last($this->written)];
    }

    private function stagedData(): Document
    {
        $this->assertInstanceOf(Document::class, $this->staged, 'the operation must be staged in the transaction log');
        $data = $this->staged->getAttribute('data');
        $this->assertInstanceOf(Document::class, $data);

        return $data;
    }

    private function assertGenerated(string $id, string $subject): void
    {
        $this->assertNotSame('unique()', $id, $subject . ' must not keep the literal unique() placeholder');
        $this->assertMatchesRegularExpression(self::GENERATED_ID, $id, $subject . ' must get a generated ID');
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
        } catch (Exception $error) {
            $this->assertSame(Exception::RELATIONSHIP_VALUE_INVALID, $error->getType());
            $this->assertSame(400, $error->getCode());
            $this->assertSame([], $this->written, 'a rejected payload must not reach the database');
            $this->assertNull($this->staged, 'a rejected payload must not be staged');

            return;
        }

        $this->fail('A malformed relationship value must be rejected with ' . Exception::RELATIONSHIP_VALUE_INVALID);
    }

    /**
     * @param list<Document> $attributes
     */
    private static function collection(string $id, string $sequence, array $attributes): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            'enabled' => true,
            'documentSecurity' => true,
            'attributes' => $attributes,
        ]);
    }

    private static function attribute(string $key): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::String->value,
            'size' => 128,
            'required' => false,
            'array' => false,
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
            'twoWay' => false,
            'twoWayKey' => '',
            'onDelete' => 'setNull',
            'side' => 'parent',
        ]);
    }
}
