<?php

namespace Tests\E2E\Services\Account;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

trait AccountBase
{
    public function testCreateAccount(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);
        $this->assertEquals($response['body']['labels'], []);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);
        $this->assertArrayHasKey('targets', $response['body']);
        $this->assertEquals($email, $response['body']['targets'][0]['identifier']);
        $this->assertArrayNotHasKey('emailCanonical', $response['body']);
        $this->assertArrayNotHasKey('emailIsFree', $response['body']);
        $this->assertArrayNotHasKey('emailIsDisposable', $response['body']);
        $this->assertArrayNotHasKey('emailIsCorporate', $response['body']);
        $this->assertArrayNotHasKey('emailIsCanonical', $response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 409);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? '',
        ]), [
            'userId' => ID::unique(),
            'email' => '',
            'password' => $password,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $shortPassword = 'short';
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => 'shortpass@appwrite.io',
            'password' => $shortPassword
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longPassword = '';
        for ($i = 0; $i < 257; $i++) { // 256 is the limit
            $longPassword .= 'p';
        }

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => 'longpass@appwrite.io',
            'password' => $longPassword,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ];
    }

    public function testEmailOTPSession(): void
    {
        $isConsoleProject = $this->getProject()['$id'] === 'console';

        // Use unique email to avoid parallel test collisions
        $otpEmail = 'otpuser-' . uniqid() . '@appwrite.io';

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $otpEmail
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmailByAddress($otpEmail);

        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $otpEmail);
        $this->assertEquals('OTP for ' . $this->getProject()['name'] . ' Login', $lastEmail['subject']);

        // FInd 6 concurrent digits in email text - OTP
        preg_match_all("/\b\d{6}\b/", $lastEmail['text'], $matches);
        $code = ($matches[0] ?? [])[0] ?? '';

        $this->assertNotEmpty($code);
        $this->assertStringContainsStringIgnoringCase('Use OTP ' . $code . ' to sign in to '. $this->getProject()['name'] . '. Expires in 15 minutes.', $lastEmail['text']);

        // Only Console project has branded logo in email.
        if ($isConsoleProject) {
            $this->assertStringContainsStringIgnoringCase('Appwrite logo', $lastEmail['html']);
        } else {
            $this->assertStringNotContainsStringIgnoringCase('Appwrite logo', $lastEmail['html']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $code
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);
        $this->assertEquals($userId, $response['body']['$id']);
        $this->assertTrue($response['body']['emailVerification']);
        $this->assertArrayHasKey('targets', $response['body']);
        $this->assertEquals($otpEmail, $response['body']['targets'][0]['identifier']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $code
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('user_invalid_token', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $otpEmail,
            'phrase' => true
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']['phrase']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals($userId, $response['body']['userId']);

        $phrase = $response['body']['phrase'];

        $lastEmail = $this->getLastEmailByAddress($otpEmail, function ($email) use ($phrase) {
            $this->assertStringContainsStringIgnoringCase('security phrase', $email['text']);
            $this->assertStringContainsStringIgnoringCase($phrase, $email['text']);
        });
        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $otpEmail);
        $this->assertEquals('OTP for ' . $this->getProject()['name'] . ' Login', $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('security phrase', $lastEmail['text']);
        $this->assertStringContainsStringIgnoringCase($phrase, $lastEmail['text']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => 'wrongemail'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('general_argument_invalid', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => 'wrongId$',
            'email' => 'email@appwrite.io'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('general_argument_invalid', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('general_argument_invalid', $response['body']['type']);
    }

    public function testDeleteAccount(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_DELETE, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);
    }

    public function testFallbackForTrustedIp(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        // call appwrite directly to avoid proxy stripping the headers
        $this->client->setEndpoint('http://localhost/v1');

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '191.0.113.195',
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '191.0.113.195',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertEquals('191.0.113.195', $response['body']['clientIp'] ?? $response['body']['ip'] ?? '');
    }

    #[Group('abuseEnabled')]
    public function testAccountAbuseReset(): void
    {
        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'disabled') {
            $this->markTestSkipped('Abuse checks are disabled.');
        }

        $email = 'abuse.reset.' . bin2hex(random_bytes(8)) . '@example.com';
        $password = 'password';
        $abuseIp = '203.0.113.' . random_int(1, 254);
        $baseHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => $abuseIp,
        ];
        $account = $this->client->call(Client::METHOD_POST, '/account', $baseHeaders, [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Abuse Reset Test',
        ]);

        $this->assertEquals($account['headers']['status-code'], 201);

        // 20 successful requests won't get blocked
        for ($i = 0; $i < 20; $i++) {
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $baseHeaders, [
                'email' => $email,
                'password' => $password,
            ]);

            $this->assertEquals($session['headers']['status-code'], 201);
        }

        // 10 failures are OK
        for ($i = 0; $i < 10; $i++) {
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $baseHeaders, [
                'email' => $email,
                'password' => 'wrongPassword',
            ]);

            $this->assertEquals($session['headers']['status-code'], 401);
        }

        // Next failure(s) should be rate limited
        $rateLimited = false;
        for ($i = 0; $i < 10; $i++) {
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $baseHeaders, [
                'email' => $email,
                'password' => 'wrongPassword',
            ]);

            if ($session['headers']['status-code'] === 429) {
                $rateLimited = true;
                break;
            }

            $this->assertEquals($session['headers']['status-code'], 401);
        }

        $this->assertTrue($rateLimited, 'Expected a rate limited response after repeated failures.');

        // Even correct password is now blocked, correctness doesn't matter
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $baseHeaders, [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($session['headers']['status-code'], 429);
    }
}
