<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\Naver;
use Appwrite\Extend\Exception as AppwriteException;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class NaverTest extends TestCase
{
    public function testLoginURL(): void
    {
        $naver = new Naver(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback', ['success' => 'https://example.com']);

        $url = \parse_url($naver->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('nid.naver.com', $url['host']);
        $this->assertSame('/oauth2.0/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame(['success' => 'https://example.com'], \json_decode($query['state'], true));
    }

    public function testRejectedTokenRequest(): void
    {
        // Naver answers a rejected token request under an HTTP 200
        $naver = new FakeNaver(\json_encode([
            'error' => 'invalid_request',
            'error_description' => 'no valid data in session',
        ], JSON_THROW_ON_ERROR));

        try {
            $naver->getAccessToken('expired-code');
            $this->fail('Expected the Naver OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_request', $exception->getError());
            $this->assertSame('no valid data in session', $exception->getErrorDescription());
        }
    }

    public function testRefreshTokensKeepsExistingRefreshToken(): void
    {
        $naver = new FakeNaver(\json_encode([
            'access_token' => 'refreshed-access-token',
            'token_type' => 'bearer',
            'expires_in' => '3600',
        ], JSON_THROW_ON_ERROR));

        $tokens = $naver->refreshTokens('existing-refresh-token');

        $this->assertSame('existing-refresh-token', $tokens['refresh_token']);
    }

    public function testUserClaims(): void
    {
        $naver = new FakeNaver($this->profile([
            'nickname' => 'OpenAPI',
            'name' => 'Naver User',
            'email' => 'user@naver.example',
            'profile_image' => 'https://naver.example/profile.gif',
        ]));

        $this->assertSame('32742776', $naver->getUserID('access-token'));
        $this->assertSame('user@naver.example', $naver->getUserEmail('access-token'));
        $this->assertSame('Naver User', $naver->getUserName('access-token'));
        $this->assertSame('https://naver.example/profile.gif', $naver->getUserPhoto('access-token'));
    }

    public function testUserNameFallsBackToNickname(): void
    {
        $naver = new FakeNaver($this->profile(['nickname' => 'OpenAPI']));

        $this->assertSame('OpenAPI', $naver->getUserName('access-token'));
    }

    public function testDeclinedConsentItems(): void
    {
        // Nothing but the id is returned when every optional item is declined
        $naver = new FakeNaver($this->profile());

        $this->assertSame('32742776', $naver->getUserID('access-token'));
        $this->assertSame('', $naver->getUserEmail('access-token'));
        $this->assertSame('', $naver->getUserName('access-token'));
        $this->assertSame('', $naver->getUserPhoto('access-token'));
    }

    public function testEmailIsNeverVerified(): void
    {
        // Naver reports nothing about the address on the profile
        $naver = new FakeNaver($this->profile(['email' => 'user@naver.example']));

        $this->assertFalse($naver->isEmailVerified('access-token'));
    }

    /**
     * @param array<string, string> $account
     */
    private function profile(array $account = []): string
    {
        return \json_encode([
            'resultcode' => '00',
            'message' => 'success',
            'response' => \array_merge(['id' => '32742776'], $account),
        ], JSON_THROW_ON_ERROR);
    }
}

final class FakeNaver extends Naver
{
    public function __construct(private readonly string $response)
    {
        parent::__construct(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');
    }

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        return $this->response;
    }
}
