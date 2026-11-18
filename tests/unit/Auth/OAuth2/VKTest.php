<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\VK;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class VKTest extends TestCase
{
    protected function setUp(): void
    {
        // The PKCE verifier is encrypted before it is placed in the state.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    public function testLoginURL(): void
    {
        $query = $this->login();

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('vkid.personal_info email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testStateUsesOnlyAllowedCharacters(): void
    {
        $state = $this->login()['state'];

        $this->assertTrue(\ctype_alnum(\str_replace(['-', '_'], '', $state)));
    }

    public function testParseStateRestoresLoginState(): void
    {
        $vk = new VK(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $state = $vk->parseState($this->login()['state']);

        $this->assertSame('https://example.com/success', $state['success']);
    }

    public function testParseStateRejectsInvalidState(): void
    {
        $vk = new VK(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $this->assertNull($vk->parseState('invalid'));
    }

    /**
     * @return array<string, string>
     */
    private function login(): array
    {
        $vk = new VK(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback', ['success' => 'https://example.com/success']);

        \parse_str((string) \parse_url($vk->getLoginURL(), PHP_URL_QUERY), $query);

        return $query;
    }
}
