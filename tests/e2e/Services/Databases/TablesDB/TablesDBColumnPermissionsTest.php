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

/**
 * Column-scoped permissions at the API boundary.
 *
 * The database library can restrict a grant to a single column, but a table has to opt
 * in through columnSecurity and no endpoint exposes that flag yet. Until one does, a
 * column-scoped permission names a feature the table cannot have, and the API refuses
 * it rather than storing a restriction that would begin applying the day the flag is
 * exposed.
 *
 * These tests pin that boundary from both sides, so whoever wires the flag up finds
 * them and has to decide deliberately what the new behaviour is.
 */
final class TablesDBColumnPermissionsTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    /**
     * @return array{string, string}
     */
    private function table(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $this->headers(), [
            'databaseId' => ID::unique(),
            'name' => 'Column Permissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->headers(), [
            'tableId' => ID::unique(),
            'name' => 'Employees',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        foreach (['name', 'salary'] as $key) {
            $column = $this->client->call(
                Client::METHOD_POST,
                '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string',
                $this->headers(),
                ['key' => $key, 'size' => 64, 'required' => false]
            );
            $this->assertEquals(202, $column['headers']['status-code']);
        }

        \sleep(2);

        return [$databaseId, $tableId];
    }

    public function testCreateTableWithAColumnScopedPermissionIsRejected(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $this->headers(), [
            'databaseId' => ID::unique(),
            'name' => 'Column Permissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);

        $table = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $database['body']['$id'] . '/tables',
            $this->headers(),
            [
                'tableId' => ID::unique(),
                'name' => 'Employees',
                'permissions' => [Permission::read(Role::any(), 'salary')],
            ]
        );

        $this->assertEquals(400, $table['headers']['status-code']);

        // The reason matters as much as the status. A table's own permissions live on a
        // document in the databases collection, which has no column security, so a
        // column-scoped grant belongs on a row rather than on the table.
        $this->assertStringContainsString('scoped to a column', $table['body']['message']);
        $this->assertStringContainsString('on rows, not on the table itself', $table['body']['message']);
    }

    public function testCreateRowWithAColumnScopedPermissionIsRejected(): void
    {
        [$databaseId, $tableId] = $this->table();

        $row = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows',
            $this->headers(),
            [
                'rowId' => ID::unique(),
                'data' => ['name' => 'Bob', 'salary' => '100'],
                'permissions' => [Permission::read(Role::any(), 'salary')],
            ]
        );

        $this->assertEquals(400, $row['headers']['status-code']);
        // This table did not opt in, so the column half of the grant has no meaning here.
        $this->assertStringContainsString('column security is not enabled', $row['body']['message']);
    }

    /**
     * The rejection is specific to the column half. The same roles and actions without
     * one are stored and returned unchanged.
     */
    public function testUnscopedPermissionsAreUnaffected(): void
    {
        [$databaseId, $tableId] = $this->table();

        $row = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows',
            $this->headers(),
            [
                'rowId' => ID::unique(),
                'data' => ['name' => 'Bob', 'salary' => '100'],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::users()),
                ],
            ]
        );

        $this->assertEquals(201, $row['headers']['status-code']);

        $stored = $row['body']['$permissions'];
        \sort($stored);

        $this->assertSame([
            Permission::read(Role::any()),
            Permission::update(Role::users()),
        ], $stored);
    }

    /**
     * With columnSecurity on, a column-scoped permission is accepted and stored.
     *
     * The rejections above are what the API does when a table has not opted in. This is
     * the other half: the flag round-trips through create and the response model, and a
     * grant naming a column is no longer refused.
     */
    public function testColumnSecurityEnablesColumnScopedPermissions(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $this->headers(), [
            'databaseId' => ID::unique(),
            'name' => 'Column Permissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->headers(), [
            'tableId' => ID::unique(),
            'name' => 'Employees',
            'rowSecurity' => true,
            'columnSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertTrue($table['body']['columnSecurity'], 'the flag round-trips through the response model');
        $tableId = $table['body']['$id'];

        foreach (['name', 'salary'] as $key) {
            $column = $this->client->call(
                Client::METHOD_POST,
                '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string',
                $this->headers(),
                ['key' => $key, 'size' => 64, 'required' => false]
            );
            $this->assertEquals(202, $column['headers']['status-code']);
        }

        \sleep(2);

        $row = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows',
            $this->headers(),
            [
                'rowId' => ID::unique(),
                'data' => ['name' => 'Bob', 'salary' => '100'],
                'permissions' => [Permission::read(Role::users(), 'salary')],
            ]
        );

        $this->assertEquals(201, $row['headers']['status-code'], 'a column-scoped grant is accepted once the table opts in');
        $this->assertSame([Permission::read(Role::users(), 'salary')], $row['body']['$permissions']);

        // And it survives a read back in the caller's vocabulary -- storage keeps an
        // identity, callers only ever see the column key.
        $read = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'],
            $this->headers()
        );

        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertSame([Permission::read(Role::users(), 'salary')], $read['body']['$permissions']);
    }

    /**
     * The same refusal on the update path. Create rejects a column-scoped grant on a
     * table that never opted in, with a 400 explaining why; update has to do the same,
     * or the caller meets a failure from the write instead of an answer about their
     * input.
     */
    public function testUpdateRowWithAColumnScopedPermissionIsRejected(): void
    {
        [$databaseId, $tableId] = $this->table();

        $row = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows',
            $this->headers(),
            ['rowId' => ID::unique(), 'data' => ['name' => 'Bob', 'salary' => '100']]
        );
        $this->assertEquals(201, $row['headers']['status-code']);

        $updated = $this->client->call(
            Client::METHOD_PATCH,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'],
            $this->headers(),
            ['permissions' => [Permission::read(Role::any(), 'salary')]]
        );

        $this->assertEquals(400, $updated['headers']['status-code']);
        $this->assertStringContainsString('column security is not enabled', $updated['body']['message']);
    }
}
