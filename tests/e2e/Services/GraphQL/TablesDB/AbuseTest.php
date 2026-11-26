<?php

declare(strict_types=1);

namespace Tests\E2E\Services\GraphQL\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\GraphQL\Base;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\System\System;

final class AbuseTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    protected function setUp(): void
    {
        parent::setUp();

        if (System::getEnv('_APP_OPTIONS_ABUSE') === 'disabled') {
            $this->markTestSkipped('Abuse is not enabled.');
        }
    }

    public function testRateLimitEnforced()
    {
        $data = $this->createTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ROW);
        $max = 120;
        // Operations go in batches, so the route's per-operation limit is reached well inside the
        // GraphQL endpoint's own cap of 60 requests a minute
        $batchSize = 10;

        for ($sent = 0; $sent <= $max + 1; $sent += $batchSize) {
            $batch = [];
            for ($i = $sent; $i < \min($sent + $batchSize, $max + 2); $i++) {
                $batch[] = [
                    'query' => $query,
                    'variables' => [
                        'databaseId' => $databaseId,
                        'tableId' => $tableId,
                        'rowId' => Id::unique(),
                        'data' => [
                            'name' => 'John Doe',
                        ],
                    ],
                ];
            }

            $response = $this->client->call(Client::METHOD_POST, '/graphql', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $batch);

            $this->assertSame(200, $response['headers']['status-code'], 'Body: ' . \json_encode($response['body']));

            foreach ($batch as $offset => $_) {
                if ($sent + $offset < $max) {
                    $this->assertArrayNotHasKey('errors', $response['body'][$offset]);
                } else {
                    $this->assertArrayHasKey('errors', $response['body'][$offset]);
                }
            }
        }
    }

    public function testComplexQueryBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::COMPLEX_QUERY_TABLE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => 'user',
                'email' => 'user@appwrite.io',
                'password' => 'password',
                'databaseId' => 'database',
                'databaseName' => 'database',
                'tableId' => 'table',
                'tableName' => 'table',
                'tablePermissions' => [
                    Permission::read(Role::users()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
                'rowSecurity' => false,
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $max = System::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', '250');

        $this->assertEquals('Max query complexity should be ' . $max . ' but got 259.', $response['body']['errors'][0]['message']);
    }

    public function testTooManyQueriesBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $maxQueries = System::getEnv('_APP_GRAPHQL_MAX_QUERIES', '10');

        $query = [];
        for ($i = 0; $i <= ((int) $maxQueries) + 1; $i++) {
            $query[] = ['query' => $this->getQuery(self::LIST_COUNTRIES)];
        }

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $query);

        $this->assertEquals('Too many queries.', $response['body']['message']);
    }

    private function createTable(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DATABASE);
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

        $query = $this->getQuery(self::CREATE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'tableId' => 'actors',
                'name' => 'Actors',
                'rowSecurity' => false,
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

        $tableId = $response['body']['data']['tablesDBCreateTable']['_id'];

        $query = $this->getQuery(self::CREATE_STRING_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'tableId' => $tableId,
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

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        return [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];
    }
}
