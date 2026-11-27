<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Clerk;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class ClerkTest extends TestCase
{
    public function testLoginURL(): void
    {
        $secret = \json_encode(['clientSecret' => 'client-secret', 'clerkDomain' => 'clerk.example.com']);
        $clerk = new Clerk(new Client(new CurlAdapter()), 'client-id', $secret, 'https://example.com/callback', ['success' => 'https://example.com']);

        $url = \parse_url($clerk->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('clerk.example.com', $url['host']);
        $this->assertSame('/oauth/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('openid profile email offline_access', $query['scope']);
        $this->assertSame('{"success":"https:\\/\\/example.com"}', $query['state']);
    }
}
