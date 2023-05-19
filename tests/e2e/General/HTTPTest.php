<?php

namespace Tests\E2E\General;

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
        $response = $this->client->call(Client::METHOD_OPTIONS, '/', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals('Appwrite', $response['headers']['server']);
        $this->assertEquals('GET, POST, PUT, PATCH, DELETE', $response['headers']['access-control-allow-methods']);
        $this->assertEquals('Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-SDK-Offline, X-Appwrite-ID, X-Appwrite-Timestamp, Content-Range, Range, Cache-Control, Expires, Pragma, X-Fallback-Cookies', $response['headers']['access-control-allow-headers']);
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
        $this->markTestIncomplete('This test needs to be updated for the new console.');
        // $response = $this->client->call(Client::METHOD_GET, '/error', \array_merge([
        //     'origin' => 'http://localhost',
        //     'content-type' => 'application/json',
        // ]), []);

        // $this->assertEquals(404, $response['headers']['status-code']);
        // $this->assertEquals('Not Found', $response['body']['message']);
        // $this->assertEquals(Exception::GENERAL_ROUTE_NOT_FOUND, $response['body']['type']);
        // $this->assertEquals(404, $response['body']['code']);
        // $this->assertEquals('dev', $response['body']['version']);
    }

    public function testHumans()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/humans.txt', \array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/robots.txt', \array_merge([
            'origin' => 'http://localhost',
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('# robotstxt.org/', $response['body']);
    }

    public function testAcmeChallenge()
    {
        // Preparation
        $previousEndpoint = $this->client->getEndpoint();
        $this->client->setEndpoint("http://localhost");

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/.well-known/acme-challenge/8DdIKX257k6Dih5s_saeVMpTnjPJdKO5Ase0OCiJrIg', \array_merge([
            'origin' => 'http://localhost',
        ]), []);

        $this->assertEquals(404, $response['headers']['status-code']);
        // 'Unknown path', but validation passed

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/.well-known/acme-challenge/../../../../../../../etc/passwd', \array_merge([
            'origin' => 'http://localhost',
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->setEndpoint($previousEndpoint);
    }

    public function testSpecs()
    {
        $directory = __DIR__ . '/../../../app/config/specs/';

        $files = scandir($directory);
        $client = new Client();
        $client->setEndpoint('https://validator.swagger.io');

        $versions = [
            'latest',
            '0.15.x',
            '0.14.x',
        ];

        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }

            $allowed = false;
            foreach ($versions as $version) {
                if (\str_contains($file, $version)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                continue;
            }

            /**
             * Test for SUCCESS
             */
            $response = $client->call(Client::METHOD_POST, '/validator/debug', [
                'content-type' => 'application/json',
            ], json_decode(file_get_contents($directory . $file), true));

            $response['body'] = json_decode($response['body'], true);
            $this->assertEquals(200, $response['headers']['status-code']);
            // looks like recent change in the validator
            $this->assertTrue(empty($response['body']['schemaValidationMessages']));
        }
    }

    public function testVersions()
    {
        /**
         * Test without header
         */
        $response = $this->client->call(Client::METHOD_GET, '/versions', \array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()));

        $body = $response['body'];
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsString($body['server']);
        $this->assertIsString($body['client-web']);
        $this->assertIsString($body['client-flutter']);
        $this->assertIsString($body['console-web']);
        $this->assertIsString($body['server-nodejs']);
        $this->assertIsString($body['server-deno']);
        $this->assertIsString($body['server-php']);
        $this->assertIsString($body['server-python']);
        $this->assertIsString($body['server-ruby']);
        $this->assertIsString($body['console-cli']);
    }
}
