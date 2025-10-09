<?php

namespace Tests\E2E\Services\Databases\TablesDB\Permissions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesPermissionsTeamTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    public array $tables = [];
    public string $databaseId = 'testpermissiondb';

    public function createTeams(): array
    {
        return [
            'team1' => $this->createTeam('team1', 'Team 1'),
            'team2' => $this->createTeam('team2', 'Team 2'),
        ];
    }

    public function createUsers(): array
    {
        return [
            'user1' => $this->createUser('user1', 'lorem@ipsum.com'),
            'user2' => $this->createUser('user2', 'dolor@ipsum.com'),
            'user3' => $this->createUser('user3', 'sit@ipsum.com'),
        ];
    }

    public function createTables($teams)
    {
        $db = $this->client->call(Client::METHOD_POST, '/tablesdb', $this->getServerHeader(), [
            'databaseId' => $this->databaseId,
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);

        $table1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::custom('table1'),
            'name' => 'Table 1',
            'permissions' => [
                Permission::read(Role::team($teams['team1']['$id'])),
                Permission::create(Role::team($teams['team1']['$id'], 'admin')),
                Permission::update(Role::team($teams['team1']['$id'], 'admin')),
                Permission::delete(Role::team($teams['team1']['$id'], 'admin')),
            ],
        ]);

        $this->tables['table1'] = $table1['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables/' . $this->tables['table1'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $table2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::custom('table2'),
            'name' => 'Table 2',
            'permissions' => [
                Permission::read(Role::team($teams['team2']['$id'])),
                Permission::create(Role::team($teams['team2']['$id'], 'owner')),
                Permission::update(Role::team($teams['team2']['$id'], 'owner')),
                Permission::delete(Role::team($teams['team2']['$id'], 'owner')),
            ]
        ]);

        $this->tables['table2'] = $table2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables/' . $this->tables['table2'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return $this->tables;
    }

    /*
     * $success = can $user read from $table
     * [$user, $table, $success]
     */
    public function readRowsProvider(): array
    {
        return [
            ['user1', 'table1', true],
            ['user2', 'table1', false],
            ['user3', 'table1', true],
            ['user1', 'table2', false],
            ['user2', 'table2', true],
            ['user3', 'table2', true],
        ];
    }

    /*
     * $success = can $user write to $table
     * [$user, $table, $success]
     */
    public function writeRowsProvider(): array
    {
        return [
            ['user1', 'table1', true],
            ['user2', 'table1', false],
            ['user3', 'table1', false],
            ['user1', 'table2', false],
            ['user2', 'table2', true],
            ['user3', 'table2', false],
        ];
    }

    /**
     * Setup database
     *
     * Data providers lose object state
     * so explicitly pass $users to each iteration
     * @return array $users
     */
    public function testSetupDatabase(): array
    {
        $this->createUsers();
        $this->createTeams();

        $this->addToTeam('user1', 'team1', ['admin']);
        $this->addToTeam('user2', 'team2', ['owner']);

        // user3 in both teams but with no roles
        $this->addToTeam('user3', 'team1');
        $this->addToTeam('user3', 'team2');

        $this->createTables($this->teams);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables/' . $this->tables['table1'] . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables/' . $this->tables['table2'] . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Ipsum',
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        return $this->users;
    }

    /**
     * Data provider params are passed before test dependencies
     * @depends testSetupDatabase
     * @dataProvider readRowsProvider
     */
    public function testReadRows($user, $table, $success, $users)
    {
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $this->databaseId . '/tables/' . $table  . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ]);

        if ($success) {
            $this->assertCount(1, $rows['body']['rows']);
        } else {
            $this->assertEquals(401, $rows['headers']['status-code']);
        }
    }

    /**
     * @depends testSetupDatabase
     * @dataProvider writeRowsProvider
     */
    public function testWriteRows($user, $table, $success, $users)
    {
        $rows = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->databaseId . '/tables/' . $table  . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Ipsum',
            ],
        ]);

        if ($success) {
            $this->assertEquals(201, $rows['headers']['status-code']);
        } else {
            // 401 if user is a part of team, 404 otherwise
            $this->assertContains($rows['headers']['status-code'], [401, 404]);
        }
    }
}
