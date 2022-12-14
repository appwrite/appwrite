<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class DatabasesPermissionsTeamTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    public array $collections = [];
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

    public function createCollections($teams)
    {
        $db = $this->client->call(Client::METHOD_POST, '/databases', $this->getServerHeader(), [
            'databaseId' => $this->databaseId,
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);

        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::custom('collection1'),
            'name' => 'Collection 1',
            'permissions' => [
                Permission::read(Role::team($teams['team1']['$id'])),
                Permission::create(Role::team($teams['team1']['$id'], 'admin')),
                Permission::update(Role::team($teams['team1']['$id'], 'admin')),
                Permission::delete(Role::team($teams['team1']['$id'], 'admin')),
            ],
        ]);

        $this->collections['collection1'] = $collection1['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections/' . $this->collections['collection1'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::custom('collection2'),
            'name' => 'Collection 2',
            'permissions' => [
                Permission::read(Role::team($teams['team2']['$id'])),
                Permission::create(Role::team($teams['team2']['$id'], 'owner')),
                Permission::update(Role::team($teams['team2']['$id'], 'owner')),
                Permission::delete(Role::team($teams['team2']['$id'], 'owner')),
            ]
        ]);

        $this->collections['collection2'] = $collection2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections/' . $this->collections['collection2'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return $this->collections;
    }

    /*
     * $success = can $user read from $collection
     * [$user, $collection, $success]
     */
    public function readDocumentsProvider(): array
    {
        return [
            ['user1', 'collection1', true],
            ['user2', 'collection1', false],
            ['user3', 'collection1', true],
            ['user1', 'collection2', false],
            ['user2', 'collection2', true],
            ['user3', 'collection2', true],
        ];
    }

    /*
     * $success = can $user write to $collection
     * [$user, $collection, $success]
     */
    public function writeDocumentsProvider(): array
    {
        return [
            ['user1', 'collection1', true],
            ['user2', 'collection1', false],
            ['user3', 'collection1', false],
            ['user1', 'collection2', false],
            ['user2', 'collection2', true],
            ['user3', 'collection2', false],
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

        $this->createCollections($this->teams);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections/' . $this->collections['collection1'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections/' . $this->collections['collection2'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
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
     * @dataProvider readDocumentsProvider
     */
    public function testReadDocuments($user, $collection, $success, $users)
    {
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $this->databaseId . '/collections/' . $collection  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ]);

        if ($success) {
            $this->assertCount(1, $documents['body']['documents']);
        } else {
            $this->assertEquals(401, $documents['headers']['status-code']);
        }
    }

    /**
     * @depends testSetupDatabase
     * @dataProvider writeDocumentsProvider
     */
    public function testWriteDocuments($user, $collection, $success, $users)
    {
        $documents = $this->client->call(Client::METHOD_POST, '/databases/' . $this->databaseId . '/collections/' . $collection  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Ipsum',
            ],
        ]);

        if ($success) {
            $this->assertEquals(201, $documents['headers']['status-code']);
        } else {
            // 401 if user is a part of team, 404 otherwise
            $this->assertContains($documents['headers']['status-code'], [401, 404]);
        }
    }
}
