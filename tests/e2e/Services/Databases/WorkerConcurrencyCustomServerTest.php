<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiDocumentsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

/**
 * Live-stack proof that the databases worker can drain a burst of schema jobs
 * without deadlock failures. Complements WorkerConcurrencyTest (coroutine caps)
 * and JobsTest (combined vs dedicated wiring).
 */
final class WorkerConcurrencyCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiDocumentsDB;

    public function testQueuedAttributeCreatesCompleteWithoutDeadlock(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes not supported');
        }

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Worker Concurrency DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Concurrency Collection',
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Flood the databases queue: API accepts immediately; worker must drain
        // serially. Parallel schema DDL on the same database deadlocks adapters.
        $keys = [];
        for ($i = 0; $i < 20; $i++) {
            $key = 'c' . $i;
            $keys[] = $key;
            $response = $this->createAttribute($databaseId, $collectionId, 'string', [
                'key' => $key,
                'size' => 64,
                'required' => false,
            ]);
            $this->assertEquals(
                202,
                $response['headers']['status-code'],
                'Attribute enqueue failed for ' . $key . ': ' . \json_encode($response['body'] ?? []),
            );
        }

        foreach ($keys as $key) {
            $this->waitForAttribute($databaseId, $collectionId, $key);
        }

        foreach ($keys as $key) {
            $attribute = $this->client->call(
                Client::METHOD_GET,
                $this->getSchemaUrl($databaseId, $collectionId) . '/' . $key,
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ],
            );
            $this->assertEquals(200, $attribute['headers']['status-code']);
            $this->assertSame('available', $attribute['body']['status']);
            $this->assertNotSame('failed', $attribute['body']['status']);
        }
    }
}
