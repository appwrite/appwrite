<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ConsoleHealthTest extends BaseConsole
{   
    public function testHTTPSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);
    }

    public function testDBSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/db', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);
    }

    public function testCacheSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/db', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);
    }

    public function testTimeSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/time', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['remote']);
        $this->assertIsInt($response['body']['local']);
        $this->assertNotEmpty($response['body']['remote']);
        $this->assertNotEmpty($response['body']['local']);
        $this->assertLessThan(10, $response['body']['diff']);
    }

    public function testWebhooksSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(10, $response['body']['size']);
    }

    public function xtestStorageLocalSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/storage/local', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);
    }

    public function testStorageAntiVirusSuccess()
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/storage/anti-virus', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('online', $response['body']['status']);
        $this->assertStringStartsWith('ClamAV ', $response['body']['version']);
    }
}