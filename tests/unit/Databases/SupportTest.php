<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Support;
use Appwrite\Utopia\Database\Adapter\Pool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Capability;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as ConnectionPool;

final class SupportTest extends TestCase
{
    /**
     * @return iterable<string, array{Adapter, bool}>
     */
    public static function adapters(): iterable
    {
        yield 'MariaDB' => [new MariaDB(new \stdClass()), true];
        yield 'MySQL' => [new MySQL(new \stdClass()), true];
        yield 'Postgres' => [new Postgres(new \stdClass()), true];
        yield 'SQLite' => [new SQLite(new PDO('sqlite::memory:')), false];
        yield 'MongoDB' => [self::mongoWithoutConnecting(), false];
        yield 'Memory' => [new Memory(), false];
        yield 'pooled MariaDB' => [self::pooled(new MariaDB(new PDO('sqlite::memory:'))), true];
        yield 'pooled SQLite' => [self::pooled(new SQLite(new PDO('sqlite::memory:'))), false];
        yield 'spatial adapter without the spatial quirk capabilities' => [
            new class (new \stdClass()) extends MySQL {
                #[\Override]
                public function capabilities(): array
                {
                    return \array_values(\array_filter(
                        parent::capabilities(),
                        static fn (Capability $capability): bool => $capability !== Capability::SpatialAxisOrder,
                    ));
                }
            },
            true,
        ];
        yield 'adapter with a spatial quirk capability but no spatial types' => [
            new class () extends Memory {
                #[\Override]
                public function capabilities(): array
                {
                    return [
                        ...parent::capabilities(),
                        Capability::SpatialAxisOrder,
                    ];
                }
            },
            false,
        ];
    }

    #[DataProvider('adapters')]
    public function testSpatialSupportIsTheAdapterSpatialFeature(Adapter $adapter, bool $supported): void
    {
        $this->assertSame($supported, Support::spatial($adapter));
    }

    private static function mongoWithoutConnecting(): Mongo
    {
        return new class () extends Mongo {
            public function __construct()
            {
            }
        };
    }

    private static function pooled(Adapter $adapter): Pool
    {
        $pool = new Pool(new ConnectionPool(new Stack(), 'database_db_main', 1, static fn (): Adapter => $adapter, 1.0));
        $pool->setAuthorization(new Authorization());

        return $pool;
    }
}
