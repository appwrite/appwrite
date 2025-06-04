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

    public function setUp(): void
    {
        parent::setUp();
        $this->client->setEndpoint('http://traefik');
    }

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
        $this->assertEquals('Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Dev-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-Appwrite-Timeout, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-Appwrite-ID, X-Appwrite-Timestamp, Content-Range, Range, Cache-Control, Expires, Pragma, X-Appwrite-Session, X-Fallback-Cookies, X-Forwarded-For, X-Forwarded-User-Agent', $response['headers']['access-control-allow-headers']);
        $this->assertEquals('X-Appwrite-Session, X-Fallback-Cookies', $response['headers']['access-control-expose-headers']);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);
        $this->assertEquals('true', $response['headers']['access-control-allow-credentials']);
        $this->assertEmpty($response['body']);
    }

    public function testHumans()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/humans.txt', \array_merge([
            'origin' => 'http://localhost',
        ]));

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
        ]));

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
        ]));

        // 'Unknown path', but validation passed
        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/.well-known/acme-challenge/../../../../../../../etc/passwd', \array_merge([
            'origin' => 'http://localhost',
        ]));

        // Check for too many path segments
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

            $this->assertEquals(200, $response['headers']['status-code']);
            // looks like recent change in the validator
            $this->assertEmpty($response['body']['schemaValidationMessages'], 'Schema validation failed for ' . $file . ': ' . json_encode($response['body']['schemaValidationMessages'], JSON_PRETTY_PRINT));
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

    public function testDefaultOAuth2()
    {
        $response = $this->client->call(Client::METHOD_GET, '/console/auth/oauth2/success', $this->getHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/console/auth/oauth2/failure', $this->getHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testCors()
    {
        /**
         * Test for SUCCESS
         */

        $endpoint = '/v1/projects'; // Can be any non-404 route

        $response = $this->client->call(Client::METHOD_GET, $endpoint);

        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://localhost',
        ]);

        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://appwrite.io',
        ]);

        $this->assertEquals('http://appwrite.io', $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'https://appwrite.io',
        ]);

        $this->assertEquals('https://appwrite.io', $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://cloud.appwrite.io',
        ]);

        $this->assertEquals('http://cloud.appwrite.io', $response['headers']['access-control-allow-origin']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://google.com',
        ]);

        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);
    }

    public function testConsoleRedirect()
    {
        /**
         * Test for SUCCESS
         */

        $endpoint = '/invite?membershipId=123&userId=asdf';

        $response = $this->client->call(Client::METHOD_GET, $endpoint);

        $this->assertEquals('/console' . $endpoint, $response['headers']['location']);
    }
}
