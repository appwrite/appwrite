<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Traits\DatabasesUrlHelpers;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

/**
 * Postgres string matching (startsWith, contains, endsWith) is ILIKE.
 * A trigram index is the index type that can serve it.
 */
trait TrigramIndex
{
    use DatabasesUrlHelpers;
    use SchemaPolling;

    public function testCreateTrigramIndex(): void
    {
        $supported = ($this->getConsoleVariables()['_APP_DB_ADAPTER'] ?? '') === 'postgresql';
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Trigram Index',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $container = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Rooms',
        ]);
        $this->assertEquals(201, $container['headers']['status-code']);
        $containerId = $container['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $containerId, 'string'), $headers, [
            'key' => 'name',
            'size' => 128,
            'required' => true,
        ]);
        $this->assertEquals(202, $name['headers']['status-code']);

        $year = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $containerId, 'integer'), $headers, [
            'key' => 'year',
            'required' => true,
            'min' => 0,
            'max' => 9999,
        ]);
        $this->assertEquals(202, $year['headers']['status-code']);

        $this->waitForAttribute($databaseId, $containerId, 'name');
        $this->waitForAttribute($databaseId, $containerId, 'year');

        $index = $this->client->call(Client::METHOD_POST, $this->getIndexUrl($databaseId, $containerId), $headers, [
            'key' => 'nameTrigram',
            'type' => 'trigram',
            $this->getIndexAttributesParam() => ['name'],
        ]);

        if ($supported) {
            $this->assertEquals(202, $index['headers']['status-code']);
            $this->assertEquals('nameTrigram', $index['body']['key']);
            $this->assertEquals('trigram', $index['body']['type']);
            $this->assertEquals('name', $index['body'][$this->getSchemaResource()][0]);
            $this->waitForIndex($databaseId, $containerId, 'nameTrigram');

            $row = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $headers, [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'name' => 'Zimmer 99',
                    'year' => 2024,
                ],
            ]);
            $this->assertEquals(201, $row['headers']['status-code']);

            $found = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId), $headers, [
                'queries' => [
                    Query::startsWith('name', 'Zimmer 99')->toString(),
                ],
            ]);
            $this->assertEquals(200, $found['headers']['status-code']);
            $this->assertCount(1, $found['body'][$this->getRecordResource()]);
            $this->assertEquals('Zimmer 99', $found['body'][$this->getRecordResource()][0]['name']);
        } else {
            $this->assertEquals(400, $index['headers']['status-code']);
            $this->assertEquals('Trigram indexes are not supported', $index['body']['message']);
        }

        $integerIndex = $this->client->call(Client::METHOD_POST, $this->getIndexUrl($databaseId, $containerId), $headers, [
            'key' => 'yearTrigram',
            'type' => 'trigram',
            $this->getIndexAttributesParam() => ['year'],
        ]);
        $this->assertEquals(400, $integerIndex['headers']['status-code']);
        $this->assertEquals(
            $supported
                ? 'Trigram index can only be created on string type attributes'
                : 'Trigram indexes are not supported',
            $integerIndex['body']['message']
        );

        if ($supported) {
            $ordered = $this->client->call(Client::METHOD_POST, $this->getIndexUrl($databaseId, $containerId), $headers, [
                'key' => 'nameTrigramOrdered',
                'type' => 'trigram',
                $this->getIndexAttributesParam() => ['name'],
                'orders' => ['ASC'],
            ]);
            $this->assertEquals(400, $ordered['headers']['status-code']);
            $this->assertEquals('Trigram indexes do not support orders or lengths', $ordered['body']['message']);
        }

        $schema = $this->getSchemaResource();
        $inline = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Notes',
            $schema => [
                ['key' => 'note', 'type' => 'string', 'size' => 128, 'required' => true],
            ],
            'indexes' => [
                ['key' => 'noteTrigram', 'type' => 'trigram', 'attributes' => ['note']],
            ],
        ]);

        if ($supported) {
            $this->assertEquals(201, $inline['headers']['status-code']);
            $this->assertEquals('noteTrigram', $inline['body']['indexes'][0]['key']);
            $this->assertEquals('trigram', $inline['body']['indexes'][0]['type']);
            $this->assertEquals('available', $inline['body']['indexes'][0]['status']);
        } else {
            $this->assertEquals(400, $inline['headers']['status-code']);
            $this->assertEquals('Trigram indexes are not supported', $inline['body']['message']);
        }
    }
}
