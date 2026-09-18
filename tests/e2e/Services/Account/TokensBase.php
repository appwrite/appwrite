<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Utopia\Database\Helpers\ID;

trait TokensBase
{
    public static function loginTokens(): \Iterator
    {
        yield 'magic URL' => ['magic-url', 3600];
        yield 'email OTP' => ['email', 900];
        yield 'phone OTP' => ['phone', 900];
    }

    #[DataProvider('loginTokens')]
    public function testCreateTokenExpire(string $type, int $defaultExpire): void
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
        foreach ([[], ['expire' => null], ['expire' => 60], ['expire' => $defaultExpire]] as $options) {
            $params = match ($type) {
                'phone' => ['userId' => ID::unique(), 'phone' => '+1202' . random_int(1000000, 9999999)],
                'email' => ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test'],
                default => ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'url' => 'http://localhost/verification'],
            };

            $response = $this->client->call(Client::METHOD_POST, '/account/tokens/' . $type, $headers, array_merge($params, $options));

            $this->assertEquals(201, $response['headers']['status-code']);
            $token = $response['body'];
            $this->assertTokenExpire($token, $options['expire'] ?? $defaultExpire);

            $secret = match ($type) {
                'phone' => $this->readPhoneCode($params['phone']),
                'email' => $this->readEmailCode($params['email'], $options['expire'] ?? $defaultExpire),
                default => $this->readEmailLink($params['email'], $token),
            };

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/token', $headers, [
                'userId' => $token['userId'],
                'secret' => $secret,
            ]);

            $this->assertEquals(201, $session['headers']['status-code']);
            $this->assertSame($token['userId'], $session['body']['userId']);
        }

        /**
         * Test for FAILURE
         */
        $params = match ($type) {
            'phone' => ['userId' => ID::unique(), 'phone' => '+1202' . random_int(1000000, 9999999)],
            'email' => ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test'],
            default => ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'url' => 'http://localhost/verification'],
        };
        $this->assertInvalidExpire('/account/tokens/' . $type, $headers, $params, $defaultExpire);
    }

    protected function assertTokenExpire(array $token, int $seconds): void
    {
        $this->assertEqualsWithDelta(
            $seconds,
            \strtotime($token['expire']) - \strtotime($token['$createdAt']),
            1
        );
    }

    protected function assertInvalidExpire(string $path, array $headers, array $params, int $maxExpire): void
    {
        foreach ([59, $maxExpire + 1] as $expire) {
            $response = $this->client->call(Client::METHOD_POST, $path, $headers, array_merge($params, ['expire' => $expire]));

            $this->assertEquals(400, $response['headers']['status-code']);
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

    protected function readEmailCode(string $email, int $expire): string
    {
        $phrase = match ($expire) {
            60 => 'in 1 minute',
            900 => 'in 15 minutes',
            3600 => 'in 1 hour',
            default => $this->fail('Unsupported expiry: ' . $expire),
        };
        $message = $this->getLastEmailByAddress($email, function (array $message) use ($phrase) {
            $this->assertStringContainsString($phrase, (string) $message['text']);
        });
        \preg_match('/\b\d{6}\b/', (string) $message['text'], $matches);
        $this->assertNotEmpty($matches);

        return $matches[0];
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
