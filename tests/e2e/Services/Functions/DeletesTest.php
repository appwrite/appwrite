<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Functions;

use Appwrite\Event\Publisher\Delete;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Storage\Device;

final class DeletesTest extends TestCase
{
    public function testExecutionRetentionVisitsEveryResourceOnce(): void
    {
        $database = new Database(new SQLite(new PDO('sqlite::memory:')), new Cache(new None()));
        $database->setDatabase('pagination')->setNamespace('pagination');
        $database->getAuthorization()->disable();
        $database->create();
        $database->createCollection('functions');
        $database->createCollection('sites');
        $database->createCollection('targets', [new Document(['$id' => 'expired', 'type' => Database::VAR_BOOLEAN, 'required' => false])]);
        foreach (['sessions', 'challenges'] as $collection) {
            $database->createCollection($collection, [new Document(['$id' => 'expire', 'type' => Database::VAR_DATETIME, 'required' => false])]);
        }
        foreach (['transactions', 'presenceLogs'] as $collection) {
            $database->createCollection($collection, [new Document(['$id' => 'expiresAt', 'type' => Database::VAR_DATETIME, 'required' => false])]);
        }
        $database->createCollection('tokens', [
            new Document(['$id' => 'expire', 'type' => Database::VAR_DATETIME, 'required' => false]),
            new Document(['$id' => 'type', 'type' => Database::VAR_INTEGER, 'size' => 4, 'required' => false]),
        ]);
        $database->createCollection('migrations', [new Document(['$id' => 'status', 'type' => Database::VAR_STRING, 'size' => 32, 'required' => false])]);

        $resources = [];
        for ($i = 0; $i < 2837; $i++) {
            $resource = $database->createDocument('functions', new Document(['$id' => 'function-' . $i]));
            $resources[] = $resource->getSequence();
        }
        $site = $database->createDocument('sites', new Document(['$id' => 'site']));
        $expected = ['functions' => $resources, 'sites' => [$site->getSequence()]];

        $deleted = [];
        $store = $this->createMock(Store::class);
        $store->expects($this->exactly(2838))->method('find')->willReturn([new Document(['$createdAt' => '2026-01-01T00:00:00.000+00:00'])]);
        $store->expects($this->exactly(2838))->method('deleteByResource')->willReturnCallback(function (string $projectId, string $resourceId, string $type, ?string $cutoff) use (&$deleted): void {
            $this->assertSame('project', $projectId);
            $this->assertSame('2026-01-01T00:00:00.000+00:00', $cutoff);
            // Fail on a repeated resource instead of hanging on a pagination loop.
            $this->assertArrayNotHasKey($resourceId, $deleted[$type] ?? []);
            $deleted[$type][$resourceId] = $resourceId;
        });
        $device = $this->createStub(Device::class);

        (new Deletes())->action(
            message: (new Message())->setPayload(['type' => DELETE_TYPE_MAINTENANCE]),
            project: new Document(['$id' => 'project']),
            dbForPlatform: $database,
            getProjectDB: fn () => $database,
            getDatabasesDB: fn () => $database,
            deviceForFiles: $device,
            deviceForFunctions: $device,
            deviceForSites: $device,
            deviceForBuilds: $device,
            deviceForCache: $device,
            certificates: $this->createStub(Provider::class),
            executor: $this->createStub(Executor::class),
            executionRetention: '',
            executionsRetentionCount: 1,
            publisherForDeletes: $this->createStub(Delete::class),
            publisherForUsage: $this->createStub(Usage::class),
            bus: $this->createStub(Bus::class),
            executionStore: $store,
        );

        foreach ($expected as $type => $ids) {
            $this->assertSame($ids, array_values($deleted[$type]));
            $this->assertSame(count($ids), $database->count($type));
        }
    }
}
