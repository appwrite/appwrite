<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Utopia\Auth\Store;
use Utopia\Database\Helpers\ID;

trait TokensBase
{
    public static function loginTokens(): \Iterator
    {
        yield 'magic URL' => ['magic-url', 64, 3600];
        yield 'email OTP' => ['email', 6, 900];
        yield 'phone OTP' => ['phone', 6, 900];
    }

    #[DataProvider('loginTokens')]
    public function testCreateToken(string $type, int $defaultLength, int $defaultExpire): void
    {
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
        if ($this->getSide() === 'server') {
            $headers = array_merge($headers, $this->getHeaders());
        }

        /**
         * Test for SUCCESS
         */
        foreach ([[], ['length' => 4, 'expire' => 60], ['length' => 8, 'expire' => 300], ['length' => 128, 'expire' => 31536000]] as $options) {
            $email = ID::unique() . '@localhost.test';
            $phone = '+1202' . random_int(1000000, 9999999);
            $params = ['userId' => ID::unique()];
            if ($type === 'phone') {
                $params['phone'] = $phone;
            } else {
                $params['email'] = $email;
            }
            if ($type === 'magic-url') {
                $params['url'] = 'http://localhost/verification';
            }

            $response = $this->client->call(Client::METHOD_POST, '/account/tokens/' . $type, $headers, array_merge($params, $options));

            $this->assertEquals(201, $response['headers']['status-code']);
            $token = $response['body'];
            $length = $options['length'] ?? $defaultLength;
            $this->assertTokenExpire($token, $options['expire'] ?? $defaultExpire);
            $this->assertEquals($params['userId'], $token['userId']);

            $secret = match ($type) {
                'magic-url' => $this->readEmailLink($email, $token),
                'email' => $this->readEmailCode($email, $token['expire'], $length),
                'phone' => $this->readPhoneCode($phone),
                default => $this->fail('Unsupported token type: ' . $type),
            };
            $this->assertSame($length, strlen($secret));
            if ($type !== 'magic-url') {
                $this->assertTrue(ctype_digit($secret));
            }
            if ($this->getSide() === 'server') {
                if ($type === 'phone') {
                    $store = (new Store())->decode($token['secret']);
                    $this->assertSame($token['userId'], $store->getProperty('id'));
                    $this->assertSame($secret, $store->getProperty('secret'));
                } else {
                    $this->assertSame($secret, $token['secret']);
                }
            } else {
                $this->assertSame('', $token['secret']);
            }

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $headers, [
                'userId' => $token['userId'],
                'secret' => $secret,
            ]);

            $this->assertEquals(201, $session['headers']['status-code']);
            $this->assertSame($token['userId'], $session['body']['userId']);

            $account = $this->client->call(Client::METHOD_GET, '/account', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-session' => $this->getSide() === 'server'
                    ? $session['body']['secret']
                    : $session['cookies']['a_session_' . $this->getProject()['$id']],
            ]);

            $this->assertEquals(200, $account['headers']['status-code']);
            $this->assertSame($token['userId'], $account['body']['$id']);

            $reuse = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $headers, [
                'userId' => $token['userId'],
                'secret' => $secret,
            ]);
            $this->assertEquals(401, $reuse['headers']['status-code']);
            $this->assertSame('user_invalid_token', $reuse['body']['type']);
        }

        /**
         * Test for FAILURE
         */
        $this->assertInvalidTokenOptions('/account/tokens/' . $type, $headers, $params, 128);
    }

    protected function assertTokenExpire(array $token, int $seconds): void
    {
        $this->assertNotEmpty($token['$id']);
        $this->assertNotEmpty($token['$createdAt']);
        $this->assertNotEmpty($token['expire']);
        $this->assertEqualsWithDelta(
            $seconds,
            strtotime($token['expire']) - strtotime($token['$createdAt']),
            1
        );
    }

    protected function assertInvalidTokenOptions(string $path, array $headers, array $params, int $maxLength): void
    {
        foreach ([['length' => 3], ['length' => $maxLength + 1], ['expire' => 59], ['expire' => 31536001]] as $options) {
            $response = $this->client->call(Client::METHOD_POST, $path, $headers, array_merge($params, $options));

            $this->assertEquals(400, $response['headers']['status-code'], $path . ': ' . json_encode($options));
            $this->assertSame('general_argument_invalid', $response['body']['type']);
        }
    }

    protected function readEmailLink(string $email, array $token): string
    {
        $message = $this->getLastEmailByAddress($email, function (array $message) use ($token) {
            $params = $this->extractQueryParamsFromEmailLink($message['html']);
            $this->assertSame($token['expire'], $params['expire'] ?? null);
        });
        $params = $this->extractQueryParamsFromEmailLink($message['html']);

        $this->assertSame($token['userId'], $params['userId']);
        $this->assertNotEmpty($params['secret']);

        return $params['secret'];
    }

    protected function readEmailCode(string $email, string $expire, int $length): string
    {
        $message = $this->getLastEmailByAddress($email, function (array $message) use ($expire) {
            $this->assertStringContainsString('Expires at ' . $expire, $message['text']);
        });
        $document = new \DOMDocument();
        @$document->loadHTML($message['html']);
        foreach ($document->getElementsByTagName('p') as $paragraph) {
            $code = trim($paragraph->textContent);
            if (ctype_digit($code) && strlen($code) === $length) {
                return $code;
            }
        }

        $this->fail('Email did not contain the requested ' . $length . '-digit code.');
    }

    protected function readPhoneCode(string $phone): string
    {
        $message = $this->getLastRequestForProject(
            $this->getProject()['$id'],
            Scope::REQUEST_TYPE_SMS,
            ['header_X-Username' => 'username', 'header_X-Key' => 'password', 'method' => 'POST'],
            probe: function (array $message) use ($phone) {
                $this->assertSame($phone, $message['data']['to'] ?? null);
                $this->assertNotEmpty($message['data']['message']);
            }
        );

        $this->assertNotEmpty($message);

        return trim($message['data']['message']);
    }
}
