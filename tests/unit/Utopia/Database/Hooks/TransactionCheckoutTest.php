<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Adapter\Pool as DatabasePool;
use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Pool as UtopiaPool;

final class TransactionCheckoutTest extends TestCase
{
    public function testCatalogLookupDuringPinnedTransactionCheckoutsAgain(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Pool 'test' could not provide a connection");

        $shared = $this->sharedPool();

        $shared['tenant']->withTransaction(function () use ($shared): void {
            Metadata::resolvePublicId($shared['catalog'], $shared['internalId']);
        });
    }

    public function testResolverDoesNotCheckoutCatalogDuringPinnedTransaction(): void
    {
        $shared = $this->sharedPool();

        $publicId = $shared['tenant']->withTransaction(
            fn (): string => (Metadata::resolver($shared['tenant'], $shared['catalog']))($shared['internalId']),
        );

        $this->assertSame('movies', $publicId);
        $this->assertSame(1, $shared['checkouts']);
    }

    public function testResolverReadsCatalogOnDedicatedHostDuringPinnedTransaction(): void
    {
        $dedicated = $this->dedicatedPools();

        $publicId = $dedicated['tenant']->withTransaction(
            fn (): string => (Metadata::resolver($dedicated['tenant'], $dedicated['catalog']))($dedicated['internalId']),
        );

        $this->assertSame('movies', $publicId);
        $this->assertSame(1, $dedicated['tenantCheckouts']);
        $this->assertGreaterThanOrEqual(1, $dedicated['catalogCheckouts']);
    }

    /**
     * @return array{tenant: Database, catalog: Database, internalId: string, checkouts: int}
     */
    private function sharedPool(): array
    {
        $memory = new Memory();
        $cache = new Cache(new NoCache());
        $authorization = new Authorization();
        $checkouts = 0;

        $setup = new Database($memory, $cache);
        $setup
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace('txn_' . \uniqid());
        $setup->create();
        $setup->createCollection('database_2', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $catalog = $authorization->skip(
            fn (): Document => $setup->createDocument('database_2', new Document([
                '$id' => 'movies',
                '$permissions' => [Permission::read(Role::any())],
            ]))
        );

        $connections = $this->createStub(UtopiaPool::class);
        $connections->method('use')->willReturnCallback(
            function (callable $callback) use ($memory, &$checkouts): mixed {
                $checkouts++;
                if ($checkouts > 1) {
                    throw new \Exception("Pool 'test' could not provide a connection within 0.1s (size 1, active 1, idle 0)");
                }

                return $callback($memory);
            }
        );

        $tenant = new Database((new DatabasePool($connections))->setHostname('mariadb'), $cache);
        $tenant
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($setup->getNamespace());

        $project = new Database((new DatabasePool($connections))->setHostname('mariadb'), $cache);
        $project
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($setup->getNamespace());

        $sequence = $catalog->getSequence();
        $this->assertNotNull($sequence);
        $this->assertNotSame('', $sequence);

        return [
            'tenant' => $tenant,
            'catalog' => $project,
            'internalId' => 'database_2_collection_' . $sequence,
            'checkouts' => &$checkouts,
        ];
    }

    /**
     * @return array{tenant: Database, catalog: Database, internalId: string, tenantCheckouts: int, catalogCheckouts: int}
     */
    private function dedicatedPools(): array
    {
        $cache = new Cache(new NoCache());
        $authorization = new Authorization();
        $tenantMemory = new Memory();
        $catalogMemory = new Memory();
        $namespace = 'txn_' . \uniqid();

        $catalogSetup = new Database($catalogMemory, $cache);
        $catalogSetup
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($namespace);
        $catalogSetup->create();
        $catalogSetup->createCollection('database_2', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);
        $catalog = $authorization->skip(
            fn (): Document => $catalogSetup->createDocument('database_2', new Document([
                '$id' => 'movies',
                '$permissions' => [Permission::read(Role::any())],
            ]))
        );

        $tenantSetup = new Database($tenantMemory, $cache);
        $tenantSetup
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($namespace);
        $tenantSetup->create();
        $tenantSetup->createCollection('database_2', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]);

        $tenantCheckouts = 0;
        $catalogCheckouts = 0;

        $tenantConnections = $this->createStub(UtopiaPool::class);
        $tenantConnections->method('use')->willReturnCallback(
            function (callable $callback) use ($tenantMemory, &$tenantCheckouts): mixed {
                $tenantCheckouts++;
                if ($tenantCheckouts > 1) {
                    throw new \Exception("Pool 'dedicated' could not provide a connection within 0.1s (size 1, active 1, idle 0)");
                }

                return $callback($tenantMemory);
            }
        );

        $catalogConnections = $this->createStub(UtopiaPool::class);
        $catalogConnections->method('use')->willReturnCallback(
            function (callable $callback) use ($catalogMemory, &$catalogCheckouts): mixed {
                $catalogCheckouts++;

                return $callback($catalogMemory);
            }
        );

        $tenant = new Database((new DatabasePool($tenantConnections))->setHostname('dedicated'), $cache);
        $tenant
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($namespace);

        $project = new Database((new DatabasePool($catalogConnections))->setHostname('mariadb'), $cache);
        $project
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($namespace);

        $sequence = $catalog->getSequence();
        $this->assertNotNull($sequence);
        $this->assertNotSame('', $sequence);

        return [
            'tenant' => $tenant,
            'catalog' => $project,
            'internalId' => 'database_2_collection_' . $sequence,
            'tenantCheckouts' => &$tenantCheckouts,
            'catalogCheckouts' => &$catalogCheckouts,
        ];
    }
}
