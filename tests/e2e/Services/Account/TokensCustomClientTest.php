<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class TokensCustomClientTest extends Scope
{
    use TokensBase;
    use ProjectCustom;
    use SideClient;

    public static function verificationTokens(): \Iterator
    {
        yield 'recovery' => ['/account/recovery', 256];
        yield 'email verification' => ['/account/verifications/email', 256];
        yield 'phone verification' => ['/account/verifications/phone', 6];
    }

    #[DataProvider('verificationTokens')]
    public function testCreateVerificationToken(string $path, int $defaultLength): void
    {
        $maxLength = $path === '/account/verifications/phone' ? 128 : 256;

        /**
         * Test for SUCCESS
         */
        foreach ([[], ['length' => 4, 'expire' => 60], ['length' => 8, 'expire' => 300], ['length' => $maxLength, 'expire' => 31536000]] as $options) {
            $user = $this->getUser(true);
            $headers = array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders());
            $phone = '+1202' . random_int(1000000, 9999999);
            $params = [];
            if ($path === '/account/verifications/phone') {
                $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/phone', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ], ['number' => $phone]);
                $this->assertEquals(200, $response['headers']['status-code']);
            } else {
                $params['url'] = 'http://localhost' . ($path === '/account/recovery' ? '/recovery' : '/verification');
                if ($path === '/account/recovery') {
                    $params['email'] = $user['email'];
                }
            }

            $response = $this->client->call(Client::METHOD_POST, $path, $headers, array_merge($params, $options));

            $this->assertEquals(201, $response['headers']['status-code']);
            $token = $response['body'];
            $this->assertTokenExpire($token, $options['expire'] ?? 3600);
            $this->assertSame($user['$id'], $token['userId']);
            $this->assertSame('', $token['secret']);

            $secret = $path === '/account/verifications/phone'
                ? $this->readPhoneCode($phone)
                : $this->readEmailLink($user['email'], $token);
            $this->assertSame($options['length'] ?? $defaultLength, strlen($secret));
            if ($path === '/account/verifications/phone') {
                $this->assertTrue(ctype_digit($secret));
            }

            $confirmation = ['userId' => $user['$id'], 'secret' => $secret];
            if ($path === '/account/recovery') {
                $confirmation['password'] = 'updated-password';
            }
            $response = $this->client->call(Client::METHOD_PUT, $path, $headers, $confirmation);
            $this->assertEquals(200, $response['headers']['status-code']);

            if ($path === '/account/recovery') {
                $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], ['email' => $user['email'], 'password' => 'updated-password']);
                $this->assertEquals(201, $session['headers']['status-code']);
                $this->assertSame($user['$id'], $session['body']['userId']);
            } else {
                $account = $this->client->call(Client::METHOD_GET, '/account', $headers);
                $this->assertEquals(200, $account['headers']['status-code']);
                $this->assertTrue($account['body'][$path === '/account/verifications/phone' ? 'phoneVerification' : 'emailVerification']);
            }
        }

        /**
         * Test for FAILURE
         */
        $this->assertInvalidTokenOptions($path, $headers, $params, $maxLength);
    }

    public static function challengeFactors(): \Iterator
    {
        yield 'email' => ['email'];
        yield 'phone' => ['phone'];
    }

    #[DataProvider('challengeFactors')]
    public function testCreateMFAChallenge(string $factor): void
    {
        /**
         * Test for SUCCESS
         */
        foreach ([[], ['length' => 4, 'expire' => 60], ['length' => 8, 'expire' => 300], ['length' => 128, 'expire' => 31536000]] as $options) {
            $user = $this->getUser(true);
            $headers = array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders());
            $serverHeaders = [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ];
            $phone = '+1202' . random_int(1000000, 9999999);
            if ($factor === 'phone') {
                $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/phone', $serverHeaders, ['number' => $phone]);
                $this->assertEquals(200, $response['headers']['status-code']);
            }
            $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user['$id'] . '/verification' . ($factor === 'phone' ? '/phone' : ''), $serverHeaders, [$factor . 'Verification' => true]);
            $this->assertEquals(200, $response['headers']['status-code']);

            $response = $this->client->call(Client::METHOD_POST, '/account/mfa/challenges', $headers, array_merge(['factor' => $factor], $options));

            $this->assertEquals(201, $response['headers']['status-code']);
            $challenge = $response['body'];
            $this->assertTokenExpire($challenge, $options['expire'] ?? 3600);
            $this->assertSame($user['$id'], $challenge['userId']);
            $this->assertArrayNotHasKey('code', $challenge);
            $this->assertArrayNotHasKey('secret', $challenge);

            $length = $options['length'] ?? 6;
            $code = $factor === 'phone'
                ? $this->readPhoneCode($phone)
                : $this->readEmailCode($user['email'], $challenge['expire'], $length);
            $this->assertSame($length, strlen($code));
            $this->assertTrue(ctype_digit($code));

            $response = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenges', $headers, [
                'challengeId' => $challenge['$id'],
                'otp' => $code,
            ]);
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertContains($factor, $response['body']['factors']);
            $this->assertSame('', $response['body']['secret']);

            $response = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenges', $headers, [
                'challengeId' => $challenge['$id'],
                'otp' => $code,
            ]);
            $this->assertEquals(401, $response['headers']['status-code']);
            $this->assertSame('user_invalid_token', $response['body']['type']);
        }

        /**
         * Test for FAILURE
         */
        $this->assertInvalidTokenOptions('/account/mfa/challenges', $headers, ['factor' => $factor], 128);
    }
}
