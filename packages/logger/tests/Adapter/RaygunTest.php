<?php

declare(strict_types=1);

namespace Utopia\Logger\Tests\Adapter;

use Utopia\Client\Exception\ConnectionException;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class RaygunTest extends AdapterTestCase
{
    public function testPushPostsTheEntryWithTheApiKey(): void
    {
        $this->client->respond(202);

        new Raygun('raygun-key', client: $this->client)->push($this->log());

        $this->assertCount(1, $this->client->requests);
        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertSame('https://api.raygun.com/entries', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('raygun-key', $request->getHeaderLine('X-ApiKey'));
        $this->assertSame([
            'occurredOn' => 1700000000,
            'details' => [
                'machineName' => 'digitalocean-us-001',
                'groupingKey' => 'api',
                'version' => '0.11.5',
                'error' => [
                    'className' => 'controller.database.deleteDocument',
                    'message' => 'Document efgh5678 not found',
                ],
                'tags' => ['sdk: Flutter', 'type: error', 'environment: production', 'sdk: utopia-logger/0.1.0'],
                'userCustomData' => ['file' => '/server/src/server.js', 'line' => '15'],
                'user' => [
                    'isAnonymous' => false,
                    'identifier' => 'efgh5678',
                    'email' => 'user@example.com',
                    'fullName' => 'name',
                ],
                'breadcrumbs' => [[
                    'category' => 'http',
                    'message' => 'DELETE /v1/documents/efgh5678',
                    'type' => 'debug',
                    'level' => 'request',
                    'timestamp' => 1699999999,
                ]],
            ],
        ], $this->json($request));
    }

    public function testPushLogsTheResponseOfARejectedEntry(): void
    {
        $this->captureErrors();
        $this->client->respond(403, 'Invalid API key');

        new Raygun('raygun-key', client: $this->client)->push($this->log());

        $this->assertStringContainsString('Raygun push failed with status code 403: Invalid API key', $this->errors());
    }

    public function testPushReturns500WhenTheRequestFails(): void
    {
        $this->captureErrors();
        $this->client->fail(new ConnectionException(new RequestFactory()->createRequest(Method::POST, 'https://api.raygun.com/entries'), 'Connection refused'));

        $status = new Raygun('raygun-key', client: $this->client)->push($this->log());

        $this->assertSame(500, $status);
        $this->assertStringContainsString('Raygun push failed with fetch error: Connection refused', $this->errors());
    }
}
