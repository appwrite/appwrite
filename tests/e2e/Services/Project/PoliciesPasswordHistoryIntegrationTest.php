<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class PoliciesPasswordHistoryIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testPasswordHistoryIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        // Step 1: Enable password history policy with limit 3
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $serverHeaders, [
            'total' => 3,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(3, $response['body']['authPasswordHistory']);

        $firstPassword = 'firstpassword';
        $secondPassword = 'secondpassword';
        $thirdPassword = 'thirdpassword';
        $fourthPassword = 'fourthpassword';

        // Step 2: Sign up user with firstpassword (policy on, so signup populates history)
        $email = 'history_' . uniqid() . '@localhost.test';
        $userId = ID::unique();

        $account = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => $userId,
            'email' => $email,
            'password' => $firstPassword,
            'name' => 'History User',
        ]);
        $this->assertSame(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $firstPassword,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
        $sessionCookie = $session['cookies']['a_session_' . $projectId];

        $clientHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $sessionCookie,
        ];

        // Change password: first -> second
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $clientHeaders, [
            'password' => $secondPassword,
            'oldPassword' => $firstPassword,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Change password: second -> third
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $clientHeaders, [
            'password' => $thirdPassword,
            'oldPassword' => $secondPassword,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Step 3: Attempt to reuse each of the 3 previous passwords - all should fail
        foreach ([$firstPassword, $secondPassword, $thirdPassword] as $reused) {
            $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $clientHeaders, [
                'password' => $reused,
                'oldPassword' => $thirdPassword,
            ]);
            $this->assertSame(400, $response['headers']['status-code'], 'Reusing password "' . $reused . '" should be blocked by history policy');
            $this->assertSame('password_recently_used', $response['body']['type']);
        }

        // Step 4: Setting fourthpassword succeeds
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $clientHeaders, [
            'password' => $fourthPassword,
            'oldPassword' => $thirdPassword,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Verify the new password works by signing in again
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $fourthPassword,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        // Step 5: Disable password history policy
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $serverHeaders, [
            'total' => null,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['authPasswordHistory']);

        // Step 6: With policy off, reusing any previous password should succeed, as should setting a brand new one.
        // oldPassword must match current password, so walk through each previous password sequentially.
        $fifthPassword = 'fifthpassword';
        $chain = [
            [$fourthPassword, $firstPassword],
            [$firstPassword, $secondPassword],
            [$secondPassword, $thirdPassword],
            [$thirdPassword, $fourthPassword],
            [$fourthPassword, $fifthPassword],
        ];

        foreach ($chain as [$current, $next]) {
            $response = $this->client->call(Client::METHOD_PATCH, '/account/password', $clientHeaders, [
                'password' => $next,
                'oldPassword' => $current,
            ]);
            $this->assertSame(200, $response['headers']['status-code'], 'Changing password from "' . $current . '" to "' . $next . '" should succeed with history policy disabled');
        }

        // Verify the final password works by signing in
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $fifthPassword,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
    }
}
