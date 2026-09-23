<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules;

use Appwrite\Platform\Modules\Console\Http\Variables\Get;
use Appwrite\Platform\Modules\Databases\Http\Databases\Action;
use Appwrite\Utopia\Database\Adapter\Pool;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
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
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as ConnectionPool;

final class SpatialSupportTest extends TestCase
{
    private const string DOMAIN_TARGET = '_APP_DOMAIN_TARGET_CNAME';

    private string|false $domainTarget;

    protected function setUp(): void
    {
        $this->domainTarget = \getenv(self::DOMAIN_TARGET);
        \putenv(self::DOMAIN_TARGET . '=localhost');
    }

    protected function tearDown(): void
    {
        \putenv($this->domainTarget === false ? self::DOMAIN_TARGET : self::DOMAIN_TARGET . '=' . $this->domainTarget);
    }

    /**
     * @return iterable<string, array{Adapter, bool}>
     */
    public static function adapters(): iterable
    {
        yield 'MariaDB' => [new MariaDB(new \stdClass()), true];
        yield 'MySQL' => [new MySQL(new \stdClass()), true];
        yield 'Postgres' => [new Postgres(new \stdClass()), true];
        yield 'SQLite' => [new SQLite(new PDO('sqlite::memory:')), false];
        yield 'MongoDB' => [self::withoutConnecting(Mongo::class), false];
        yield 'Memory' => [new Memory(), false];
        yield 'pooled MariaDB' => [self::pooled(new MariaDB(new \stdClass())), true];
        yield 'pooled SQLite' => [self::pooled(new SQLite(new PDO('sqlite::memory:'))), false];
        yield 'spatial adapter without the spatial quirk capabilities' => [
            new class (new \stdClass()) extends MySQL {
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
    public function testSpatialAttributesFollowTheAdapterSpatialFeature(Adapter $adapter, bool $supported): void
    {
        $action = new class () extends Action {
            public function supportsSpatialAttributes(Adapter $adapter): bool
            {
                return $this->supportsSpatial($adapter);
            }
        };

        $this->assertSame($supported, $action->supportsSpatialAttributes($adapter));
    }

    #[DataProvider('adapters')]
    public function testConsoleVariablesAdvertiseTheAdapterSpatialFeature(Adapter $adapter, bool $supported): void
    {
        $database = $this->createStub(Database::class);
        $database->method('getAdapter')->willReturn($adapter);

        $variables = null;
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('dynamic')
            ->willReturnCallback(static function (Document $document) use (&$variables): void {
                $variables = $document;
            });

        (new Get())->action(
            static fn (): array => [],
            $this->createStub(VcsFactory::class),
            $response,
            ['sitesDomain' => 'sites.localhost', 'functionsDomain' => 'functions.localhost'],
            $database,
        );

        $this->assertInstanceOf(Document::class, $variables);
        $this->assertSame($supported, $variables->getAttribute('supportForSpatials'));
    }

    /**
     * @param class-string<Adapter> $class
     */
    private static function withoutConnecting(string $class): Adapter
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function pooled(Adapter $adapter): Pool
    {
        $pool = new Pool(new ConnectionPool(new Stack(), 'database_db_main', 1, static fn (): Adapter => $adapter, 1.0));
        $pool->setAuthorization(new Authorization());

        return $pool;
    }
}
