<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesSessionInvalidationIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testSessionInvalidationIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
            'x-appwrite-response-format' => '1.9.4',
        ];

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $setInvalidation = function (bool $enabled) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-invalidation', $serverHeaders, [
                'enabled' => $enabled,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($enabled, $response['body']['authInvalidateSessions']);
        };

        $accountHeaders = function (string $sessionCookie) use ($projectId): array {
            return [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $sessionCookie,
            ];
        };

        $getAccount = function (string $sessionCookie) use ($accountHeaders): array {
            return $this->client->call(Client::METHOD_GET, '/account', $accountHeaders($sessionCookie));
        };

        // Step 1: Disable session invalidation
        $setInvalidation(false);

        // Step 2: Create user and two sessions
        $email = 'invalidation_' . uniqid() . '@localhost.test';
        $firstPassword = 'firstpassword';

        $user = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $firstPassword,
            'name' => 'Invalidation User',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        $login = function (string $password) use ($publicHeaders, $email, $projectId): string {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
            return $response['cookies']['a_session_' . $projectId];
        };

        $session1 = $login($firstPassword);
        $session2 = $login($firstPassword);

        $this->assertSame(200, $getAccount($session1)['headers']['status-code']);
        $this->assertSame(200, $getAccount($session2)['headers']['status-code']);

        // Step 3: Change password while invalidation is disabled - both sessions survive
        $secondPassword = 'secondpassword';
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $serverHeaders, [
            'password' => $secondPassword,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $this->assertEventually(function () use ($getAccount, $session1, $session2) {
            $this->assertSame(200, $getAccount($session1)['headers']['status-code']);
            $this->assertSame(200, $getAccount($session2)['headers']['status-code']);
        }, 15_000, 500);

        // Step 4: Enable session invalidation
        $setInvalidation(true);

        // Step 5: Change password - both sessions should be invalidated
        $thirdPassword = 'thirdpassword';
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', $serverHeaders, [
            'password' => $thirdPassword,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $this->assertEventually(function () use ($getAccount, $session1, $session2) {
            $this->assertSame(401, $getAccount($session1)['headers']['status-code']);
            $this->assertSame(401, $getAccount($session2)['headers']['status-code']);
        }, 15_000, 500);

        // Step 6: Disable session invalidation again
        $setInvalidation(false);

        // Step 7: Previously-invalidated sessions stay dead
        $this->assertSame(401, $getAccount($session1)['headers']['status-code']);
        $this->assertSame(401, $getAccount($session2)['headers']['status-code']);
    }
}
