<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Joins;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\RelationshipType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;
use Utopia\Query\Schema\ColumnType;

final class JoinsOneToManyTest extends TestCase
{
    public function testLeftJoinOnAOneToManyRelationshipIsRewritten(): void
    {
        $resolved = $this->resolve(Query::leftJoin('orders', 'ord', [Query::on('orders', '$id')]));

        $this->assertSame(Method::LeftJoin, $resolved->getMethod());
        $this->assertSame([['$id', '=', 'customer']], self::conditions($resolved));
    }

    public function testOnlyTheOneToManyConditionOfTheOnListIsRewritten(): void
    {
        $resolved = $this->resolve(Query::join('orders', 'ord', [
            Query::on('orders', '$id'),
            Query::on('region', 'region'),
            Query::equal('status', ['paid']),
        ]));

        $this->assertSame([['$id', '=', 'customer'], ['region', '=', 'region']], self::conditions($resolved));
        $this->assertSame(Method::Equal, $resolved->getValues()[2]->getMethod(), 'a filter in the ON list is not a column pair');
        $this->assertSame(['paid'], $resolved->getValues()[2]->getValues());
    }

    public function testOperatorOfTheRewrittenConditionIsKept(): void
    {
        $resolved = $this->resolve(Query::join('orders', 'ord', [Query::on('orders', '$id', '!=')]));

        $this->assertSame([['$id', '!=', 'customer']], self::conditions($resolved));
    }

    public function testConditionOnAnEarlierJoinsColumnIsNotTheMainRelationship(): void
    {
        $resolved = $this->joins()->resolve([
            Query::join('orders', 'ord', [Query::on('orders', '$id')]),
            Query::join('orders', 'sub', [Query::on('ord.orders', '$id')]),
        ], self::customers());

        $this->assertSame([['$id', '=', 'customer']], self::conditions($resolved[0]));
        $this->assertSame([['ord.orders', '=', '$id']], self::conditions($resolved[1]), 'a qualified left column belongs to an earlier join, not to the main collection');
    }

    public function testOneToManyWithoutATwoWayKeyKeepsItsColumns(): void
    {
        $customers = self::customers(self::relationship('orders', RelationshipType::OneToMany, twoWayKey: ''));

        $resolved = $this->joins()->resolve([Query::join('orders', 'ord', [Query::on('orders', '$id')])], $customers);

        $this->assertSame([['orders', '=', '$id']], self::conditions($resolved[0]));
    }

    public function testRelationshipReadFromTopLevelAttributesIsRewritten(): void
    {
        $customers = self::customers(new Document([
            'key' => 'orders',
            'type' => ColumnType::Relationship->value,
            'relationType' => RelationshipType::OneToMany->value,
            'twoWayKey' => 'customer',
        ]));

        $resolved = $this->joins()->resolve([Query::join('orders', 'ord', [Query::on('orders', '$id')])], $customers);

        $this->assertSame([['$id', '=', 'customer']], self::conditions($resolved[0]));
    }

    public function testRewrittenJoinKeepsItsAliasAndResolvedTable(): void
    {
        $resolved = $this->resolve(Query::join('orders', 'ord', [Query::on('orders', '$id')]));

        $this->assertSame('ord', $resolved->getAlias());
        $this->assertSame('database_1_collection_2', $resolved->getAttribute());
    }

    private function resolve(Query $join): Query
    {
        $resolved = $this->joins()->resolve([$join], self::customers());

        $this->assertCount(1, $resolved);

        return $resolved[0];
    }

    /**
     * @return list<array<mixed>>
     */
    private static function conditions(Query $join): array
    {
        return \array_values(\array_map(
            static fn (Query $condition): array => $condition->getValues(),
            \array_filter($join->getJoinOnQueries(), static fn (Query $condition): bool => $condition->getMethod() === Method::On),
        ));
    }

    private function joins(): Joins
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        return new Joins(
            dbForProject: self::catalog(),
            database: new Document(['$id' => 'shop', '$sequence' => '1']),
            authorization: $authorization,
            privileged: false,
            notFound: Exception::COLLECTION_NOT_FOUND,
        );
    }

    private static function catalog(): Database
    {
        $orders = new Document([
            '$id' => 'orders',
            '$sequence' => '2',
            '$permissions' => [Permission::read(Role::any())],
            'enabled' => true,
            'documentSecurity' => false,
        ]);

        return new class ($orders) extends Database {
            public function __construct(private readonly Document $orders)
            {
                parent::__construct(new Memory(), new Cache(new None()));
            }

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                return $id === $this->orders->getId() ? $this->orders : new Document();
            }

            #[\Override]
            public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
            {
                return [];
            }
        };
    }

    private static function customers(?Document $orders = null): Document
    {
        return new Document([
            '$id' => 'customers',
            '$sequence' => '1',
            'attributes' => [
                $orders ?? self::relationship('orders', RelationshipType::OneToMany, twoWayKey: 'customer'),
                self::relationship('region', RelationshipType::ManyToOne, twoWayKey: 'customers'),
            ],
        ]);
    }

    private static function relationship(string $key, RelationshipType $type, string $twoWayKey): Document
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
}
