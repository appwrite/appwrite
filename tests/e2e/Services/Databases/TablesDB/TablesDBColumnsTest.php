<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

final class TablesDBColumnsTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * Every column type the dedicated endpoints expose must also be creatable
     * inline on create table.
     */
    public function testCreateTableColumns(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Inline Columns',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'Modules',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
            'columns' => [
                ['key' => 'title', 'type' => 'string', 'size' => 128, 'required' => true],
                ['key' => 'slug', 'type' => 'varchar', 'size' => 64],
                ['key' => 'modulePath', 'type' => 'text'],
                ['key' => 'summary', 'type' => 'mediumtext'],
                ['key' => 'archive', 'type' => 'longtext'],
                ['key' => 'email', 'type' => 'email'],
                ['key' => 'website', 'type' => 'url'],
                ['key' => 'address', 'type' => 'ip'],
                ['key' => 'status', 'type' => 'enum', 'elements' => ['on', 'off'], 'default' => 'on'],
            ],
            'indexes' => [
                ['key' => 'slug_unique', 'type' => 'unique', 'attributes' => ['slug']],
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertCount(9, $table['body']['columns']);
        $columnKeys = array_column($table['body']['columns'], 'key');
        sort($columnKeys);
        $this->assertEquals(
            ['address', 'archive', 'email', 'modulePath', 'slug', 'status', 'summary', 'title', 'website'],
            $columnKeys,
        );
        $this->assertCount(1, $table['body']['indexes']);
        $this->assertEquals('slug_unique', $table['body']['indexes'][0]['key']);
        $this->assertEquals('available', $table['body']['indexes'][0]['status']);
        $tableId = $table['body']['$id'];

        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns', $headers);

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertEquals(9, $columns['body']['total']);

        $byKey = [];
        foreach ($columns['body']['columns'] as $column) {
            $this->assertEquals('available', $column['status']);
            $byKey[$column['key']] = $column;
        }

        // Sizes must match the dedicated per-column endpoints. The fixed width
        // types do not expose a size, it is implied by the type.
        $this->assertEquals('string', $byKey['title']['type']);
        $this->assertEquals(128, $byKey['title']['size']);
        $this->assertEquals('varchar', $byKey['slug']['type']);
        $this->assertEquals(64, $byKey['slug']['size']);
        $this->assertEquals('text', $byKey['modulePath']['type']);
        $this->assertEquals('mediumtext', $byKey['summary']['type']);
        $this->assertEquals('longtext', $byKey['archive']['type']);

        // Format shorthands become a string of that format
        $this->assertEquals('string', $byKey['email']['type']);
        $this->assertEquals('email', $byKey['email']['format']);
        $this->assertEquals('string', $byKey['website']['type']);
        $this->assertEquals('url', $byKey['website']['format']);
        $this->assertEquals('string', $byKey['address']['type']);
        $this->assertEquals('ip', $byKey['address']['format']);
        $this->assertEquals('string', $byKey['status']['type']);
        $this->assertEquals('enum', $byKey['status']['format']);
        $this->assertEquals(['on', 'off'], $byKey['status']['elements']);
        $this->assertEquals('on', $byKey['status']['default']);

        // Longer than any of the sized string types allow, so it only fits if
        // the text column really got its 65535 default
        $modulePath = \str_repeat('src/Appwrite/Platform/Modules/Databases/', 200);

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $headers, [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Appwrite',
                'modulePath' => $modulePath,
                'email' => 'team@appwrite.io',
                'website' => 'https://appwrite.io',
                'address' => '127.0.0.1',
                'status' => 'off',
            ],
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals($modulePath, $row['body']['modulePath']);
        $this->assertEquals('off', $row['body']['status']);
    }

    public function testCreateTableUnsupportedColumnType(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Inline Columns Invalid',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'Invalid',
            'columns' => [
                ['key' => 'unknown', 'type' => 'blob'],
            ],
        ]);

        $this->assertEquals(400, $table['headers']['status-code']);
        $this->assertStringContainsString("Invalid type for attribute 'unknown': blob", (string) $table['body']['message']);
    }
}
