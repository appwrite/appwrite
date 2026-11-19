<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Bird;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class BirdTest extends TestCase
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
        $bird = new Bird(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');

        $url = \parse_url($bird->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('platform.bird.com', $url['host']);
        $this->assertSame('/v1/oauth/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('workspace:read', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testTokenRequestSendsClientCredentials(): void
    {
        $bird = new FakeBird(['access_token' => 'bt_us1_access-token']);

        $this->assertSame('bt_us1_access-token', $bird->getAccessToken('authorization-code'));

        \parse_str($bird->payload, $params);
        $this->assertSame('https://platform.bird.com/v1/oauth/token', $bird->url);
        $this->assertSame('client-id', $params['client_id'] ?? null);
        $this->assertSame('client-secret', $params['client_secret'] ?? null);
    }

    public function testUserIsReadFromAuthMe(): void
    {
        $bird = new FakeBird([
            'id' => 'usr_01krdgeqcxet5s7t44vh8rt9mg',
            'email' => 'aisha@example.com',
            'full_name' => 'Aisha Khan',
            'avatar_url' => 'https://example.com/avatar.png',
            'email_verified_at' => '2026-01-01T00:00:00Z',
        ]);

        $this->assertSame('usr_01krdgeqcxet5s7t44vh8rt9mg', $bird->getUserID('bt_us1_access-token'));
        $this->assertSame('aisha@example.com', $bird->getUserEmail('bt_us1_access-token'));
        $this->assertSame('Aisha Khan', $bird->getUserName('bt_us1_access-token'));
        $this->assertSame('https://example.com/avatar.png', $bird->getUserPhoto('bt_us1_access-token'));
        $this->assertTrue($bird->isEmailVerified('bt_us1_access-token'));
        $this->assertSame('https://platform.bird.com/v1/auth/me', $bird->url);
        $this->assertSame(['Authorization: Bearer bt_us1_access-token'], $bird->headers);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function unverifiedUsers(): \Iterator
    {
        yield 'never verified' => [['id' => 'usr_1', 'email' => 'aisha@example.com', 'email_verified_at' => null]];
        yield 'field omitted' => [['id' => 'usr_1', 'email' => 'aisha@example.com']];
    }

    /**
     * @param array<string, mixed> $user
     */
    #[DataProvider('unverifiedUsers')]
    public function testEmailIsUnverifiedWithoutVerificationTime(array $user): void
    {
        $bird = new FakeBird($user);

        $this->assertFalse($bird->isEmailVerified('bt_us1_access-token'));
    }
}

final class FakeBird extends Bird
{
    public string $url = '';

    /** @var array<string> */
    public array $headers = [];

    public string $payload = '';

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback');
    }

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->payload = $payload;

        return \json_encode($this->response, JSON_THROW_ON_ERROR);
    }
}
