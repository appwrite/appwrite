<?php

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class ContentTypeTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testGraphQLContentType()
    {
        $projectId = $this->getProject()['$id'];
        $query = 'query { localeListCountries { total countries { code } } }';
        $graphQLPayload = [$query]; // Needs to be an array because the test client expects it
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/graphql',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $response = $response['body']['data']['localeListCountries'];
        $this->assertEquals(194, $response['total']);
    }

    public function testSingleQueryJSONContentType()
    {
        $projectId = $this->getProject()['$id'];
        $query = 'query { localeListCountries { total countries { code } } }';
        $graphQLPayload = ['query' => $query];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $response = $response['body']['data']['localeListCountries'];
        $this->assertEquals(194, $response['total']);
    }

    public function testArrayBatchedJSONContentType()
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

    public function testQueryBatchedJSONContentType()
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

    public function testMultipartFormDataContentType()
    {
        $projectId = $this->getProject()['$id'];

        $query = $this->getQuery(self::$CREATE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => ID::unique(),
                'name' => 'Test Bucket',
                'fileSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ];
        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $bucket = $bucket['body']['data']['storageCreateBucket'];

        $query = $this->getQuery(self::$CREATE_FILE);
        $gqlPayload = [
            'operations' => \json_encode([
                'query' => $query,
                'variables' => [
                    'bucketId' => $bucket['_id'],
                    'fileId' => ID::unique(),
                    'file' => null,
                    'fileSecurity' => true,
                    'permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ]
            ]),
            'map' => \json_encode([
                'file' => ["variables.file"]
            ]),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $this->assertIsArray($file['body']['data']['storageCreateFile']);
    }

    public function testPostNoBody()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('No query passed in the request.', $response['body']['message']);
    }

    public function testPostEmptyBody()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), []);

        $this->assertEquals('No query passed in the request.', $response['body']['message']);
    }

    public function testPostRandomBody()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), ['foo' => 'bar']);

        $this->assertEquals('Invalid query.', $response['body']['message']);
    }

    public function testGetNoQuery()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('No query passed in the request.', $response['body']['message']);
    }

    public function testGetEmptyQuery()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/graphql?query=', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('Invalid query.', $response['body']['message']);
    }

    public function testGetRandomParameters()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql?random=random', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('No query passed in the request.', $response['body']['message']);
    }
}
