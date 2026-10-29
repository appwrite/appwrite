<?php

declare(strict_types=1);

namespace Tests\E2E\Services\GraphQL;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;

final class QueriesValidationTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testListQueriesVariableAsSingleString(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = 'query listUsers($queries: [String!]) {
            usersList(queries: $queries) {
                total
            }
        }';

        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'queries' => Query::limit(1)->toString(),
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertNotEquals(500, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertIsInt($response['body']['data']['usersList']['total']);
    }

    public function testListQueriesInlineStringCoercion(): void
    {
        $projectId = $this->getProject()['$id'];
        $limitQuery = Query::limit(1)->toString();
        $graphQLPayload = [
            'query' => 'query {
                usersList(queries: ' . \json_encode($limitQuery, JSON_THROW_ON_ERROR) . ') {
                    total
                }
            }',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertNotEquals(500, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertIsInt($response['body']['data']['usersList']['total']);
    }

    public function testMalformedListQueryRejectedLikeRest(): void
    {
        $projectId = $this->getProject()['$id'];
        $malformed = '{"method":"bogus","attribute":"name","values":["x"]}';

        $rest = $this->client->call(Client::METHOD_GET, '/users', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'queries' => [$malformed],
        ]);

        $this->assertSame(400, $rest['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $rest['body']['type']);
        $this->assertStringContainsString('Invalid query method: bogus', (string) $rest['body']['message']);

        $graphQLPayload = [
            'query' => $this->getQuery(self::GET_USERS),
            'variables' => [
                'queries' => [$malformed],
            ],
        ];

        $graphql = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertNotEquals(500, $graphql['headers']['status-code']);
        $this->assertSame(200, $graphql['headers']['status-code']);
        $this->assertArrayHasKey('errors', $graphql['body']);
        $this->assertSame($rest['body']['message'], $graphql['body']['errors'][0]['message']);
    }
}
