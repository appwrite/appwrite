<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\App;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class AbuseTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    protected function setUp(): void
    {
        parent::setUp();

        if (App::getEnv('_APP_OPTIONS_ABUSE') === 'disabled') {
            $this->markTestSkipped('Abuse is not enabled.');
        }
    }

    public function testRateLimitEnforced()
    {
        $data = $this->createCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $max = 120;

        for ($i = 0; $i <= $max + 1; $i++) {
            $gqlPayload = [
                'query' => $query,
                'variables' => [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => ID::unique(),
                    'data' => [
                        'name' => 'John Doe',
                    ],
                ],
            ];

            $response = $this->client->call(Client::METHOD_POST, '/graphql', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $gqlPayload);

            if ($i < $max) {
                $this->assertArrayNotHasKey('errors', $response['body']);
            } else {
                $this->assertArrayHasKey('errors', $response['body']);
            }
        }
    }

    public function testComplexQueryBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$COMPLEX_QUERY);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => 'user',
                'email' => 'user@appwrite.io',
                'password' => 'password',
                'databaseId' => 'database',
                'databaseName' => 'database',
                'collectionId' => 'collection',
                'collectionName' => 'collection',
                'collectionPermissions' => [
                    Permission::read(Role::users()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
                'documentSecurity' => false,
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $max = App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 250);

        $this->assertEquals('Max query complexity should be ' . $max . ' but got 259.', $response['body']['errors'][0]['message']);
    }

    public function testTooManyQueriesBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $maxQueries = App::getEnv('_APP_GRAPHQL_MAX_QUERIES', 10);

        $query = [];
        for ($i = 0; $i <= $maxQueries + 1; $i++) {
            $query[] = ['query' => $this->getQuery(self::$LIST_COUNTRIES)];
        }

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $query);

        $this->assertEquals('Too many queries.', $response['body']['message']);
    }

    private function createCollection()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'actors',
                'name' => 'AbuseDatabase',
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $databaseId = $response['body']['data']['databasesCreate']['_id'];

        $query = $this->getQuery(self::$CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => 'actors',
                'name' => 'Actors',
                'documentSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::write(Role::any()),
                ],
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $collectionId = $response['body']['data']['databasesCreateCollection']['_id'];

        $query = $this->getQuery(self::$CREATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        sleep(2);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];
    }
}
