<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectNone;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideNone;

class HTTPTest extends Scope
{
    use ProjectNone;
    use SideNone;

    public function testOptions()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_OPTIONS, '/', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Appwrite', $response['headers']['server']);
        $this->assertEquals('GET, POST, PUT, PATCH, DELETE', $response['headers']['access-control-allow-methods']);
        $this->assertEquals('Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version, Cache-Control, Expires, Pragma, X-Fallback-Cookies', $response['headers']['access-control-allow-headers']);
        $this->assertEquals('X-Fallback-Cookies', $response['headers']['access-control-expose-headers']);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);
        $this->assertEquals('true', $response['headers']['access-control-allow-credentials']);
        $this->assertEmpty($response['body']);
    }

    public function testError()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/error', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), []);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Not Found', $response['body']['message']);
        $this->assertEquals(404, $response['body']['code']);
        $this->assertEquals('dev', $response['body']['version']);
    }

    public function testManifest()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/manifest.json', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Appwrite', $response['body']['name']);
        $this->assertEquals('Appwrite', $response['body']['short_name']);
        $this->assertEquals('.', $response['body']['start_url']);
        $this->assertEquals('.', $response['body']['start_url']);
        $this->assertEquals('https://appwrite.io/', $response['body']['url']);
        $this->assertEquals('standalone', $response['body']['display']);
    }

    public function testHumans()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/humans.txt', array_merge([
            'origin' => 'http://localhost',
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('# humanstxt.org/', $response['body']);
    }

    public function testRobots()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/robots.txt', array_merge([
            'origin' => 'http://localhost',
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('# robotstxt.org/', $response['body']);
    }
}