<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Zoho;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZohoTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string|null}>
     */
    public static function promptSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret', null];
        yield 'no prompt' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => []]), null];
        yield 'consent' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['consent']]), 'consent'];
    }

    #[DataProvider('promptSecrets')]
    public function testLoginURLPrompt(string $secret, ?string $expected): void
    {
        $zoho = new Zoho('client-id', $secret, 'https://example.com/callback');

        \parse_str((string) \parse_url($zoho->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function clientSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret'];
        yield 'json secret' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['consent']])];
    }

    #[DataProvider('clientSecrets')]
    public function testAccessTokenSendsClientSecret(string $secret): void
    {
        $zoho = new FakeZoho('client-id', $secret, 'https://example.com/callback');

        $this->assertSame('access-token', $zoho->getAccessToken('authorization-code'));

        \parse_str($zoho->payload, $params);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
    }
}

final class FakeZoho extends Zoho
{
    public string $payload = '';

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->payload = $payload;

        return \json_encode([
            'access_token' => 'access-token',
            'id_token' => 'header.' . \base64_encode(\json_encode(['sub' => 'user-id'])) . '.signature',
        ]);
    }
}
