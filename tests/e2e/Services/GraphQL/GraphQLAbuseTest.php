<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\App;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class GraphQLAbuseTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    public function testComplexQueryBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATABASE_STACK);
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

        $max = App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 50);

        $this->assertEquals('Max query complexity should be ' . $max . ' but got 57.', $response['body']['errors'][0]['message']);
    }

    public function testTooManyQueriesBlocked()
    {
        $projectId = $this->getProject()['$id'];
        $maxQueries = App::getEnv('_APP_GRAPHQL_MAX_QUERIES', 50);

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
}
