<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

final class PublicIdTest extends TestCase
{
    public function testResolvePublicIdParsesInternalName(): void
    {
        $database = $this->database(
            new Document(['$id' => 'libraries']),
            function (string $collection, array $queries): void {
                $this->assertSame('database_2', $collection);
                $this->assertSame(
                    [Query::equal('$sequence', ['17'])->toArray()],
                    \array_map(static fn (Query $query): array => $query->toArray(), $queries),
                );
            },
        );

        $this->assertSame('libraries', Metadata::resolvePublicId($database, 'database_2_collection_17'));
    }

    public function testResolvePublicIdReturnsInternalIdForUnknownShape(): void
    {
        $database = $this->database();

        foreach (['users', 'database_2', 'database__collection_1', ''] as $internalId) {
            $this->assertSame($internalId, Metadata::resolvePublicId($database, $internalId));
        }
    }

    public function testResolvePublicIdReturnsInternalIdWhenCatalogMissing(): void
    {
        $database = $this->database(new Document());

        $this->assertSame(
            'database_2_collection_17',
            Metadata::resolvePublicId($database, 'database_2_collection_17'),
        );
    }

    public function testResolvePublicIdReturnsInternalIdWhenCatalogIdEmpty(): void
    {
        $database = $this->database(new Document(['$id' => '']));

        $this->assertSame(
            'database_2_collection_17',
            Metadata::resolvePublicId($database, 'database_2_collection_17'),
        );
    }

    public function testResolvePublicIdWrapsLookupInSilentAndAuthorizationSkip(): void
    {
        $silent = false;
        $skip = false;

        $authorization = $this->createMock(Authorization::class);
        $authorization
            ->expects($this->once())
            ->method('skip')
            ->willReturnCallback(function (callable $callback) use (&$skip): mixed {
                $skip = true;

                return $callback();
            });

        $database = $this->createMock(Database::class);
        $database->method('getAuthorization')->willReturn($authorization);
        $database
            ->expects($this->once())
            ->method('silent')
            ->willReturnCallback(function (callable $callback) use (&$silent): mixed {
                $silent = true;

                return $callback();
            });
        $database->method('findOne')->willReturn(new Document(['$id' => 'libraries']));

        $this->assertSame('libraries', Metadata::resolvePublicId($database, 'database_2_collection_17'));
        $this->assertTrue($silent);
        $this->assertTrue($skip);
    }

    public function testResolverDoesNotQueryCatalogDuringTenantTransaction(): void
    {
        $tenant = $this->database(new Document(['$id' => 'movies']), inTransaction: true, hostname: 'mariadb');
        $catalog = $this->database(hostname: 'mariadb');

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesCatalogDuringTenantTransactionOnDifferentHost(): void
    {
        $tenant = $this->database(inTransaction: true, hostname: 'dedicated');
        $catalog = $this->database(new Document(['$id' => 'movies']), hostname: 'mariadb');

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesCatalogWhenTenantIsIdle(): void
    {
        $tenant = $this->database();
        $catalog = $this->database(new Document(['$id' => 'movies']));

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesSeededCatalogWithoutCheckout(): void
    {
        $tenant = $this->database();
        $catalog = $this->database();

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog, [
                'database_2_collection_17' => 'movies',
            ]))('database_2_collection_17'),
        );
    }

    public function testDecorateDuringTenantTransactionDoesNotQueryCatalog(): void
    {
        $tenant = $this->database(new Document(['$id' => 'movies']), inTransaction: true, hostname: 'mariadb');
        $catalog = $this->database(hostname: 'mariadb');

        $result = (new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'table',
            resolvePublicId: Metadata::resolver($tenant, $catalog),
        ))->decorate(
            Event::DocumentCreate,
            new Document(['$id' => 'database_2_collection_17']),
            new Document(['$id' => 'row1']),
        );

        $this->assertSame('movies', $result->getAttribute('$tableId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
    }

    public function testDecorateDuringDedicatedTransactionUsesCatalog(): void
    {
        $tenant = $this->database(inTransaction: true, hostname: 'dedicated');
        $catalog = $this->database(new Document(['$id' => 'movies']), hostname: 'mariadb');

        $result = (new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'table',
            resolvePublicId: Metadata::resolver($tenant, $catalog),
        ))->decorate(
            Event::DocumentCreate,
            new Document(['$id' => 'database_2_collection_17']),
            new Document(['$id' => 'row1']),
        );

        $this->assertSame('movies', $result->getAttribute('$tableId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
    }

    private function database(?Document $catalog = null, ?callable $assertFindOne = null, bool $inTransaction = false, string $hostname = ''): Database
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(fn (callable $callback): mixed => $callback());

        $adapter = $this->createStub(Adapter::class);
        $adapter->method('inTransaction')->willReturn($inTransaction);
        $adapter->method('getHostname')->willReturn($hostname);

        $database = $this->createMock(Database::class);
        $database->method('getAuthorization')->willReturn($authorization);
        $database->method('getAdapter')->willReturn($adapter);
        $database->method('silent')->willReturnCallback(fn (callable $callback): mixed => $callback());

        if ($catalog === null) {
            $database->expects($this->never())->method('findOne');

            return $database;
        }

        $database
            ->expects($this->once())
            ->method('findOne')
            ->willReturnCallback(function (string $collection, array $queries) use ($catalog, $assertFindOne): Document {
                if ($assertFindOne !== null) {
                    $assertFindOne($collection, $queries);
                }

                return $catalog;
            });

        return $database;
    }
}
