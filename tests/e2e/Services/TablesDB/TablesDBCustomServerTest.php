<?php

declare(strict_types=1);

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Databases\DatabasesBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

final class TablesDBCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;

    public function testUpdateRowsUniqueConflict(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, $this->getDatabaseUrl(), $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Update Conflict',
        ]);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
            'tableId' => ID::unique(),
            'name' => 'Bulk Update Conflict',
        ]);
        $tableId = $table['body']['$id'];

        $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $tableId, 'integer'), $headers, [
            'key' => 'slot',
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $tableId, 'string'), $headers, [
            'key' => 'status',
            'size' => 16,
            'required' => true,
        ]);
        $this->waitForAllAttributes($databaseId, $tableId);

        $this->client->call(Client::METHOD_POST, $this->getIndexUrl($databaseId, $tableId), $headers, [
            'key' => 'slot_status',
            'type' => 'unique',
            'columns' => ['slot', 'status'],
        ]);
        $this->waitForIndex($databaseId, $tableId, 'slot_status');

        // More drafts than one batch of the bulk update, with the conflicting row in the second batch
        foreach (\array_chunk(\range(0, 1099), 100) as $slots) {
            $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $tableId), $headers, [
                'rows' => \array_map(fn (int $slot) => ['slot' => $slot, 'status' => 'draft'], $slots),
            ]);
        }
        $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $tableId), $headers, [
            'rowId' => ID::unique(),
            'data' => ['slot' => 1050, 'status' => 'published'],
        ]);

        $update = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $tableId), $headers, [
            'data' => ['status' => 'published'],
            'queries' => [Query::equal('status', ['draft'])->toString()],
        ]);

        $this->assertSame(409, $update['headers']['status-code']);

        $drafts = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $tableId), $headers, [
            'queries' => [
                Query::equal('status', ['draft'])->toString(),
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertSame(1100, $drafts['body']['total']);
    }
}
