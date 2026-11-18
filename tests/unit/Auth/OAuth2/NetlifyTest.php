<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Netlify;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;

final class NetlifyTest extends TestCase
{
    public function testLoginURL(): void
    {
        $netlify = new Netlify(new Client(new CurlAdapter()), 'client-id', 'client-secret', 'https://example.com/callback', ['success' => 'https://example.com']);

        $url = \parse_url($netlify->getLoginURL());
        \parse_str((string) ($url['query'] ?? ''), $query);

        $this->assertSame('app.netlify.com', $url['host']);
        $this->assertSame('/authorize', $url['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://example.com/callback', $query['redirect_uri']);
        $this->assertSame('{"success":"https:\/\/example.com"}', $query['state']);
    }
}
