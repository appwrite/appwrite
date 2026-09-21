<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesMFAFactorsIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testMFAFactorsDefaults(): void
    {
        // A project that never set the policy allows native factors and blocks custom
        $project = $this->getProject(true);
        $projectId = $project['$id'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $project['apiKey'],
        ];

        $policy = $this->client->call(Client::METHOD_GET, '/project/policies/mfa-factors', $serverHeaders);
        $this->assertSame(200, $policy['headers']['status-code']);
        $this->assertTrue($policy['body']['totp']);
        $this->assertTrue($policy['body']['email']);
        $this->assertTrue($policy['body']['phone']);
        $this->assertFalse($policy['body']['custom']);

        $list = $this->client->call(Client::METHOD_GET, '/project/policies', $serverHeaders);
        $this->assertSame(200, $list['headers']['status-code']);

        $byId = [];
        foreach ($list['body']['policies'] as $listed) {
            $byId[$listed['$id']] = $listed;
        }
        $this->assertArrayHasKey('mfa-factors', $byId);
        $this->assertSame($policy['body'], $byId['mfa-factors']);

        $member = $this->createUserWithSession($serverHeaders);

        $factors = $this->client->call(Client::METHOD_GET, '/account/mfa/factors', $member['sessionHeaders']);
        $this->assertSame(200, $factors['headers']['status-code']);
        $this->assertFalse($factors['body']['custom']);

        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $member['sessionHeaders'], [
            'factor' => 'custom',
        ]);
        $this->assertSame(501, $challenge['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $challenge['body']['type']);
    }

    public function testMFAFactorsPolicyIntegration(): void
    {
        $project = $this->getProject(true);
        $projectId = $project['$id'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $project['apiKey'],
        ];

        // Step 1: Enable the custom factor and disable email
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/mfa-factors', $serverHeaders, [
            'custom' => true,
            'email' => false,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $policy = $this->client->call(Client::METHOD_GET, '/project/policies/mfa-factors', $serverHeaders);
        $this->assertSame(200, $policy['headers']['status-code']);
        $this->assertTrue($policy['body']['totp']);
        $this->assertFalse($policy['body']['email']);
        $this->assertTrue($policy['body']['phone']);
        $this->assertTrue($policy['body']['custom']);

        // Step 2: A user with a verified email sees the policy reflected in factors
        $member = $this->createUserWithSession($serverHeaders, verifiedEmail: true);

        $factors = $this->client->call(Client::METHOD_GET, '/account/mfa/factors', $member['sessionHeaders']);
        $this->assertSame(200, $factors['headers']['status-code']);
        $this->assertTrue($factors['body']['custom']);
        $this->assertFalse($factors['body']['email']);

        // Step 3: Challenges for a disabled factor are rejected
        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $member['sessionHeaders'], [
            'factor' => 'email',
        ]);
        $this->assertSame(501, $challenge['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $challenge['body']['type']);

        // Step 4: The custom factor completes the full challenge flow
        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $member['sessionHeaders'], [
            'factor' => 'custom',
        ]);
        $this->assertSame(201, $challenge['headers']['status-code']);
        $this->assertArrayNotHasKey('code', $challenge['body']);
        $challengeId = $challenge['body']['$id'];

        $secret = $this->client->call(Client::METHOD_GET, '/users/' . $member['userId'] . '/mfa/challenges/' . $challengeId, $serverHeaders);
        $this->assertSame(200, $secret['headers']['status-code']);
        $this->assertNotEmpty($secret['body']['code']);

        $verification = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenges', $member['sessionHeaders'], [
            'challengeId' => $challengeId,
            'otp' => $secret['body']['code'],
        ]);
        $this->assertSame(200, $verification['headers']['status-code']);
        $this->assertContains('custom', $verification['body']['factors']);

        // Step 5: Leave a custom challenge pending, then restore email and disable custom again
        $pending = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $member['sessionHeaders'], [
            'factor' => 'custom',
        ]);
        $this->assertSame(201, $pending['headers']['status-code']);

        $pendingSecret = $this->client->call(Client::METHOD_GET, '/users/' . $member['userId'] . '/mfa/challenges/' . $pending['body']['$id'], $serverHeaders);
        $this->assertSame(200, $pendingSecret['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/mfa-factors', $serverHeaders, [
            'custom' => false,
            'email' => true,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        // Disabling a factor also invalidates challenges issued while it was enabled
        $verification = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenges', $member['sessionHeaders'], [
            'challengeId' => $pending['body']['$id'],
            'otp' => $pendingSecret['body']['code'],
        ]);
        $this->assertSame(501, $verification['headers']['status-code']);
        $this->assertSame('user_auth_method_unsupported', $verification['body']['type']);

        $factors = $this->client->call(Client::METHOD_GET, '/account/mfa/factors', $member['sessionHeaders']);
        $this->assertSame(200, $factors['headers']['status-code']);
        $this->assertFalse($factors['body']['custom']);
        $this->assertTrue($factors['body']['email']);

        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $member['sessionHeaders'], [
            'factor' => 'custom',
        ]);
        $this->assertSame(501, $challenge['headers']['status-code']);
    }

    /**
     * Create a user with a server-issued session.
     *
     * @param array<string, string> $serverHeaders
     * @return array{userId: string, sessionHeaders: array<string, string>}
     */
    private function createUserWithSession(array $serverHeaders, bool $verifiedEmail = false): array
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'email' => 'mfa_' . \uniqid() . '@localhost.test',
            'password' => 'password1234',
            'name' => 'Morgan Miller',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        if ($verifiedEmail) {
            $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/verification', $serverHeaders, [
                'emailVerification' => true,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
        }

        $session = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/sessions', $serverHeaders);
        $this->assertSame(201, $session['headers']['status-code']);

        return [
            'userId' => $userId,
            'sessionHeaders' => [
                'content-type' => 'application/json',
                'x-appwrite-project' => $serverHeaders['x-appwrite-project'],
                'x-appwrite-session' => $session['body']['secret'],
            ],
        ];
    }
}
