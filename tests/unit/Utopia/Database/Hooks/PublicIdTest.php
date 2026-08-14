<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
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

    private function database(?Document $catalog = null, ?callable $assertFindOne = null): Database
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(fn (callable $callback): mixed => $callback());

        $database = $this->createMock(Database::class);
        $database->method('getAuthorization')->willReturn($authorization);
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
