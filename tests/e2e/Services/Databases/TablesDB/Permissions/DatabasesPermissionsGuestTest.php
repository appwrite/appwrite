<?php

namespace Tests\E2E\Services\Databases\TablesDB\Permissions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

class DatabasesPermissionsGuestTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    private $authorization;

    public function getAuthorization(): Authorization
    {
        if (isset($this->authorization)) {
            return $this->authorization;
        }

        $this->authorization = new Authorization();
        return $this->authorization;
    }


    public function createTable(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'InvalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $publicMovies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $privateMovies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [],
            'rowSecurity' => true,
        ]);

        $publicTable = ['id' => $publicMovies['body']['$id']];
        $privateTable = ['id' => $privateMovies['body']['$id']];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $publicTable['id'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $privateTable['id'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return [
            'databaseId' => $databaseId,
            'publicTableId' => $publicTable['id'],
            'privateTableId' => $privateTable['id'],
        ];
    }

    public function permissionsProvider(): array
    {
        return [
            [[Permission::read(Role::any())]],
            [[Permission::read(Role::users())]],
            [[Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())]],
            [[Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())]],
        ];
    }

    /**
     * @dataProvider permissionsProvider
     */
    public function testReadRows($permissions)
    {
        $data = $this->createTable();
        $publicTableId = $data['publicTableId'];
        $privateTableId = $data['privateTableId'];
        $databaseId = $data['databaseId'];

        $publicResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $publicTableId . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions,
        ]);
        $privateResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions,
        ]);

        $this->assertEquals(201, $publicResponse['headers']['status-code']);
        $this->assertEquals(201, $privateResponse['headers']['status-code']);

        $roles = $this->getAuthorization()->getRoles();
        $this->getAuthorization()->cleanRoles();

        $publicRows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $publicTableId  . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);
        $privateRows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId  . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(1, $publicRows['body']['total']);
        $this->assertEquals($permissions, $publicRows['body']['rows'][0]['$permissions']);

        if (\in_array(Permission::read(Role::any()), $permissions)) {
            $this->assertEquals(1, $privateRows['body']['total']);
            $this->assertEquals($permissions, $privateRows['body']['rows'][0]['$permissions']);
        } else {
            $this->assertEquals(0, $privateRows['body']['total']);
        }

        foreach ($roles as $role) {
            $this->getAuthorization()->addRole($role);
        }
    }

    public function testWriteRow()
    {
        $data = $this->createTable();
        $publicTableId = $data['publicTableId'];
        $privateTableId = $data['privateTableId'];
        $databaseId = $data['databaseId'];

        $roles = $this->getAuthorization()->getRoles();
        $this->getAuthorization()->cleanRoles();

        $publicResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $publicTableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ]
        ]);

        $publicRowId = $publicResponse['body']['$id'];
        $this->assertEquals(201, $publicResponse['headers']['status-code']);

        $privateResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
        ]);

        $this->assertEquals(401, $privateResponse['headers']['status-code']);

        // Create a row in private table with API key so we can test that update and delete are also not allowed
        $privateResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
        ]);

        $this->assertEquals(201, $privateResponse['headers']['status-code']);
        $privateRowId = $privateResponse['body']['$id'];

        $publicRow = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $publicTableId . '/rows/' . $publicRowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(200, $publicRow['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $publicRow['body']['title']);

        $privateRow = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId . '/rows/' . $privateRowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(401, $privateRow['headers']['status-code']);

        $publicRow = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $publicTableId . '/rows/' . $publicRowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(204, $publicRow['headers']['status-code']);

        $privateRow = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $privateTableId . '/rows/' . $privateRowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $privateRow['headers']['status-code']);

        foreach ($roles as $role) {
            $this->getAuthorization()->addRole($role);
        }
    }

    public function testWriteRowWithPermissions()
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'GuestPermissionsWrite',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('GuestPermissionsWrite', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::create(Role::any()),
            ],
            'rowSecurity' => true
        ]);

        $moviesId = $movies['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(1);

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
    }
}
