<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectNone;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideNone;
use Utopia\Config\Config;

final class HTTPTest extends Scope
{
    use ProjectNone;
    use SideNone;

    public function setUp(): void
    {
        parent::setUp();
        $this->client->setEndpoint('http://appwrite.test');
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

        $corsConfig = Config::getParam('cors');
        $allowedMethods = \implode(', ', $corsConfig['allowedMethods']);
        $allowedHeaders = \implode(', ', $corsConfig['allowedHeaders']);
        $exposedHeaders = \implode(', ', $corsConfig['exposedHeaders']);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals('Appwrite', $response['headers']['server']);
        $this->assertEquals($allowedMethods, $response['headers']['access-control-allow-methods']);
        $this->assertEquals($allowedHeaders, $response['headers']['access-control-allow-headers']);
        $this->assertEquals($exposedHeaders, $response['headers']['access-control-expose-headers']);
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
        $this->assertStringContainsString('# humanstxt.org/', (string) $response['body']);
    }

    public function testRobots()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/robots.txt', \array_merge([
            'origin' => 'http://localhost',
        ]));

        $this->assertEquals(200, $response['headers']['status-code'], "Simple GET /robots.txt HTTP request failed: " . \json_encode($response));
        $this->assertStringContainsString('# robotstxt.org/', (string) $response['body']);
    }

    public function testAcmeChallenge()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/.well-known/acme-challenge/8DdIKX257k6Dih5s_saeVMpTnjPJdKO5Ase0OCiJrIg');

        // 'Unknown path', but validation passed
        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/.well-known/acme-challenge/../../../../../../../etc/passwd');

        // 'Unknown path', but validation passed
        $this->assertEquals(404, $response['headers']['status-code']);
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

        $endpoint = '/v1/projects'; // Can be any non-404 route

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://localhost',
        ]);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);

        /**
         * Test for FAILURE
         */
        // you should not return a fallback origin for a no host
        $response = $this->client->call(Client::METHOD_GET, $endpoint);
        $this->assertNull($response['headers']['access-control-allow-origin'] ?? null);

        // you should not return a fallback origin for a no host
        $response = $this->client->call(Client::METHOD_GET, $endpoint, [
            'origin' => 'http://google.com',
        ]);
        $this->assertNull($response['headers']['access-control-allow-origin'] ?? null);
    }

    public function testPreflight()
    {

        $endpoint = '/v1/projects'; // Can be any non-404 route

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_OPTIONS, $endpoint, [
            'origin' => 'http://random.com',
            'access-control-request-headers' => 'X-Appwrite-Project',
            'access-control-request-method' => 'GET'
        ]);
        $this->assertEquals('http://random.com', $response['headers']['access-control-allow-origin']);
    }

    public function testPreflightDevKeyHeader(): void
    {
        $endpoint = '/v1/account';
        $corsConfig = Config::getParam('cors');
        $allowedHeaders = \implode(', ', $corsConfig['allowedHeaders']);

        $response = $this->client->call(Client::METHOD_OPTIONS, $endpoint, [
            'origin' => 'http://localhost:3000',
            'access-control-request-headers' => 'X-Appwrite-Project, X-Appwrite-Dev-Key, Content-Type',
            'access-control-request-method' => 'POST',
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($allowedHeaders, $response['headers']['access-control-allow-headers']);
        $this->assertStringContainsString('X-Appwrite-Dev-Key', $response['headers']['access-control-allow-headers']);
    }

    /**
     * Guards against the 1.7.4 dev-key regression where the server set
     * `Access-Control-Allow-Origin: *` while also sending
     * `Access-Control-Allow-Credentials: true`. Browsers reject that illegal
     * combination for any credentialed request, breaking all dev-key traffic
     * from the browser. The server must echo a specific origin, never `*`,
     * whenever credentials are allowed.
     */
    public function testCredentialedResponseNeverUsesWildcardOrigin(): void
    {
        $endpoint = '/v1/account';

        foreach ([Client::METHOD_GET, Client::METHOD_OPTIONS] as $method) {
            $headers = ['origin' => 'http://localhost'];
            if ($method === Client::METHOD_OPTIONS) {
                $headers['access-control-request-headers'] = 'X-Appwrite-Project, X-Appwrite-Dev-Key';
                $headers['access-control-request-method'] = 'POST';
            }
            $response = $this->client->call($method, $endpoint, $headers);

            $allowOrigin = $response['headers']['access-control-allow-origin'] ?? null;
            $allowCredentials = $response['headers']['access-control-allow-credentials'] ?? null;

            $this->assertEquals('true', $allowCredentials, "Server must return credentials header for a trusted origin ($method)");
            $this->assertNotEquals('*', $allowOrigin, "Wildcard origin must never be paired with credentials ($method)");
            $this->assertEquals('http://localhost', $allowOrigin, "Server must echo the specific origin ($method)");
        }
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
