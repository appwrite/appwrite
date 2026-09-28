<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Discord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscordTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string|null}>
     */
    public static function promptSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret', null];
        yield 'no prompt' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => []]), null];
        yield 'none' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['none']]), 'none'];
        yield 'consent' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['consent']]), 'consent'];
    }

    #[DataProvider('promptSecrets')]
    public function testLoginURLPrompt(string $secret, ?string $expected): void
    {
        $discord = new Discord('client-id', $secret, 'https://example.com/callback');

        \parse_str((string) \parse_url($discord->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function clientSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret'];
        yield 'json secret' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['none']])];
    }

    #[DataProvider('clientSecrets')]
    public function testAccessTokenSendsClientSecret(string $secret): void
    {
        $discord = new FakeDiscord('client-id', $secret, 'https://example.com/callback');

        $this->assertSame('access-token', $discord->getAccessToken('authorization-code'));

        \parse_str($discord->payload, $params);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
    }
}

final class FakeDiscord extends Discord
{
    public string $payload = '';

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->payload = $payload;

        return \json_encode(['access_token' => 'access-token']);
    }
}
