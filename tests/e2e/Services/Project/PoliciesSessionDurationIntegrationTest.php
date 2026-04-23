<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesSessionDurationIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testSessionDurationIntegration(): void
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

        $setDuration = function (int $seconds) use ($serverHeaders): void {
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-duration', $serverHeaders, [
                'duration' => $seconds,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($seconds, $response['body']['authDuration']);
        };

        // Step 1: Set session duration to 5 seconds
        $setDuration(5);

        // Step 2: Create user and a session
        $email = 'duration_' . uniqid() . '@localhost.test';
        $password = 'password1234';

        $user = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Duration User',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
        $sessionCookie = $session['cookies']['a_session_' . $projectId];

        $accountHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $sessionCookie,
        ];

        $response = $this->client->call(Client::METHOD_GET, '/account', $accountHeaders);
        $this->assertSame(200, $response['headers']['status-code']);

        // Step 3: Poll until the 5s TTL elapses - session should expire
        $this->assertEventually(function () use ($accountHeaders) {
            $response = $this->client->call(Client::METHOD_GET, '/account', $accountHeaders);
            $this->assertSame(401, $response['headers']['status-code']);
        }, 15_000, 500);

        // Step 4: Raise duration to 10s - same session should still not be usable
        $setDuration(10);

        $response = $this->client->call(Client::METHOD_GET, '/account', $accountHeaders);
        $this->assertSame(401, $response['headers']['status-code']);

        // Step 5: Set duration to 1 year
        $setDuration(31536000);

        // Step 6: Same session should still not be usable
        $response = $this->client->call(Client::METHOD_GET, '/account', $accountHeaders);
        $this->assertSame(401, $response['headers']['status-code']);
    }
}
