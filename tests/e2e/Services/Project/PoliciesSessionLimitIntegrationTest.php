<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesSessionLimitIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testSessionLimitIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $email = 'session_' . uniqid() . '@localhost.test';
        $password = 'password1234';

        // Create user (via API key so signup rules don't interfere)
        $response = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Session User',
        ]);
        $this->assertSame(201, $response['headers']['status-code']);

        $login = function () use ($publicHeaders, $email, $password): string {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $response['headers']['status-code']);
            return $response['cookies']['a_session_' . $this->getProject()['$id']];
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

        $setSessionLimit = function (?int $total) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-limit', $serverHeaders, [
                'total' => $total,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
        };

        // Step 1: Session limit = 1
        $setSessionLimit(1);

        $session1 = $login();
        $this->assertEventually(function () use ($getAccount, $session1) {
            $response = $getAccount($session1);
            $this->assertSame(200, $response['headers']['status-code']);
        }, 15_000, 500);

        // New session pushes old one out
        $session2 = $login();

        \sleep(3); // Giving ::shutdown() hooks some time

        $this->assertSame(200, $getAccount($session2)['headers']['status-code']);
        $this->assertSame(401, $getAccount($session1)['headers']['status-code']);

        // Step 2: Session limit = 2
        $setSessionLimit(2);

        $session3 = $login();

        \sleep(3); // Giving ::shutdown() hooks some time

        $this->assertSame(200, $getAccount($session2)['headers']['status-code']);
        $this->assertSame(200, $getAccount($session3)['headers']['status-code']);

        // Step 3: 4th session evicts session2 (oldest), session3 and session4 remain
        $session4 = $login();

        \sleep(3); // Giving ::shutdown() hooks some time

        $this->assertSame(200, $getAccount($session4)['headers']['status-code']);
        $this->assertSame(200, $getAccount($session3)['headers']['status-code']);
        $this->assertSame(401, $getAccount($session2)['headers']['status-code']);

        // Step 4: Disable session limit, create 5 new sessions, all should remain usable
        $setSessionLimit(null);

        $newSessions = [];
        for ($i = 0; $i < 5; $i++) {
            $newSessions[] = $login();
        }

        foreach ($newSessions as $index => $sessionCookie) {
            $this->assertSame(200, $getAccount($sessionCookie)['headers']['status-code'], 'Session #' . ($index + 1) . ' should remain valid when limit is disabled');
        }
    }
}
