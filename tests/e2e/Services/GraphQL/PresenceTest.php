<?php

declare(strict_types=1);

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PresenceTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testUpsertPresenceSourceIsGraphql(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getNewKey(['presences.write']);
        $user = $this->getUser(true);

        $payload = [
            'query' => <<<'GQL'
                mutation upsert($presenceId: String!, $userId: String!, $status: String!, $metadata: Json) {
                    presencesUpsert(presenceId: $presenceId, userId: $userId, status: $status, metadata: $metadata) {
                        _id
                        userId
                        status
                        source
                    }
                }
                GQL,
            'variables' => [
                'presenceId' => ID::unique(),
                'userId' => $user['$id'],
                'status' => 'online',
                'metadata' => [
                    'testRunId' => ID::unique(),
                ],
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], $payload);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('online', $response['body']['data']['presencesUpsert']['status']);
        $this->assertEquals('graphql', $response['body']['data']['presencesUpsert']['source']);
    }
}
