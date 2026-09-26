<?php

declare(strict_types=1);

namespace Utopia\Http\Tests;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;

final class ResponseFPMTest extends TestCase
{
    use BaseTest;
    protected Client $client;

    public function setUp(): void
    {
        $this->client = new Client(getenv('HTTP_E2E_FPM_URL') ?: 'http://localhost:19020');
    }

    public function testCookie(): void
    {
        $cookie = 'cookie1=value1';
        $response = $this->client->call(Client::METHOD_GET, '/cookies', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);

        $cookie = 'cookie1=value1; cookie2=value2';
        $response = $this->client->call(Client::METHOD_GET, '/cookies', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);

        $cookie = 'cookie1=value1;cookie2=value2';
        $response = $this->client->call(Client::METHOD_GET, '/cookies', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);

        $cookie = 'cookie1=value1=value2';
        $response = $this->client->call(Client::METHOD_GET, '/cookies', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);

        $cookie = 'cookie1=v1; Cookie1=v2';
        $response = $this->client->call(Client::METHOD_GET, '/cookies', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertEquals($cookie, $response['body']);
    }
}
