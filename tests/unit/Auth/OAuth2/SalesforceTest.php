<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Salesforce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesforceTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string|null}>
     */
    public static function promptSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret', null];
        yield 'no prompt' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => []]), null];
        yield 'login' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['login']]), 'login'];
        yield 'login and consent' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['login', 'consent']]), 'login consent'];
    }

    #[DataProvider('promptSecrets')]
    public function testLoginURLPrompt(string $secret, ?string $expected): void
    {
        $salesforce = new Salesforce('client-id', $secret, 'https://example.com/callback');

        \parse_str((string) \parse_url($salesforce->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame($expected, $query['prompt'] ?? null);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function clientSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret'];
        yield 'json secret' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['login']])];
    }

    #[DataProvider('clientSecrets')]
    public function testAccessTokenSendsClientSecret(string $secret): void
    {
        $salesforce = new FakeSalesforce('client-id', $secret, 'https://example.com/callback');

        $this->assertSame('access-token', $salesforce->getAccessToken('authorization-code'));

        $this->assertContains('Authorization: Basic ' . \base64_encode('client-id:client-secret'), $salesforce->headers);
    }
}

final class FakeSalesforce extends Salesforce
{
    public array $headers = [];

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->headers = $headers;

        return \json_encode(['access_token' => 'access-token']);
    }
}
