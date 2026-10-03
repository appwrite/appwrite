<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Joins;
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
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class JoinsTest extends TestCase
{
    private const string CATALOG = 'database_1';

    private Authorization $authorization;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());
        $this->authorization->addRole(Role::users()->toString());
        $this->authorization->addRole(Role::user('reader')->toString());
    }

    public function testJoinOfACollectionReadableAtCollectionLevelReadsItsTable(): void
    {
        $join = $this->resolve('shared');

        $this->assertSame('database_1_collection_2', $join->getAttribute());
    }

    public function testJoinOfADocumentSecurityCollectionWithoutCollectionReadReadsItsTable(): void
    {
        $join = $this->resolve('owned');

        $this->assertSame('database_1_collection_3', $join->getAttribute(), 'listing it directly returns the rows the caller holds document read on, so joining it must too');
    }

    public function testJoinOfACollectionTheCallerCannotListIsUnauthorized(): void
    {
        $refusal = $this->refusal('closed');

        $this->assertSame(Exception::USER_UNAUTHORIZED, $refusal->getType(), 'a collection without collection-level read and without document security cannot be joined');
        $this->assertSame(401, $refusal->getCode());
    }

    public function testJoinOfADisabledCollectionIsNotFound(): void
    {
        $refusal = $this->refusal('disabled');

        $this->assertSame(Exception::COLLECTION_NOT_FOUND, $refusal->getType());
        $this->assertSame(404, $refusal->getCode());
    }

    public function testJoinOfADisabledTableIsTableNotFound(): void
    {
        $refusal = $this->refusal('disabled', $this->joins(notFound: Exception::TABLE_NOT_FOUND));

        $this->assertSame(Exception::TABLE_NOT_FOUND, $refusal->getType(), 'the refusal names the resource the route serves');
        $this->assertSame(404, $refusal->getCode());
    }

    #[DataProvider('unlistable')]
    public function testPrivilegedCallerJoinsACollectionAUserCannotList(string $joined, string $table): void
    {
        $join = $this->resolve($joined, $this->joins(privileged: true));

        $this->assertSame($table, $join->getAttribute(), 'API keys and privileged users read every collection of their project');
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function unlistable(): \Iterator
    {
        yield 'without collection read or document security' => ['closed', 'database_1_collection_4'];
        yield 'disabled' => ['disabled', 'database_1_collection_5'];
    }

    public function testJoinByPhysicalNameIsLookedUpBySequence(): void
    {
        $join = $this->resolve('database_1_collection_3');

        $this->assertSame('database_1_collection_3', $join->getAttribute());
    }

    #[DataProvider('refusals')]
    public function testJoinByPhysicalNameGetsTheSameChecks(string $joined, string $type): void
    {
        $refusal = $this->refusal($joined);

        $this->assertSame($type, $refusal->getType());
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function refusals(): \Iterator
    {
        yield 'disabled' => ['database_1_collection_5', Exception::COLLECTION_NOT_FOUND];
        yield 'without collection read or document security' => ['database_1_collection_4', Exception::USER_UNAUTHORIZED];
        yield 'unknown sequence' => ['database_1_collection_9', Exception::COLLECTION_NOT_FOUND];
        yield 'not a sequence' => ['database_1_collection_x', Exception::COLLECTION_NOT_FOUND];
        yield 'another database' => ['database_2_collection_2', Exception::COLLECTION_NOT_FOUND];
    }

    public function testJoinOnAOneToManyRelationshipMatchesTheMainIdAgainstTheRelatedKey(): void
    {
        $customers = self::customers(self::relationship('orders', RelationType::OneToMany, twoWayKey: 'customer'));

        $resolved = $this->joins()->resolve([Query::join('shared', 'orders', '$id', '=', 'ord')], $customers);

        $this->assertSame(['$id', '=', 'customer', 'ord'], $resolved[0]->getValues(), 'a one-to-many relationship is stored on the related side, in its two-way key');
    }

    public function testJoinOnAManyToOneRelationshipKeepsItsColumns(): void
    {
        $customers = self::customers(self::relationship('region', RelationType::ManyToOne, twoWayKey: 'customers'));

        $resolved = $this->joins()->resolve([Query::join('shared', 'region', '$id', '=', 'reg')], $customers);

        $this->assertSame(['region', '=', '$id', 'reg'], $resolved[0]->getValues());
    }

    private function joins(bool $privileged = false, string $notFound = Exception::COLLECTION_NOT_FOUND): Joins
    {
        return new Joins(
            dbForProject: $this->catalog(),
            database: new Document(['$id' => 'shop', '$sequence' => '1']),
            authorization: $this->authorization,
            privileged: $privileged,
            notFound: $notFound,
        );
    }

    private function resolve(string $joined, ?Joins $joins = null): Query
    {
        $resolved = ($joins ?? $this->joins())->resolve([Query::join($joined, '$id', 'customerId', '=', 'ord')], self::customers());

        $this->assertCount(1, $resolved);

        return $resolved[0];
    }

    private function refusal(string $joined, ?Joins $joins = null): Exception
    {
        try {
            ($joins ?? $this->joins())->resolve([Query::join($joined, '$id', 'customerId', '=', 'ord')], self::customers());
        } catch (Exception $refusal) {
            return $refusal;
        }

        $this->fail('the join of ' . $joined . ' must be refused');
    }

    private function catalog(): Database
    {
        return new class ([self::CATALOG => [
            self::collection('customers', '1', [Permission::read(Role::any())], documentSecurity: true),
            self::collection('shared', '2', [Permission::read(Role::any())], documentSecurity: false),
            self::collection('owned', '3', [Permission::create(Role::any())], documentSecurity: true),
            self::collection('closed', '4', [Permission::create(Role::any())], documentSecurity: false),
            self::collection('disabled', '5', [Permission::read(Role::any())], documentSecurity: true, enabled: false),
        ]]) extends Database {
            /**
             * @param array<string, list<Document>> $registries
             */
            public function __construct(private readonly array $registries)
            {
                parent::__construct(new Memory(), new Cache(new None()));
            }

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                foreach ($this->registries[$collection] ?? [] as $entry) {
                    if ($entry->getId() === $id) {
                        return $entry;
                    }
                }

                return new Document();
            }

            #[\Override]
            public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
            {
                $sequences = [];
                foreach ($queries as $query) {
                    if ($query->getAttribute() === '$sequence') {
                        $sequences = $query->getValues();
                    }
                }

                return \array_values(\array_filter(
                    $this->registries[$collection] ?? [],
                    static fn (Document $entry): bool => \in_array($entry->getSequence(), $sequences, true),
                ));
            }
        };
    }

    private static function customers(Document ...$attributes): Document
    {
        return self::collection('customers', '1', [Permission::read(Role::any())], documentSecurity: true, attributes: $attributes);
    }

    private static function relationship(string $key, RelationType $type, string $twoWayKey): Document
    {
        return new Document([
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'options' => [
                'relationType' => $type->value,
                'twoWayKey' => $twoWayKey,
            ],
        ]);
    }

    /**
     * @param list<string> $permissions
     * @param list<Document> $attributes
     */
    private static function collection(string $id, string $sequence, array $permissions, bool $documentSecurity, bool $enabled = true, array $attributes = []): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            '$permissions' => $permissions,
            'enabled' => $enabled,
            'documentSecurity' => $documentSecurity,
            'attributes' => $attributes,
        ]);
    }
}
