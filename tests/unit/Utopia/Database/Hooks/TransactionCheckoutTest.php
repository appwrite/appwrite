<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\Pool as DatabasePool;
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

        $tenant = new Database(new DatabasePool($connections), $cache);
        $tenant
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setNamespace($setup->getNamespace());

        $project = new Database(new DatabasePool($connections), $cache);
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
}
