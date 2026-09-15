<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use WebSocket\Client as WebSocketClient;

trait RealtimeRelationshipBase
{
    /**
     * Create linked records through public APIs so relationship workers and permissions are exercised.
     */
    private function createRelationshipRecords(string $type, string $api = 'databases', string $onDelete = 'setNull'): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $tables = $api === 'tablesdb';
        $collections = $tables ? 'tables' : 'collections';
        $documents = $tables ? 'rows' : 'documents';
        $attributes = $tables ? 'columns' : 'attributes';
        $collectionId = $tables ? 'tableId' : 'collectionId';
        $documentId = $tables ? 'rowId' : 'documentId';
        $database = $this->client->call(Client::METHOD_POST, '/' . $api, $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Relationship events',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];
        $base = '/' . $api . '/' . $databaseId . '/' . $collections;
        $records = [];

        foreach (['parent', 'child'] as $side) {
            $collection = $this->client->call(Client::METHOD_POST, $base, $headers, [
                $collectionId => ID::unique(),
                'name' => $side,
                $tables ? 'rowSecurity' : 'documentSecurity' => true,
                'permissions' => [Permission::create(Role::any())],
            ]);
            $this->assertSame(201, $collection['headers']['status-code']);
            $id = $collection['body']['$id'];
            $path = $base . '/' . $id;
            $attribute = $this->client->call(Client::METHOD_POST, $path . '/' . $attributes . '/string', $headers, [
                'key' => 'name',
                'size' => 64,
                'required' => false,
            ]);
            $this->assertSame(202, $attribute['headers']['status-code']);
            $this->assertEventually(function () use ($path, $attributes, $headers): void {
                $attribute = $this->client->call(Client::METHOD_GET, $path . '/' . $attributes . '/name', $headers);
                $this->assertSame('available', $attribute['body']['status']);
            }, 30000, 100);
            $record = $this->client->call(Client::METHOD_POST, $path . '/' . $documents, $headers, [
                $documentId => ID::unique(),
                'data' => ['name' => $side],
                'permissions' => [Permission::read(Role::any()), Permission::delete(Role::any())],
            ]);
            $this->assertSame(201, $record['headers']['status-code']);
            $records[$side] = [
                'collectionId' => $id,
                'id' => $record['body']['$id'],
                'path' => $path . '/' . $documents . '/' . $record['body']['$id'],
            ];
        }

        $relationship = $this->client->call(Client::METHOD_POST, $base . '/' . $records['parent']['collectionId'] . '/' . $attributes . '/relationship', $headers, [
            $tables ? 'relatedTableId' : 'relatedCollectionId' => $records['child']['collectionId'],
            'type' => $type,
            'twoWay' => true,
            'key' => 'children',
            'twoWayKey' => 'parents',
            'onDelete' => $onDelete,
        ]);
        $this->assertSame(202, $relationship['headers']['status-code']);
        foreach (['parent' => 'children', 'child' => 'parents'] as $side => $key) {
            $path = $base . '/' . $records[$side]['collectionId'] . '/' . $attributes . '/' . $key;
            $this->assertEventually(function () use ($path, $headers): void {
                $attribute = $this->client->call(Client::METHOD_GET, $path, $headers);
                $this->assertSame('available', $attribute['body']['status']);
            }, 30000, 100);
        }

        $many = \in_array($type, ['oneToMany', 'manyToMany']);
        $linked = $this->client->call(Client::METHOD_PATCH, $records['parent']['path'], $headers, [
            'data' => ['children' => $many ? [$records['child']['id']] : $records['child']['id']],
        ]);
        $this->assertSame(200, $linked['headers']['status-code']);

        return [...$records, 'headers' => $headers, 'databaseId' => $databaseId, 'base' => $base, 'attributes' => $attributes];
    }

    private function subscribeToRelationshipRecord(array $fixture, string $side): WebSocketClient
    {
        $record = $fixture[$side];
        $socket = $this->getWebsocket([
            'databases.' . $fixture['databaseId'] . '.collections.' . $record['collectionId'] . '.documents.' . $record['id'],
        ], ['origin' => 'http://localhost']);
        $connected = \json_decode($socket->receive(), true);
        $this->assertSame('connected', $connected['type']);
        return $socket;
    }

    private function assertRelationshipRemovalEvent(string $type, string $deleted, string $api = 'databases'): void
    {
        $fixture = $this->createRelationshipRecords($type, $api);
        $survivor = $deleted === 'child' ? 'parent' : 'child';
        $key = $survivor === 'parent' ? 'children' : 'parents';
        $socket = $this->subscribeToRelationshipRecord($fixture, $survivor);
        try {
            // Test for SUCCESS: deleting either side notifies the surviving related record.
            $response = $this->client->call(Client::METHOD_DELETE, $fixture[$deleted]['path'], $fixture['headers']);
            $this->assertSame(204, $response['headers']['status-code']);
            $event = $this->receiveUntilEvent($socket, fn (array $message): bool => ($message['data']['payload']['$id'] ?? null) === $fixture[$survivor]['id']);
            $this->assertContains(
                'databases.' . $fixture['databaseId'] . '.collections.' . $fixture[$survivor]['collectionId'] . '.documents.' . $fixture[$survivor]['id'] . '.update',
                $event['data']['events']
            );
            $this->assertSame($survivor, $event['data']['payload']['name']);
            $this->assertArrayNotHasKey($key, $event['data']['payload']);
            $this->assertSame($fixture['databaseId'], $event['data']['payload']['$databaseId']);
            $this->assertSame($fixture[$survivor]['collectionId'], $event['data']['payload'][$api === 'tablesdb' ? '$tableId' : '$collectionId']);

            $remaining = $this->client->call(Client::METHOD_GET, $fixture[$survivor]['path'], $fixture['headers']);
            $this->assertSame(200, $remaining['headers']['status-code']);
            $many = $survivor === 'parent'
                ? \in_array($type, ['oneToMany', 'manyToMany'])
                : \in_array($type, ['manyToOne', 'manyToMany']);
            $this->assertSame($many ? [] : null, $remaining['body'][$key]);
            $missing = $this->client->call(Client::METHOD_GET, $fixture[$deleted]['path'], $fixture['headers']);
            $this->assertSame(404, $missing['headers']['status-code']);
        } finally {
            $socket->close();
        }
    }

    public function testDeleteOneToManyChildRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('oneToMany', 'child');
    }

    public function testDeleteOneToManyParentRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('oneToMany', 'parent');
    }

    public function testDeleteOneToOneChildRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('oneToOne', 'child');
    }

    public function testDeleteOneToOneParentRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('oneToOne', 'parent');
    }

    public function testDeleteManyToOneChildRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('manyToOne', 'child');
    }

    public function testDeleteManyToOneParentRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('manyToOne', 'parent');
    }

    public function testDeleteManyToManyChildRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('manyToMany', 'child');
    }

    public function testDeleteManyToManyParentRealtime(): void
    {
        $this->assertRelationshipRemovalEvent('manyToMany', 'parent');
    }
}
