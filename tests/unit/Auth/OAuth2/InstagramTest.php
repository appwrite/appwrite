<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Instagram;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class InstagramTest extends TestCase
{
    public function testLoginURL(): void
    {
        $oauth2 = new Instagram(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback', ['success' => 'https://example.com']);

        $url = \parse_url($oauth2->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('www.instagram.com', $url['host']);
        $this->assertSame('/oauth/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('instagram_business_basic', $query['scope']);
        $this->assertSame('{"success":"https:\/\/example.com"}', $query['state']);
    }
}
