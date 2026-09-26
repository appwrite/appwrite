<?php

declare(strict_types=1);

namespace Utopia\Http\Tests;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;

final class ResponseSwooleTest extends TestCase
{
    use BaseTest;
    protected Client $client;

    public function setUp(): void
    {
        $this->client = new Client(getenv('HTTP_E2E_SWOOLE_URL') ?: 'http://localhost:19501');
    }

    public function testCookie(): void
    {
        $headers = ['Cookie: cookie1=value1; cookie2=value2'];

        $response = $this->client->call(Client::METHOD_GET, '/cookie/cookie1', $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('value1', $response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/cookie/cookie2', $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('value2', $response['body']);
    }

    public function testSwooleResources(): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/swoole-test');
        $this->assertSame('DELETE', $response['body']);
    }
}
