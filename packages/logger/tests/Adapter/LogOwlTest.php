<?php

declare(strict_types=1);

namespace Utopia\Logger\Tests\Adapter;

use Utopia\Client\Exception\DnsException;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class LogOwlTest extends AdapterTestCase
{
    public function testPushPostsTheLogToTheTypeEndpoint(): void
    {
        $this->client->respond(200);

        new LogOwl('service-ticket', 'https://logowl.example.com/logging/', client: $this->client)->push($this->log());

        $this->assertCount(1, $this->client->requests);
        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertSame('https://logowl.example.com/logging/error', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'ticket' => 'service-ticket',
            'message' => 'controller.database.deleteDocument',
            'path' => '/server/src/server.js',
            'line' => '15',
            'stacktrace' => '',
            'badges' => [
                'environment' => 'production',
                'namespace' => 'api',
                'version' => '0.11.5',
                'message' => 'Document efgh5678 not found',
                'id' => 'efgh5678',
                '$email' => 'user@example.com',
                '$username' => 'name',
            ],
            'type' => 'error',
            'metrics' => ['platform' => 'digitalocean-us-001'],
            'logs' => [[
                'type' => 'log',
                'log' => 'DELETE /v1/documents/efgh5678',
                'timestamp' => 1699999999,
            ]],
            'timestamp' => 1700000000,
            'adapter' => ['name' => 'logOwl', 'type' => 'utopia-logger', 'version' => '0.1.0'],
        ], $this->json($request));
    }

    public function testPushDefaultsToTheHostedApi(): void
    {
        $this->client->respond(200);

        new LogOwl('service-ticket', client: $this->client)->push($this->log());

        $this->assertSame('https://api.logowl.io/logging/error', (string) $this->client->last()->getUri());
    }

    public function testPushLogsTheResponseOfARejectedLog(): void
    {
        $this->captureErrors();
        $this->client->respond(400, 'invalid ticket');

        new LogOwl('service-ticket', client: $this->client)->push($this->log());

        $this->assertStringContainsString('LogOwl push failed with status code 400: invalid ticket', $this->errors());
    }

    public function testPushReturns500WhenTheHostDoesNotResolve(): void
    {
        $this->captureErrors();
        $this->client->fail(new DnsException(new RequestFactory()->createRequest(Method::POST, 'https://api.invalid.io/logging/error'), 'Could not resolve host: api.invalid.io'));

        $status = new LogOwl('service-ticket', 'https://api.invalid.io/logging/', client: $this->client)->push($this->log());

        $this->assertSame(500, $status);
        $this->assertStringContainsString('LogOwl push failed with fetch error: Could not resolve host: api.invalid.io', $this->errors());
    }
}
