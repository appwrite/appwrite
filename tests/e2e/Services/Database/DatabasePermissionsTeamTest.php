<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabasePermissionsTeamTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasePermissionsScope;

    public array $collections = [];

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
        $collection1 = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'collection1',
            'name' => 'Collection 1',
            'read' => ['team:' . $teams['team1']['$id']],
            'write' => ['team:' . $teams['team1']['$id'] . '/admin'],
            'permission' => 'collection',
        ]);

        $this->collections['collection1'] = $collection1['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['collection1'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $collection2 = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'collection2',
            'name' => 'Collection 2',
            'read' => ['team:' . $teams['team2']['$id']],
            'write' => ['team:' . $teams['team2']['$id'] . '/owner'],
            'permission' => 'collection',
        ]);

        $this->collections['collection2'] = $collection2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['collection2'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
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

        $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['collection1'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['collection2'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Ipsum',
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        return $this->users;
    }

    /**
     * @depends testSetupDatabase
     * @dataProvider readDocumentsProvider
     */
    public function testReadDocuments($user, $collection, $success, $users)
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ]);

        if ($success) {
            $this->assertCount(1, $documents['body']['documents']);
        } else {
            $this->assertEquals(404, $documents['headers']['status-code']);
        }

    }

    /**
     * @depends testSetupDatabase
     * @dataProvider writeDocumentsProvider
     */
    public function testWriteDocuments($user, $collection, $success, $users)
    {
        $documents = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collection  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users[$user]['session'],
        ], [
            'documentId' => 'unique()',
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
