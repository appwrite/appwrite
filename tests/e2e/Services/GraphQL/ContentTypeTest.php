<?php

declare(strict_types=1);

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

final class ContentTypeTest extends Scope
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
        $this->assertEquals(197, $response['total']);
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
        $this->assertEquals(197, $response['total']);
    }

    #[DataProvider('postRoutes')]
    public function testPostSDKQuery(string $path): void
    {
        /**
         * Test for SUCCESS
         */
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-graphql' => 'true',
        ], $this->getHeaders());
        $countries = ['query' => 'query { localeListCountries { total } }'];
        $continents = ['query' => 'query { localeListContinents { total } }'];

        $single = $this->client->call(Client::METHOD_POST, $path, $headers, ['query' => $countries]);

        $this->assertSame(200, $single['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $single['body']);
        $this->assertIsInt($single['body']['data']['localeListCountries']['total']);
        $this->assertGreaterThan(0, $single['body']['data']['localeListCountries']['total']);

        $batch = $this->client->call(Client::METHOD_POST, $path, $headers, ['query' => [$countries, $continents]]);

        $this->assertSame(200, $batch['headers']['status-code']);
        $this->assertCount(2, $batch['body']);
        $this->assertArrayNotHasKey('errors', $batch['body'][0]);
        $this->assertArrayNotHasKey('errors', $batch['body'][1]);
        $this->assertIsInt($batch['body'][0]['data']['localeListCountries']['total']);
        $this->assertGreaterThan(0, $batch['body'][0]['data']['localeListCountries']['total']);
        $this->assertIsInt($batch['body'][1]['data']['localeListContinents']['total']);
        $this->assertGreaterThan(0, $batch['body'][1]['data']['localeListContinents']['total']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function postRoutes(): \Iterator
    {
        yield 'query' => ['/graphql'];
        yield 'mutation' => ['/graphql/mutation'];
    }

    #[DataProvider('invalidSDKQueries')]
    public function testPostInvalidSDKQuery(string $path, array $payload, string $type): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, $path, \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-graphql' => 'true',
        ], $this->getHeaders()), $payload);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame($type, $response['body']['type']);
        $this->assertNotEmpty($response['body']['message']);
        $this->assertArrayNotHasKey('data', $response['body']);
    }

    /**
     * @return \Iterator<string, array{string, array, string}>
     */
    public static function invalidSDKQueries(): \Iterator
    {
        foreach (self::postRoutes() as $route => [$path]) {
            foreach (['string' => '{ localeListCountries { total } }', 'number' => 42, 'boolean' => true] as $name => $query) {
                yield $route . ' ' . $name => [$path, ['query' => $query], 'general_argument_invalid'];
            }

            yield $route . ' missing' => [$path, [], 'graphql_no_query'];
            yield $route . ' null' => [$path, ['query' => null], 'graphql_no_query'];
            yield $route . ' empty' => [$path, ['query' => []], 'graphql_no_query'];
            yield $route . ' object' => [$path, ['query' => new \stdClass()], 'graphql_no_query'];
        }
    }

    public function testJSONObjectVariables()
    {
        $projectId = $this->getProject()['$id'];
        $query = '{ localeGet { ip country continent currency } }';
        $graphQLPayload = [
            'query' => $query,
            'variables' => new \stdClass(),
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['data']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('localeGet', $response['body']['data']);
        $this->assertIsArray($response['body']['data']['localeGet']);
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

        $this->assertIsArray($response['body'][0]['data']);
        $this->assertIsArray($response['body'][1]['data']);
        $this->assertArrayNotHasKey('errors', $response['body'][0]);
        $this->assertArrayNotHasKey('errors', $response['body'][1]);
        $this->assertArrayHasKey('localeListCountries', $response['body'][0]['data']);
        $this->assertArrayHasKey('localeListContinents', $response['body'][1]['data']);
        $this->assertEquals(197, $response['body'][0]['data']['localeListCountries']['total']);
        $this->assertEquals(7, $response['body'][1]['data']['localeListContinents']['total']);
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
        $this->assertEquals(197, $response['body']['data']['localeListCountries']['total']);
        $this->assertEquals(7, $response['body']['data']['localeListContinents']['total']);
    }

    public function testMultipartFormDataContentType()
    {
        $projectId = $this->getProject()['$id'];

        $query = $this->getQuery(self::CREATE_BUCKET);
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

        $query = $this->getQuery(self::CREATE_FILE);
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

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }

    public function testPostEmptyBody()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), []);

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }

    public function testPostRandomBody()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), ['foo' => 'bar']);

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }

    public function testGetNoQuery()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }

    public function testGetEmptyQuery()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/graphql?query=', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }

    public function testGetRandomParameters()
    {
        $projectId = $this->getProject()['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/graphql?random=random', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals('Param "query" is not optional.', $response['body']['message']);
    }
}
