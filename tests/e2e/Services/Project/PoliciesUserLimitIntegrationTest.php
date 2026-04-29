<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesUserLimitIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testUserLimitIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $signupHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $signup = function () use ($signupHeaders): array {
            return $this->client->call(Client::METHOD_POST, '/account', $signupHeaders, [
                'userId' => ID::unique(),
                'email' => 'limit_' . uniqid() . '@localhost.test',
                'password' => 'password1234',
                'name' => 'Limit User',
            ]);
        };

        // Step 1: Set user limit to 3
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $serverHeaders, [
            'total' => 3,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(3, $response['body']['authLimit']);

        // Create 3 users - all should succeed
        for ($i = 1; $i <= 3; $i++) {
            $response = $signup();
            $this->assertSame(201, $response['headers']['status-code'], 'User ' . $i . ' should be created under limit of 3');
        }

        // User 4 should be blocked
        $response = $signup();
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('user_count_exceeded', $response['body']['type']);

        // Step 2: Raise user limit to 4
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $serverHeaders, [
            'total' => 4,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(4, $response['body']['authLimit']);

        // User 4 now succeeds
        $response = $signup();
        $this->assertSame(201, $response['headers']['status-code']);

        // User 5 should be blocked
        $response = $signup();
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('user_count_exceeded', $response['body']['type']);

        // Step 3: Remove user limit (null -> stored as 0 -> unlimited)
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $serverHeaders, [
            'total' => null,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['authLimit']);

        // User 5 now succeeds
        $response = $signup();
        $this->assertSame(201, $response['headers']['status-code']);
    }
}
