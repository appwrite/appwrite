<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class MockPhonesSessionIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testMockPhoneSessionIntegration(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $clientHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        // Step 1: Configure two mock phones with distinct OTPs.
        $phoneA = '+1' . \random_int(2000000000, 9999999999);
        $phoneB = '+1' . \random_int(2000000000, 9999999999);
        $otpA = '111111';
        $otpB = '222222';

        $mockA = $this->client->call(Client::METHOD_POST, '/project/mock-phones', $serverHeaders, [
            'number' => $phoneA,
            'otp' => $otpA,
        ]);
        $this->assertSame(201, $mockA['headers']['status-code']);
        $this->assertSame($phoneA, $mockA['body']['number']);
        $this->assertSame($otpA, $mockA['body']['otp']);

        $mockB = $this->client->call(Client::METHOD_POST, '/project/mock-phones', $serverHeaders, [
            'number' => $phoneB,
            'otp' => $otpB,
        ]);
        $this->assertSame(201, $mockB['headers']['status-code']);
        $this->assertSame($phoneB, $mockB['body']['number']);
        $this->assertSame($otpB, $mockB['body']['otp']);

        // Step 2 (Phone A): sign-in flow that also creates the user (userId = unique()).
        $tokenA = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', $clientHeaders, [
            'userId' => ID::unique(),
            'phone' => $phoneA,
        ]);
        $this->assertSame(201, $tokenA['headers']['status-code']);
        $userIdA = $tokenA['body']['userId'];
        $this->assertNotEmpty($userIdA);

        // Arbitrary wrong OTP must be rejected.
        $wrongA = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdA,
            'secret' => '999999',
        ]);
        $this->assertSame(401, $wrongA['headers']['status-code']);

        // Phone B's OTP must not unlock Phone A's user — proves OTPs are scoped to the mock record.
        $crossA = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdA,
            'secret' => $otpB,
        ]);
        $this->assertSame(401, $crossA['headers']['status-code']);

        // Correct mock OTP establishes the session.
        $sessionA = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdA,
            'secret' => $otpA,
        ]);
        $this->assertSame(201, $sessionA['headers']['status-code']);
        $this->assertNotEmpty($sessionA['cookies']['a_session_' . $projectId] ?? null);
        $cookieA = $sessionA['cookies']['a_session_' . $projectId];

        // GET /account using the session confirms identity.
        $accountA = $this->client->call(Client::METHOD_GET, '/account', \array_merge($clientHeaders, [
            'cookie' => 'a_session_' . $projectId . '=' . $cookieA,
        ]));
        $this->assertSame(200, $accountA['headers']['status-code']);
        $this->assertSame($userIdA, $accountA['body']['$id']);
        $this->assertSame($phoneA, $accountA['body']['phone']);
        $this->assertTrue($accountA['body']['phoneVerification']);

        // Step 3 (Phone B): pre-create the user server-side, then sign in with the mock OTP.
        $precreated = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => ID::unique(),
            'phone' => $phoneB,
        ]);
        $this->assertSame(201, $precreated['headers']['status-code']);
        $userIdB = $precreated['body']['$id'];
        $this->assertSame($phoneB, $precreated['body']['phone']);

        $tokenB = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', $clientHeaders, [
            'userId' => $userIdB,
            'phone' => $phoneB,
        ]);
        $this->assertSame(201, $tokenB['headers']['status-code']);
        $this->assertSame($userIdB, $tokenB['body']['userId']);

        // Arbitrary wrong OTP must be rejected.
        $wrongB = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdB,
            'secret' => '000000',
        ]);
        $this->assertSame(401, $wrongB['headers']['status-code']);

        // Phone A's OTP must not unlock Phone B's user.
        $crossB = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdB,
            'secret' => $otpA,
        ]);
        $this->assertSame(401, $crossB['headers']['status-code']);

        // Correct mock OTP establishes the session.
        $sessionB = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', $clientHeaders, [
            'userId' => $userIdB,
            'secret' => $otpB,
        ]);
        $this->assertSame(201, $sessionB['headers']['status-code']);
        $this->assertNotEmpty($sessionB['cookies']['a_session_' . $projectId] ?? null);
        $cookieB = $sessionB['cookies']['a_session_' . $projectId];

        // GET /account using the session confirms identity.
        $accountB = $this->client->call(Client::METHOD_GET, '/account', \array_merge($clientHeaders, [
            'cookie' => 'a_session_' . $projectId . '=' . $cookieB,
        ]));
        $this->assertSame(200, $accountB['headers']['status-code']);
        $this->assertSame($userIdB, $accountB['body']['$id']);
        $this->assertSame($phoneB, $accountB['body']['phone']);
        $this->assertTrue($accountB['body']['phoneVerification']);

        // Cross-check: the two flows produced distinct users.
        $this->assertNotSame($userIdA, $userIdB);
        $this->assertNotSame($accountA['body']['phone'], $accountB['body']['phone']);

        // Cleanup mock phone config to avoid polluting project state for later tests.
        $this->client->call(Client::METHOD_DELETE, '/project/mock-phones/' . \urlencode($phoneA), $serverHeaders);
        $this->client->call(Client::METHOD_DELETE, '/project/mock-phones/' . \urlencode($phoneB), $serverHeaders);
    }
}
