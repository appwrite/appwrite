<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Adapter;

use Appwrite\Utopia\Database\Adapter\Pool;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

final class PoolTest extends TestCase
{
    public function testAPoolNamedForItsHostAnswersTheHostnameWithoutACheckout(): void
    {
        $connection = $this->connection('mariadb');
        $pool = $this->pool($connection)->setHostname('database_db_main');

        $this->assertSame('database_db_main', $pool->hostname());
        $this->assertSame(0, $connection->checkouts);
    }

    public function testAPoolWithoutAHostnameAsksItsConnection(): void
    {
        $connection = $this->connection('mariadb');

        $this->assertSame('mariadb', $this->pool($connection)->hostname());
        $this->assertSame(1, $connection->checkouts);
    }

    public function testBuildingCacheKeysChecksOutNoConnection(): void
    {
        $connection = $this->connection('mariadb');
        $database = new Database($this->pool($connection)->setHostname('database_db_main'), new Cache(new None()));
        $database->getCacheBaseKeys('movies');
        $checkouts = $connection->checkouts;

        [$collectionKey, $documentKey] = $database->getCacheBaseKeys('movies', 'movie1');
        $database->getHostname();

        $this->assertSame($checkouts, $connection->checkouts);
        $this->assertStringContainsString('-cache-database_db_main:', $collectionKey);
        $this->assertStringContainsString('-cache-database_db_main:', $documentKey);
    }

    /**
     * @return ConnectedMemory&object{checkouts: int}
     */
    private function connection(string $hostname): ConnectedMemory
    {
        $connection = new class () extends ConnectedMemory {
            public int $checkouts = 0;

            #[\Override]
            public function setDatabase(string $name): static
            {
                $this->checkouts++;

                return parent::setDatabase($name);
            }
        };
        $connection->setHostname($hostname);

        return $connection;
    }

    private function pool(ConnectedMemory $connection): Pool
    {
        $pool = new Pool(new UtopiaPool(new Stack(), 'database_db_main', 1, static fn (): ConnectedMemory => $connection, 1.0));
        $pool->setAuthorization(new Authorization());

        return $pool;
    }
}
