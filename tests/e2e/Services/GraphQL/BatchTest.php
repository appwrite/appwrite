<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\ID;

class BatchTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testArrayBatchedQueries()
    {
        $projectId = $this->getProject()['$id'];
        $query1 = 'query { localeListCountries { total countries { code } } }';
        $query2 = 'query { localeListContinents { total continents { code } } }';
        $graphQLPayload = [
            ['query' => $query1],
            ['query' => $query2],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertArrayHasKey('localeListContinents', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total']);
        $this->assertEquals(7, $response['body']['data']['localeListContinents']['total']);
    }

    public function testArrayBatchedQueriesOfSameType()
    {
        $projectId = $this->getProject()['$id'];
        $query = 'query { localeListCountries { total countries { code } } }';
        $graphQLPayload = [
            ['query' => $query],
            ['query' => $query],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total'][0]);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total'][1]);
        $this->assertEquals(388, \count($response['body']['data']['localeListCountries']['countries']));
    }

    public function testArrayBatchedMutations()
    {
        $projectId = $this->getProject()['$id'];
        $email = 'tester' . \uniqid() . '@example.com';
        $graphQLPayload = [[
            'query' => 'mutation CreateAccount($userId: String!, $email: String!, $password: String!, $name: String) {
                accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
                    name
                }
            }',
            'variables' => [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => 'password',
                'name' => 'Tester 1',
            ],
        ],
        [
            'query' => 'mutation CreateTeam($teamId: String! $name: String!) {
                teamsCreate(teamId: $teamId, name: $name) {
                    name
                }
            }',
            'variables' => [
                'teamId' => ID::unique(),
                'name' => 'Team 1',
            ],
        ]];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('accountCreate', $response['body']['data']);
        $this->assertArrayHasKey('teamsCreate', $response['body']['data']);
        $this->assertEquals('Tester 1', $response['body']['data']['accountCreate']['name']);
        $this->assertEquals('Team 1', $response['body']['data']['teamsCreate']['name']);
    }

    public function testArrayBatchedMutationsOfSameType()
    {
        $projectId = $this->getProject()['$id'];
        $email1 = 'tester' . \uniqid() . '@example.com';
        $email2 = 'tester' . \uniqid() . '@example.com';
        $query = 'mutation CreateAccount($userId: String!, $email: String!, $password: String!, $name: String) {
            accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
                _id
            }
        }';

        $graphQLPayload = [
            [
                'query' => $query,
                'variables' => [
                    'userId' => ID::unique(),
                    'email' => $email1,
                    'password' => 'password',
                    'name' => 'Tester 1',
                ],
            ],
            [
                'query' => $query,
                'variables' => [
                    'userId' => ID::unique(),
                    'email' => $email2,
                    'password' => 'password',
                    'name' => 'Tester 2',
                ],
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('accountCreate', $response['body']['data']);
        $this->assertEquals(2, \count($response['body']['data']['accountCreate']['_id']));
    }

    public function testArrayBatchedMixed()
    {
        $projectId = $this->getProject()['$id'];
        $email = 'tester' . \uniqid() . '@example.com';
        $graphQLPayload = [
            ['query' => 'query { localeListCountries { total countries { code } } }'],
            ['query' => 'query { localeListContinents { total continents { code } } }'],
            [
                'query' => 'mutation CreateAccount($userId: String!, $email: String!, $password: String!, $name: String) {
                    accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
                        name
                    }
                }',
                'variables' => [
                    'userId' => ID::unique(),
                    'email' => $email,
                    'password' => 'password',
                    'name' => 'Tester 1',
                ]
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertArrayHasKey('localeListContinents', $response['body']['data']);
        $this->assertArrayHasKey('accountCreate', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total']);
        $this->assertEquals(7, $response['body']['data']['localeListContinents']['total']);
        $this->assertEquals('Tester 1', $response['body']['data']['accountCreate']['name']);
    }

    public function testArrayBatchedMixedOfSameType()
    {
        $projectId = $this->getProject()['$id'];
        $email = 'tester' . \uniqid() . '@example.com';
        $query = 'query { localeListCountries { total countries { code } } }';
        $graphQLPayload = [
            ['query' => $query],
            ['query' => $query],
            [
                'query' => 'mutation CreateAccount($userId: String!, $email: String!, $password: String!, $name: String) {
                    accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
                        _id
                    }
                }',
                'variables' => [
                    'userId' => ID::unique(),
                    'email' => $email,
                    'password' => 'password',
                    'name' => 'Tester 1',
                ]
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertArrayHasKey('accountCreate', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total'][0]);
        $this->assertEquals(388, \count($response['body']['data']['localeListCountries']['countries']));
        $this->assertArrayHasKey('_id', $response['body']['data']['accountCreate']);
    }

    public function testQueryBatchedQueries()
    {
        $projectId = $this->getProject()['$id'];
        $query = '
            query {
                localeListCountries { total countries { code } }
                localeListContinents { total continents { code } }
            }
        ';
        $graphQLPayload = [
            ['query' => $query],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertArrayHasKey('localeListContinents', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total']);
        $this->assertEquals(7, $response['body']['data']['localeListContinents']['total']);
    }

    public function testQueryBatchedQueriesOfSameType()
    {
        $projectId = $this->getProject()['$id'];
        $query = '
            query {
                localeListCountries { total countries { code } }
                localeListCountries { total countries { code } }
            }
        ';
        $graphQLPayload = [
            ['query' => $query],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeListCountries', $response['body']['data']);
        $this->assertEquals(194, $response['body']['data']['localeListCountries']['total']);
    }

    public function testQueryBatchedMutations()
    {
        $projectId = $this->getProject()['$id'];
        $email = 'tester' . \uniqid() . '@example.com';
        $graphQLPayload = [
            'query' => 'mutation CreateAndLogin($userId: String!, $email: String!, $password: String!, $name: String) {
                accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
                    name
                }
                accountCreateEmailSession(email: $email, password: $password) {
                    expire
                }
            }',
            'variables' => [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => 'password',
                'name' => 'Tester',
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);


        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('accountCreate', $response['body']['data']);
        $this->assertArrayHasKey('accountCreateEmailSession', $response['body']['data']);
        $this->assertEquals('Tester', $response['body']['data']['accountCreate']['name']);
    }

    public function testQueryBatchedMutationsOfSameType()
    {
        $projectId = $this->getProject()['$id'];
        $email1 = 'tester' . \uniqid() . '@example.com';
        $email2 = 'tester' . \uniqid() . '@example.com';
        $graphQLPayload = [
            'query' => 'mutation CreateAndLogin($email1: String!, $email2: String!, $password: String!, $name1: String, $name2: String) {
                accountCreate(userId: "unique()", email: $email1, password: $password, name: $name1) {
                    name
                }
                 accountCreate(userId: "unique()", email: $email2, password: $password, name: $name2) {
                    name
                }
            }',
            'variables' => [
                'userId' => ID::unique(),
                'email1' => $email1,
                'email2' => $email2,
                'name1' => 'Tester 1',
                'name2' => 'Tester 2',
                'password' => 'password',
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals('Fields "accountCreate" conflict because they have differing arguments. Use different aliases on the fields to fetch both if this was intentional.', $response['body']['errors'][0]['message']);
    }

    public function testQueryBatchedMutationsOfSameTypeWithAlias()
    {
        $projectId = $this->getProject()['$id'];
        $email1 = 'tester' . \uniqid() . '@example.com';
        $email2 = 'tester' . \uniqid() . '@example.com';
        $graphQLPayload = [
            'query' => 'mutation CreateAndLogin($email1: String!, $email2: String!, $password: String!, $name1: String, $name2: String) {
                account1: accountCreate(userId: "unique()", email: $email1, password: $password, name: $name1) {
                    name
                }
                 account2: accountCreate(userId: "unique()", email: $email2, password: $password, name: $name2) {
                    name
                }
            }',
            'variables' => [
                'userId' => ID::unique(),
                'email1' => $email1,
                'email2' => $email2,
                'name1' => 'Tester 1',
                'name2' => 'Tester 2',
                'password' => 'password',
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('account1', $response['body']['data']);
        $this->assertArrayHasKey('account2', $response['body']['data']);
        $this->assertEquals('Tester 1', $response['body']['data']['account1']['name']);
        $this->assertEquals('Tester 2', $response['body']['data']['account2']['name']);
    }
}
