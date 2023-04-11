<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class ScopeTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testInvalidScope()
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getNewKey(['databases.read']);
        $query = $this->getQuery(self::$CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
                'name' => 'Actors',
            ],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], $gqlPayload);

        $message = "app.${projectId}@service.localhost (role: applications) missing scope (databases.write)";
        $this->assertArrayHasKey('errors', $database['body']);
        $this->assertEquals($message, $database['body']['errors'][0]['message']);
    }

    public function testValidScope()
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getNewKey(['databases.read', 'databases.write']);
        $query = $this->getQuery(self::$CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
                'name' => 'Actors',
            ],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        $database = $database['body']['data']['databasesCreate'];
        $this->assertEquals('Actors', $database['name']);
    }
}
