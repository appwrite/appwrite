<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Kakao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KakaoTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string|null}>
     */
    public static function promptSecrets(): \Iterator
    {
        yield 'plain secret' => ['client-secret', null];
        yield 'no prompt' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => []]), null];
        yield 'none' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['none']]), 'none'];
        yield 'login and select account' => [\json_encode(['clientSecret' => 'client-secret', 'prompt' => ['login', 'select_account']]), 'login,select_account'];
    }

    #[DataProvider('promptSecrets')]
    public function testLoginURLPrompt(string $secret, ?string $expected): void
    {
        $kakao = new Kakao('client-id', $secret, 'https://example.com/callback');

        \parse_str((string) \parse_url($kakao->getLoginURL(), PHP_URL_QUERY), $query);

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
        $kakao = new FakeKakao('client-id', $secret, 'https://example.com/callback');

        $this->assertSame('access-token', $kakao->getAccessToken('authorization-code'));

        \parse_str($kakao->payload, $params);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
    }
}

final class FakeKakao extends Kakao
{
    public string $payload = '';

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->payload = $payload;

        return \json_encode(['access_token' => 'access-token']);
    }
}
